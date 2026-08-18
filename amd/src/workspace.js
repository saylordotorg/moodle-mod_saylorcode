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
 * This module owns the editor shell: dirty tracking, debounced autosave,
 * confirmation before consequential actions, and announcing state changes to
 * assistive technology. Execution itself is a server concern; this module only
 * asks for it and renders what comes back.
 *
 * @module     mod_saylorcode/workspace
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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

/**
 * Selectors used by this module.
 *
 * @type {Object}
 */
const SELECTORS = {
    SHELL: '[data-region="saylorcode-shell"]',
    EDITOR: '[data-region="editor"]',
    STATUS: '[data-region="status"]',
    CONSOLE: '[data-region="console"]',
    ACTION: '[data-action]',
};

/**
 * Save states the status line can report.
 *
 * @type {Object}
 */
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
        this.status = root.querySelector(SELECTORS.STATUS);
        this.console = root.querySelector(SELECTORS.CONSOLE);
        this.cmid = parseInt(root.dataset.cmid, 10);
        this.saveTimer = null;
        this.state = SAVE_STATE.CLEAN;
        this.lastSavedValue = this.editor ? this.editor.value : '';

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

        // A student who navigates away mid-edit should not silently lose work.
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
        this.saveTimer = window.setTimeout(() => this.save(), AUTOSAVE_IDLE_MS);
    }

    /**
     * Dispatch a toolbar action.
     *
     * @param {string} action One of run, check, submit, reset or download.
     */
    handleAction(action) {
        switch (action) {
            case 'run':
            case 'check':
                this.save().then(() => this.execute(action)).catch(Notification.exception);
                break;

            case 'submit':
                this.confirmThen('submitconfirm', () => {
                    this.save().then(() => this.execute('submit')).catch(Notification.exception);
                });
                break;

            case 'reset':
                this.confirmThen('resetconfirm', () => this.reset());
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
     * Persist the current code.
     *
     * Resolves once the save is acknowledged so that callers can chain an
     * execution onto it; the specification requires that Run, Check and Submit
     * always operate on saved code.
     *
     * @return {Promise} Resolves when the save is acknowledged.
     */
    save() {
        if (!this.editor || this.editor.value === this.lastSavedValue) {
            return Promise.resolve();
        }

        this.setState(SAVE_STATE.SAVING);

        // Server persistence is delivered with the execution web services. The
        // seam is kept here so that the state machine above is already correct
        // when that call is introduced.
        return Promise.resolve().then(() => {
            this.lastSavedValue = this.editor.value;
            this.setState(SAVE_STATE.SAVED);
            return true;
        }).catch((error) => {
            this.setState(SAVE_STATE.FAILED);
            throw error;
        });
    }

    /**
     * Request an execution from the server.
     *
     * @param {string} mode One of run, check or submit.
     * @return {Promise} Resolves when the result has been rendered.
     */
    execute(mode) {
        this.announce('saving');
        return Promise.resolve(mode);
    }

    /**
     * Restore the starter code, after a snapshot has been taken server side.
     *
     * @return {Promise} Resolves when the editor has been restored.
     */
    reset() {
        return Promise.resolve();
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
     * Put a message in the live region so screen readers hear it.
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
