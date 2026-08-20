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
 * A promise shaped like the one Moodle's Ajax.call actually returns.
 *
 * This is the point of the whole harness, so it is worth being exact about.
 * Ajax.call returns a jQuery Deferred, and jQuery 3.7.1's promise object
 * defines then, catch, always, pipe and state. It does NOT define finally.
 *
 * Handing the code under test a native promise would be more convenient and
 * completely useless: native promises have finally, so a chain ending in
 * finally would work in the test and throw in the browser. That is exactly the
 * bug this harness exists to catch -- it locked the workspace after every run,
 * with the controls dead until the page was reloaded, and no test in the
 * codebase could see it.
 *
 * So: no finally here, deliberately. If production code calls it, these tests
 * fail the same way the browser does.
 *
 * @module     mod_saylorcode/tests/helpers/jquery_deferred
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wrap a native promise in a jQuery-shaped one.
 *
 * @param {Promise} native The promise to delegate to.
 * @returns {Object} A jQuery-like promise.
 */
const wrap = (native) => {
    const promise = {
        then(onFulfilled, onRejected) {
            return wrap(native.then(
                onFulfilled ? (value) => onFulfilled(value) : undefined,
                onRejected ? (reason) => onRejected(reason) : undefined
            ));
        },

        catch(onRejected) {
            return promise.then(null, onRejected);
        },

        always(handler) {
            native.then(handler, handler);
            return promise;
        },

        state() {
            return 'pending';
        },

        // So a test can await the thing without going through the shim.
        __native: native,
    };

    return promise;
};

/**
 * A jQuery-shaped promise that resolves with the given value.
 *
 * @param {*} value The resolution value.
 * @returns {Object} A jQuery-like promise.
 */
export const resolved = (value) => wrap(Promise.resolve(value));

/**
 * A jQuery-shaped promise that rejects with the given reason.
 *
 * @param {*} reason The rejection reason.
 * @returns {Object} A jQuery-like promise.
 */
export const rejected = (reason) => wrap(Promise.reject(reason));

/**
 * A jQuery-shaped promise settled by the caller.
 *
 * @returns {Object} With promise, resolve and reject.
 */
export const deferred = () => {
    let settle;
    let fail;
    const native = new Promise((res, rej) => {
        settle = res;
        fail = rej;
    });

    return {promise: wrap(native), resolve: settle, reject: fail};
};
