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
 * Stand-in for core/str.
 *
 * Answers with the key, which is enough to assert that a state was reached
 * without pinning the tests to English wording.
 *
 * @module     mod_saylorcode/tests/mocks/str
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Resolve a string to its own key.
 *
 * @param {string} key The string identifier.
 * @returns {Promise} Resolves to the key.
 */
export const get_string = (key) => Promise.resolve(key); // eslint-disable-line camelcase

/**
 * Resolve several strings to their keys.
 *
 * @param {Array} requests The requests.
 * @returns {Promise} Resolves to the keys.
 */
export const get_strings = (requests) => // eslint-disable-line camelcase
    Promise.resolve(requests.map((request) => request.key));
