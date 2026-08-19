// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Client side state for the Saylor Code Studio workspace.
 *
 * Drives all three layouts from one set of markup. The layout decides where
 * results appear; this module decides what they say, so adding a layout is a
 * template and stylesheet change rather than a change here.
 *
 * @module     mod_saylorcode/workspace
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';
import * as Editor from 'mod_saylorcode/editor';

/**
 * How long the editor must be idle before an autosave fires.
 *
 * The specification asks for two seconds. Saving on every keystroke would
 * generate far more requests than the runner or database need to see.
 *
 * @type {number}
 */
const AUTOSAVE_IDLE_MS = 2000;

/** @type {string} One level of indentation, for the plain fallback editor. */
const INDENT = '    ';

/** @type {string} Where the chosen editor theme is remembered. */
const THEME_KEY = 'mod_saylorcode/editortheme';

/** @type {Object} Selectors used by this module. */
const SELECTORS = {
    SHELL: '[data-region="saylorcode-shell"]',
    EDITOR: '[data-region="editor"]',
    STDIN: '[data-region="stdin"]',
    STATUS: '[data-region="status"]',
    SAVE: '[data-region="save"]',
    RAN: '[data-region="ran"]',
    CONSOLE: '[data-region="console"]',
    TESTS: '[data-region="tests"]',
    VERDICT: '[data-region="verdict"]',
    VERDICT_SURFACE: '[data-region="verdict-surface"]',
    ATTEMPTS: '[data-region="attempts"]',
    ATTEMPTS_INLINE: '[data-region="attempts-inline"]',
    ACTION: '[data-action]',
    TAB: '[data-tab]',
    PANEL: '[data-panel]',
};

/** @type {string[]} States that mean the program or the platform failed. */
const FAILED_STATES = [
    'compile_error', 'runtime_error', 'timeout', 'memory_limit',
    'output_limit', 'process_limit', 'runner_unavailable', 'internal_error',
];

/**
 * Controls one workspace instance.
 */
class Workspace {

    /**
     * Wire up a workspace.
     *
     * @param {HTMLElement} root The shell element.
     */
    constructor(root) {
        this.root = root;
        this.cmid = parseInt(root.dataset.cmid, 10);
        this.layout = root.dataset.layout || 'split';
        this.entryFilename = root.dataset.entryfilename || 'Main.java';
        // An author preview renders the real workspace but talks to nothing:
        // staff hold no attempt, so a save or a run would only fail.
        this.preview = root.dataset.preview === '1';

        this.editor = root.querySelector(SELECTORS.EDITOR);
        this.stdin = root.querySelector(SELECTORS.STDIN);
        this.status = root.querySelector(SELECTORS.STATUS);
        this.saveEl = root.querySelector(SELECTORS.SAVE);
        this.console = root.querySelector(SELECTORS.CONSOLE);
        this.tests = root.querySelector(SELECTORS.TESTS);
        this.verdict = root.querySelector(SELECTORS.VERDICT);

        // Identifies this tab, so a save from another tab can be told apart
        // from this one catching up with itself.
        this.browserSession = `s${Math.random().toString(36).slice(2, 12)}`;
        this.knownSnapshotId = 0;

        this.saveTimer = null;
        this.busy = false;
        this.dirty = false;
        this.escapeHatch = false;
        this.attempts = 0;
        this.best = null;

        this.code = Editor.create(this.editor, this.editorLabel());
        this.lastSavedValue = this.code ? this.code.getValue() : '';

        this.applyStoredTheme();
        this.registerListeners();
    }

    /**
     * The accessible name for the editing surface.
     *
     * @returns {string} The label text.
     */
    editorLabel() {
        const id = this.editor ? this.editor.id : '';
        const label = id ? this.root.querySelector(`label[for="${id}"]`) : null;
        return label ? label.textContent.trim() : this.entryFilename;
    }

