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
 * Stand-in for the CodeMirror wrapper.
 *
 * The real one pulls in the whole vendored bundle, which is irrelevant to the
 * state machine under test and slow to load. This keeps a value and reports it.
 *
 * @module     mod_saylorcode/tests/mocks/editor
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A minimal editor over a textarea-like value.
 *
 * @param {HTMLElement} element The host element.
 * @param {string} label The accessible label.
 * @returns {Object} The editor handle.
 */
export const create = (element, label) => {
    let value = element && element.dataset ? (element.dataset.starter || '') : '';
    let listener = null;

    // Matches the shape the real module returns: rich, getValue, setValue,
    // onChange, focus. Anything the workspace calls has to be here, or the
    // tests fail for reasons that have nothing to do with the code under test.
    return {
        label,
        rich: true,
        getValue: () => value,
        setValue: (next) => {
            value = next;
            if (listener) {
                listener();
            }
        },
        onChange: (callback) => {
            listener = callback;
        },
        focus: () => {},
    };
};
