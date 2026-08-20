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
 * Stand-in for core/ajax.
 *
 * Returns jQuery-shaped promises rather than native ones, because that is what
 * Moodle returns and the difference is load bearing. See the helper.
 *
 * @module     mod_saylorcode/tests/mocks/ajax
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {resolved} from '../helpers/jquery_deferred';

/** @type {Array} Every request made, for assertions. */
export const calls = [];

/** @type {Function} What to answer with. Replaced per test. */
let responder = () => resolved({});

/**
 * Decide what the next calls return.
 *
 * @param {Function} fn Receives the request, returns a jQuery-like promise.
 * @returns {void}
 */
export const respondWith = (fn) => {
    responder = fn;
};

/**
 * Forget everything recorded and go back to the default response.
 *
 * @returns {void}
 */
export const reset = () => {
    calls.length = 0;
    responder = () => resolved({});
};

export default {
    /**
     * Record the requests and answer them.
     *
     * @param {Array} requests The requests.
     * @returns {Array} One jQuery-like promise per request.
     */
    call(requests) {
        return requests.map((request) => {
            calls.push(request);
            return responder(request);
        });
    },
};
