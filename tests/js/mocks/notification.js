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
 * Stand-in for core/notification.
 *
 * Records exceptions rather than rendering them, so a test can assert that a
 * failure was surfaced instead of swallowed.
 *
 * @module     mod_saylorcode/tests/mocks/notification
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Array} Everything passed to exception(). */
export const exceptions = [];

/** @type {Array} Every confirm() call, as {title, question, saveLabel}. */
export const confirmations = [];

/**
 * Forget what was recorded.
 *
 * @returns {void}
 */
export const reset = () => {
    exceptions.length = 0;
    confirmations.length = 0;
};

export default {
    /**
     * Record an exception.
     *
     * @param {Error} error The error.
     * @returns {void}
     */
    exception(error) {
        exceptions.push(error);
    },

    /**
     * Record an alert as an exception, which is close enough here.
     *
     * @param {string} title The title.
     * @param {string} message The message.
     * @returns {void}
     */
    alert(title, message) {
        exceptions.push(new Error(`${title}: ${message}`));
    },

    /**
     * Record a confirmation and take the yes branch.
     *
     * Faithful about one thing in particular. Core hands the save label to
     * Modal.asyncSet(), which reads value.hasOwnProperty('then') after guarding
     * only on `typeof value !== 'object'` -- and typeof null is 'object'. So a
     * null label throws a TypeError inside core and the dialogue never opens,
     * which is what a tester saw when Reset did nothing but show
     * "Cannot read properties of null". Reproducing it here means a test fails
     * instead of a person finding it.
     *
     * The fourth argument is deliberately not checked: core's confirm() drops
     * it before calling saveCancel(), so a null there is harmless.
     *
     * @param {string} title The dialogue title.
     * @param {string} question The question.
     * @param {string} saveLabel The confirm button label.
     * @param {string} cancelLabel Ignored by core.
     * @param {Function} onConfirm Called when the user agrees.
     * @returns {void}
     */
    confirm(title, question, saveLabel, cancelLabel, onConfirm) {
        if (saveLabel === null || typeof saveLabel === 'undefined') {
            throw new TypeError("Cannot read properties of null (reading 'hasOwnProperty')");
        }

        confirmations.push({title, question, saveLabel});

        if (typeof onConfirm === 'function') {
            onConfirm();
        }
    },
};
