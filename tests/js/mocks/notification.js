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

/**
 * Forget what was recorded.
 *
 * @returns {void}
 */
export const reset = () => {
    exceptions.length = 0;
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
};