    /**
     * Attach event handlers.
     */
    registerListeners() {
        if (this.code) {
            this.code.onChange(() => this.handleInput());
        }

        // Only the plain fallback needs hand rolled indenting. CodeMirror
        // brings its own keymap and leaves Tab moving focus, which is the
        // accessible default.
        if (this.editor && this.code && !this.code.rich) {
            this.editor.addEventListener('keydown', (e) => this.handleKeydown(e));
        }

        this.root.addEventListener('click', (e) => {
            const action = e.target.closest(SELECTORS.ACTION);
            if (action && this.root.contains(action)) {
                e.preventDefault();
                this.handleAction(action.dataset.action);
                return;
            }

            const tab = e.target.closest(SELECTORS.TAB);
            if (tab && this.root.contains(tab)) {
                e.preventDefault();
                this.selectTab(tab.dataset.tab);
            }
        });

        // Ctrl+Enter runs from anywhere in the workspace, which is what the
        // action row advertises.
        this.root.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                this.handleAction('run');
            }
        });

        this.root.querySelectorAll(SELECTORS.TAB).forEach((tab) => {
            tab.addEventListener('keydown', (e) => this.handleTabKeys(e));
        });

        // A guided lesson changes steps without reloading, so it asks the
        // workspace to show different code rather than touching the editor.
        document.addEventListener('saylorcode:setcode', (e) => {
            if (this.code && typeof e.detail.code === 'string') {
                this.code.setValue(e.detail.code);
                this.lastSavedValue = e.detail.code;
                this.dirty = false;
                this.setSaveState('saved');
            }
        });

        window.addEventListener('beforeunload', (e) => {
            if (this.dirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    /**
     * The step the student is working on, or 0 outside a guided lesson.
     *
     * Read from the guided panel rather than held here, so the panel stays the
     * single owner of which step is open and the two cannot disagree.
     *
     * @returns {number} The step id.
     */
    currentStepId() {
        const panel = document.querySelector('[data-region="saylorcode-guided"]');

        return panel ? parseInt(panel.dataset.currentstepid, 10) || 0 : 0;
    }

    /**
     * Restart the autosave countdown after a change.
     */
    handleInput() {
        if (this.preview) {
            return;
        }

        this.dirty = true;
        this.setSaveState('saving');

        if (this.saveTimer) {
            window.clearTimeout(this.saveTimer);
        }
        this.saveTimer = window.setTimeout(() => {
            this.save().catch(Notification.exception);
        }, AUTOSAVE_IDLE_MS);
    }

    /**
     * Indent handling for the plain fallback editor.
     *
     * Escape then Tab moves focus, so a keyboard user is never trapped.
     *
     * @param {KeyboardEvent} e The key event.
     */
    handleKeydown(e) {
        if (e.key === 'Escape') {
            this.escapeHatch = true;
            return;
        }

        if (e.key !== 'Tab' || this.escapeHatch) {
            this.escapeHatch = false;
            return;
        }

        e.preventDefault();

        if (this.editor.selectionStart === this.editor.selectionEnd) {
            this.insertIndent();
        } else {
            this.shiftSelectedLines(e.shiftKey);
        }

        this.handleInput();
    }

    /**
     * Insert one indent at the cursor.
     */
    insertIndent() {
        const el = this.editor;
        const at = el.selectionStart;

        el.value = el.value.slice(0, at) + INDENT + el.value.slice(at);
        el.selectionStart = el.selectionEnd = at + INDENT.length;
    }

    /**
     * Indent or outdent every line the selection touches.
     *
     * Replacing a selection with one indent would delete the selected code,
     * which is the opposite of what pressing Tab on a block should do.
     *
     * @param {boolean} outdent True to remove one level rather than add one.
     */
    shiftSelectedLines(outdent) {
        const el = this.editor;
        const value = el.value;
        const start = el.selectionStart;
        const end = el.selectionEnd;

        const from = value.lastIndexOf('\n', start - 1) + 1;
        let to = value.indexOf('\n', end);
        if (to === -1) {
            to = value.length;
        }

        const lines = value.slice(from, to).split('\n');
        let firstDelta = 0;
        let totalDelta = 0;

        const shifted = lines.map((line, index) => {
            let result;
            if (outdent) {
                const removed = line.startsWith(INDENT)
                    ? INDENT.length
                    : (line.match(/^ {1,3}/) || [''])[0].length;
                result = line.slice(removed);
                totalDelta -= removed;
                if (index === 0) {
                    firstDelta = -removed;
                }
            } else {
                result = INDENT + line;
                totalDelta += INDENT.length;
                if (index === 0) {
                    firstDelta = INDENT.length;
                }
            }
            return result;
        });

        el.value = value.slice(0, from) + shifted.join('\n') + value.slice(to);
        el.selectionStart = Math.max(from, start + firstDelta);
        el.selectionEnd = Math.max(el.selectionStart, end + totalDelta);
    }

    /**
     * Move between tabs with the arrow keys, as a tablist should.
     *
     * @param {KeyboardEvent} e The key event.
     */
    handleTabKeys(e) {
        const keys = {ArrowLeft: -1, ArrowRight: 1};
        if (!(e.key in keys)) {
            return;
        }

        e.preventDefault();

        const tabs = Array.from(this.root.querySelectorAll(SELECTORS.TAB));
        const index = tabs.indexOf(e.target);
        const next = tabs[(index + keys[e.key] + tabs.length) % tabs.length];

        this.selectTab(next.dataset.tab);
        next.focus();
    }

    /**
     * Show one tab panel.
     *
     * @param {string} name output, input or feedback.
     */
    selectTab(name) {
        this.root.querySelectorAll(SELECTORS.TAB).forEach((tab) => {
            const selected = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });

        this.root.querySelectorAll(SELECTORS.PANEL).forEach((panel) => {
            panel.hidden = panel.dataset.panel !== name;
        });
    }

    /**
     * Dispatch a control.
     *
     * @param {string} action The action name.
     */
    handleAction(action) {
        if (this.busy && action !== 'theme' && action !== 'closedrawer') {
            return;
        }

        // Theme and the drawer are pure presentation, so they stay live in a
        // preview. Everything else would reach a web service the viewer has no
        // business calling.
        if (this.preview && action !== 'theme' && action !== 'closedrawer') {
            return;
        }

        switch (action) {
            case 'run':
            case 'check':
                this.execute(action).catch(Notification.exception);
                break;

            case 'submit':
                this.confirmThen('submitconfirm', () => {
                    this.execute('submit').catch(Notification.exception);
                });
                break;

            case 'reset':
                this.confirmThen('resetconfirm', () => {
                    this.reset().catch(Notification.exception);
                });
                break;

            case 'download':
                this.download();
                break;

            case 'theme':
                this.toggleTheme();
                break;

            case 'closedrawer':
                this.root.classList.remove('saylorcode-open');
                break;

            default:
                break;
        }
    }

    /**
     * Switch the editor between its dark and light surfaces.
     *
     * The choice is remembered across activities, because it is a preference
     * about reading code rather than about one exercise.
     */
    toggleTheme() {
        const light = this.root.classList.toggle('saylorcode-light');
        const button = this.root.querySelector('[data-action="theme"]');

        if (button) {
            button.setAttribute('aria-pressed', light ? 'true' : 'false');
        }

        try {
            window.localStorage.setItem(THEME_KEY, light ? 'light' : 'dark');
        } catch (e) {
            // A browser refusing storage is not a reason to fail the toggle.
            this.escapeHatch = false;
        }
    }

    /**
     * Apply a previously chosen editor theme.
     */
    applyStoredTheme() {
        let stored = null;
        try {
            stored = window.localStorage.getItem(THEME_KEY);
        } catch (e) {
            stored = null;
        }

        if (stored !== 'light') {
            return;
        }

        this.root.classList.add('saylorcode-light');
        const button = this.root.querySelector('[data-action="theme"]');
        if (button) {
            button.setAttribute('aria-pressed', 'true');
        }
    }

    /**
     * Ask for confirmation before a consequential action.
     *
     * @param {string} messagekey Language string key for the question.
     * @param {Function} onConfirm Called when the student agrees.
     */
    confirmThen(messagekey, onConfirm) {
        Promise.all([
            getString('pluginname', 'mod_saylorcode'),
            getString(messagekey, 'mod_saylorcode'),
        ]).then(([title, question]) => {
            return Notification.confirm(title, question, null, null, onConfirm);
        }).catch(Notification.exception);
    }

    /**
     * The current file map.
     *
     * @returns {Object} Relative path to contents.
     */
    getFiles() {
        return {[this.entryFilename]: this.code ? this.code.getValue() : ''};
    }

    /**
     * Persist the current code.
     *
     * @returns {Promise} Resolves when the save is acknowledged.
     */
    save() {
        if (!this.code || this.code.getValue() === this.lastSavedValue) {
            this.dirty = false;
            return Promise.resolve(true);
        }

        const pending = this.code.getValue();

        return Ajax.call([{
            methodname: 'mod_saylorcode_save_code',
            args: {
                cmid: this.cmid,
                files: JSON.stringify(this.getFiles()),
                browsersession: this.browserSession,
                knownsnapshotid: this.knownSnapshotId,
            },
        }])[0].then((response) => {
            if (response.conflict) {
                // Neither tab may silently discard the other's work.
                this.setSaveState('conflict', response.message);
                return false;
            }

            this.lastSavedValue = pending;
            this.knownSnapshotId = response.snapshotid;
            this.dirty = false;
            this.setSaveState('saved');
            return true;
        }).catch((error) => {
            this.setSaveState('failed');
            throw error;
        });
    }

    /**
     * Run, check or submit.
     *
     * @param {string} mode One of run, check or submit.
     * @returns {Promise} Resolves when the result has been rendered.
     */
    execute(mode) {
        this.setBusy(true);
        this.setStatus('running');
        this.clearResults();
        this.openResults();

        return Ajax.call([{
            methodname: 'mod_saylorcode_run_code',
            args: {
                cmid: this.cmid,
                mode: mode,
                files: JSON.stringify(this.getFiles()),
                stdin: this.stdin ? this.stdin.value : '',
                browsersession: this.browserSession,
                stepid: this.currentStepId(),
            },
        }])[0].then((result) => {
            this.lastSavedValue = this.code ? this.code.getValue() : '';
            this.dirty = false;
            this.setSaveState('saved');
            this.renderResult(result, mode);

            // A guided lesson listens for this to move its panel on. The
            // workspace deliberately knows nothing about how that is drawn.
            if (result.step) {
                document.dispatchEvent(new CustomEvent('saylorcode:stepprogress', {
                    detail: result.step,
                }));
            }
            return result;
        }).catch((error) => {
            this.setStatus('error');
            this.writeConsole([{text: error.message || String(error), kind: 'err'}]);
            throw error;
        }).finally(() => {
            this.setBusy(false);
        });
    }

    /**
     * Restore the starter code.
     *
     * @returns {Promise} Resolves when the editor has been restored.
     */
    reset() {
        this.setBusy(true);

        return Ajax.call([{
            methodname: 'mod_saylorcode_reset_code',
            args: {
                cmid: this.cmid,
                browsersession: this.browserSession,
            },
        }])[0].then((response) => {
            const files = JSON.parse(response.files);
            const restored = files[this.entryFilename] ?? '';

            if (this.code) {
                this.code.setValue(restored);
                this.lastSavedValue = restored;
            }

            this.dirty = false;
            this.clearResults();
            this.setStatus('idle');
            this.setSaveState('saved');
            this.root.classList.remove('saylorcode-open');
            return response;
        }).finally(() => {
            this.setBusy(false);
        });
    }

    /**
     * Offer the current code as a file.
     */
    download() {
        if (!this.code) {
            return;
        }

        const blob = new Blob([this.code.getValue()], {type: 'text/plain'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = this.entryFilename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Render one execution result.
     *
     * @param {Object} result The web service response.
     * @param {string} mode The action that produced it.
     */
    renderResult(result, mode) {
        const lines = [];

        if (result.compileroutput) {
            result.compileroutput.split('\n').forEach((text) => lines.push({text, kind: 'err'}));
        }
        if (result.stdout) {
            result.stdout.replace(/\n$/, '').split('\n').forEach((text) => lines.push({text}));
        }
        if (result.stderr) {
            result.stderr.split('\n').forEach((text) => lines.push({text, kind: 'err'}));
        }

        this.writeConsole(lines);
        this.renderTests(result.tests || []);
        this.stampRan();

        const tests = result.tests || [];
        if (FAILED_STATES.indexOf(result.state) !== -1) {
            this.setStatus('error');
        } else if (mode === 'run' || !tests.length) {
            this.setStatus('ran');
        } else {
            this.setStatus(tests.every((t) => t.passed) ? 'passed' : 'failed');
        }

        if (mode === 'submit') {
            this.recordAttempt(tests);
        }

        this.showVerdict(result, mode, tests);
    }

    /**
     * Put lines into the console.
     *
     * @param {Array} lines Objects with text and an optional kind.
     */
    writeConsole(lines) {
        if (!this.console) {
            return;
        }

        this.console.textContent = '';

        lines.forEach((line) => {
            const el = document.createElement('div');
            el.className = 'saylorcode-console-line';
            if (line.kind === 'err') {
                el.classList.add('saylorcode-line-err');
            } else if (line.kind === 'ok') {
                el.classList.add('saylorcode-line-ok');
            }
            // Assigned as text, never as HTML. Program output is untrusted and
            // must never be parsed as markup.
            el.textContent = line.text;
            this.console.appendChild(el);
        });
    }

    /**
     * Render the test outcomes.
     *
     * @param {Array} tests The test results.
     */
    renderTests(tests) {
        if (!this.tests) {
            return;
        }

        this.tests.textContent = '';

        if (!tests.length) {
            return;
        }

        const list = document.createElement('ul');
        list.className = 'saylorcode-testlist';

        tests.forEach((test) => {
            const item = document.createElement('li');
            item.className = test.passed ? 'saylorcode-test-pass' : 'saylorcode-test-fail';

            const name = document.createElement('span');
            name.className = 'saylorcode-test-name';
            // Not colour alone: the outcome is stated as a symbol and as text.
            name.textContent = `${test.passed ? '✓' : '✗'} ${test.name}`;
            item.appendChild(name);

            if (!test.passed && test.feedback) {
                const feedback = document.createElement('p');
                feedback.className = 'saylorcode-test-feedback';
                feedback.textContent = test.feedback;
                item.appendChild(feedback);
            }

            list.appendChild(item);
        });

        this.tests.appendChild(list);
    }

    /**
     * Show the summary line under the results.
     *
     * @param {Object} result The web service response.
     * @param {string} mode The action that produced it.
     * @param {Array} tests The test results.
     */
    showVerdict(result, mode, tests) {
        if (!this.verdict) {
            return;
        }

        const passed = tests.filter((t) => t.passed).length;
        const good = tests.length > 0 && passed === tests.length;

        const finish = (text) => {
            this.verdict.textContent = text;
            this.verdict.hidden = !text;
            this.verdict.classList.toggle('is-good', good && tests.length > 0);
            this.verdict.classList.toggle('is-bad', !good && tests.length > 0);

            const surface = this.root.querySelector(SELECTORS.VERDICT_SURFACE);
            if (surface && text) {
                surface.textContent = text;
                surface.classList.toggle('is-good', good);
                surface.classList.toggle('is-bad', !good);
            }
        };

        if (!tests.length) {
            finish(result.message || '');
            return;
        }

        const key = mode === 'submit' ? 'verdictsubmitted' : 'verdictchecked';
        const args = mode === 'submit'
            ? {attempt: this.attempts, passed: passed, total: tests.length}
            : {passed: passed, total: tests.length};

        getString(key, 'mod_saylorcode', args).then((text) => {
            finish(`${good ? '✓' : '✗'} ${text}`);
            return text;
        }).catch(Notification.exception);
    }

    /**
     * Record a submission in the attempt counter.
     *
     * @param {Array} tests The test results.
     */
    recordAttempt(tests) {
        const passed = tests.filter((t) => t.passed).length;

        this.attempts++;
        this.best = this.best === null ? passed : Math.max(this.best, passed);

        getString('attemptsummary', 'mod_saylorcode', {
            attempt: this.attempts,
            best: this.best,
            total: tests.length,
        }).then((label) => {
            this.root.querySelectorAll(`${SELECTORS.ATTEMPTS}, ${SELECTORS.ATTEMPTS_INLINE}`)
                .forEach((el) => {
                    el.textContent = label;
                });
            return label;
        }).catch(Notification.exception);
    }

    /**
     * Clear the previous run's output.
     */
    clearResults() {
        if (this.console) {
            this.console.textContent = '';
        }
        if (this.tests) {
            this.tests.textContent = '';
        }
        if (this.verdict) {
            this.verdict.hidden = true;
        }
    }

    /**
     * Bring the results into view, in whichever layout is in use.
     */
    openResults() {
        if (this.layout === 'drawer') {
            this.root.classList.add('saylorcode-open');
        }
        if (this.layout === 'tabs') {
            this.selectTab('output');
        }
    }

    /**
     * Note the time of the last run.
     */
    stampRan() {
        const el = this.root.querySelector(SELECTORS.RAN);
        if (!el) {
            return;
        }

        const now = new Date();
        const pad = (n) => n.toString().padStart(2, '0');

        getString('ranat', 'mod_saylorcode', `${pad(now.getHours())}:${pad(now.getMinutes())}`)
            .then((text) => {
                el.textContent = text;
                return text;
            }).catch(Notification.exception);
    }

    /**
     * Record that work is in flight, and show it.
     *
     * @param {boolean} busy Whether work is in flight.
     */
    setBusy(busy) {
        this.busy = busy;
        this.root.classList.toggle('saylorcode-busy', busy);
    }

    /**
     * Update the status pill.
     *
     * @param {string} state idle, running, ran, error, passed or failed.
     */
    setStatus(state) {
        if (!this.status) {
            return;
        }

        const keys = {
            idle: 'statusready',
            running: 'statusrunning',
            ran: 'statusran',
            error: 'statuserror',
            passed: 'statuspassed',
            failed: 'statusfailed',
        };

        this.status.classList.remove('is-running', 'is-good', 'is-bad');
        if (state === 'running') {
            this.status.classList.add('is-running');
        } else if (state === 'ran' || state === 'passed') {
            this.status.classList.add('is-good');
        } else if (state === 'error' || state === 'failed') {
            this.status.classList.add('is-bad');
        }

        getString(keys[state] || keys.idle, 'mod_saylorcode').then((text) => {
            this.status.textContent = text;
            return text;
        }).catch(Notification.exception);
    }

    /**
     * Update the save indicator.
     *
     * @param {string} state saving, saved, failed or conflict.
     * @param {string} message Optional ready made message.
     */
    setSaveState(state, message) {
        if (!this.saveEl) {
            return;
        }

        if (message) {
            this.saveEl.textContent = message;
            return;
        }

        const keys = {
            saving: 'saving',
            saved: 'saved',
            failed: 'savefailed',
            conflict: 'saveconflict',
        };

        getString(keys[state] || 'saved', 'mod_saylorcode').then((text) => {
            this.saveEl.textContent = text;
            return text;
        }).catch(Notification.exception);
    }
}

/**
 * Initialise the workspace for one course module.
 *
 * @param {number} cmid The course module id.
 */
export const init = (cmid) => {
    document.querySelectorAll(SELECTORS.SHELL).forEach((root) => {
        if (parseInt(root.dataset.cmid, 10) === cmid) {
            new Workspace(root);
        }
    });
};
