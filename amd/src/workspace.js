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
 * Owns the editor shell: dirty tracking, debounced autosave, confirmation
 * before consequential actions, and announcing state changes to assistive
 * technology. Execution is a server concern; this module asks for it and
 * renders what comes back.
 *
 * @module     mod_saylorcode/workspace
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

/**
 * How long the editor must be idle before an autosave fires.
 *
 * The specification asks for two seconds. Saving on every keystroke would
 * generate far more requests than the runner or database need to see.
 *
 * @type {number}
 */
const AUTOSAVE_IDLE_MS = 2000;

/** @type {Object} Selectors used by this module. */
const SELECTORS = {
    SHELL: '[data-region="saylorcode-shell"]',
    EDITOR: '[data-region="editor"]',
    STDIN: '[data-region="stdin"]',
    STATUS: '[data-region="status"]',
    CONSOLE: '[data-region="console"]',
    TESTS: '[data-region="tests"]',
    ACTION: '[data-action]',
};

/** @type {Object} Save states the status line can report. */
const SAVE_STATE = {
    CLEAN: 'clean',
    DIRTY: 'dirty',
    SAVING: 'saving',
    SAVED: 'saved',
    FAILED: 'failed',
};

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
        this.editor = root.querySelector(SELECTORS.EDITOR);
        this.stdin = root.querySelector(SELECTORS.STDIN);
        this.status = root.querySelector(SELECTORS.STATUS);
        this.console = root.querySelector(SELECTORS.CONSOLE);
        this.tests = root.querySelector(SELECTORS.TESTS);
        this.cmid = parseInt(root.dataset.cmid, 10);
        this.entryFilename = root.dataset.entryfilename || 'Main.java';

        // Identifies this tab, so a save from another tab can be told apart
        // from this one catching up with itself.
        this.browserSession = `s${Math.random().toString(36).slice(2, 12)}`;
        this.knownSnapshotId = 0;

        this.saveTimer = null;
        this.state = SAVE_STATE.CLEAN;
        this.lastSavedValue = this.editor ? this.editor.value : '';
        this.busy = false;

        this.registerListeners();
    }

    /**
     * Attach event handlers.
     */
    registerListeners() {
        if (this.editor) {
            this.editor.addEventListener('input', () => this.handleInput());
        }

        this.root.addEventListener('click', (e) => {
            const trigger = e.target.closest(SELECTORS.ACTION);
            if (trigger && this.root.contains(trigger)) {
                e.preventDefault();
                this.handleAction(trigger.dataset.action);
            }
        });

        window.addEventListener('beforeunload', (e) => {
            if (this.state === SAVE_STATE.DIRTY || this.state === SAVE_STATE.SAVING) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    /**
     * Restart the autosave countdown after a keystroke.
     */
    handleInput() {
        this.setState(SAVE_STATE.DIRTY);

        if (this.saveTimer) {
            window.clearTimeout(this.saveTimer);
        }
        this.saveTimer = window.setTimeout(() => {
            this.save().catch(Notification.exception);
        }, AUTOSAVE_IDLE_MS);
    }

    /**
     * Dispatch a toolbar action.
     *
     * @param {string} action One of run, check, submit, reset or download.
     */
    handleAction(action) {
        if (this.busy) {
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

            default:
                break;
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
        return {[this.entryFilename]: this.editor ? this.editor.value : ''};
    }

    /**
     * Persist the current code.
     *
     * @returns {Promise} Resolves when the save is acknowledged.
     */
    save() {
        if (!this.editor || this.editor.value === this.lastSavedValue) {
            return Promise.resolve(true);
        }

        this.setState(SAVE_STATE.SAVING);
        const pending = this.editor.value;

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
                this.setState(SAVE_STATE.FAILED);
                this.showMessage(response.message);
                return false;
            }

            this.lastSavedValue = pending;
            this.knownSnapshotId = response.snapshotid;
            this.setState(SAVE_STATE.SAVED);
            return true;
        }).catch((error) => {
            this.setState(SAVE_STATE.FAILED);
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
        this.busy = true;
        this.announce('running');

        return Ajax.call([{
            methodname: 'mod_saylorcode_run_code',
            args: {
                cmid: this.cmid,
                mode: mode,
                files: JSON.stringify(this.getFiles()),
                stdin: this.stdin ? this.stdin.value : '',
                browsersession: this.browserSession,
            },
        }])[0].then((result) => {
            this.lastSavedValue = this.editor ? this.editor.value : '';
            this.setState(SAVE_STATE.SAVED);
            this.renderResult(result);
            return result;
        }).catch((error) => {
            this.showMessage(error.message || String(error));
            throw error;
        }).finally(() => {
            this.busy = false;
        });
    }

    /**
     * Restore the starter code.
     *
     * @returns {Promise} Resolves when the editor has been restored.
     */
    reset() {
        this.busy = true;

        return Ajax.call([{
            methodname: 'mod_saylorcode_reset_code',
            args: {
                cmid: this.cmid,
                browsersession: this.browserSession,
            },
        }])[0].then((response) => {
            const files = JSON.parse(response.files);
            const restored = files[this.entryFilename] ?? '';

            if (this.editor) {
                this.editor.value = restored;
                this.lastSavedValue = restored;
            }
            this.setState(SAVE_STATE.SAVED);
            this.showMessage(response.message);
            return response;
        }).finally(() => {
            this.busy = false;
        });
    }

    /**
     * Offer the current code as a file.
     */
    download() {
        if (!this.editor) {
            return;
        }

        const blob = new Blob([this.editor.value], {type: 'text/plain'});
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
     * Render an execution result.
     *
     * @param {Object} result The web service response.
     */
    renderResult(result) {
        if (this.console) {
            const parts = [];
            if (result.compileroutput) {
                parts.push(result.compileroutput);
            }
            if (result.stdout) {
                parts.push(result.stdout);
            }
            if (result.stderr) {
                parts.push(result.stderr);
            }
            // textContent, never innerHTML: program output is untrusted and
            // must never be parsed as markup.
            this.console.textContent = parts.join('\n');
        }

        this.renderTests(result.tests || []);
        this.showMessage(result.message || '');
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
            // Not colour alone: the outcome is stated in text as well.
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
     * Record a new save state and announce it.
     *
     * @param {string} state One of the SAVE_STATE values.
     */
    setState(state) {
        this.state = state;

        if (state === SAVE_STATE.SAVING) {
            this.announce('saving');
        } else if (state === SAVE_STATE.SAVED) {
            this.announce('saved');
        }
    }

    /**
     * Put a translated message in the live region.
     *
     * @param {string} key Language string key.
     */
    announce(key) {
        if (!this.status) {
            return;
        }

        getString(key, 'mod_saylorcode').then((text) => {
            this.status.textContent = text;
            return text;
        }).catch(Notification.exception);
    }

    /**
     * Put a ready made message in the live region.
     *
     * @param {string} message The message text.
     */
    showMessage(message) {
        if (this.status && message) {
            this.status.textContent = message;
        }
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
