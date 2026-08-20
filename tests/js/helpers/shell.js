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
 * Enough workspace markup for the module to attach to.
 *
 * Only the regions and controls the module looks for, kept deliberately close
 * to what activity_shell.mustache renders. If a selector changes there and not
 * here, these tests stop exercising the real thing, so the two are worth
 * keeping in step.
 *
 * @module     mod_saylorcode/tests/helpers/shell
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Put a workspace shell in the document and return its root.
 *
 * @param {Object} options cmid, layout, preview, starter.
 * @returns {HTMLElement} The shell element.
 */
export const mount = (options = {}) => {
    const cmid = options.cmid ?? 42;
    const preview = options.preview ? '1' : '0';
    const starter = options.starter ?? 'public class Main {}';

    document.body.innerHTML = `
        <div data-region="saylorcode-shell"
             data-cmid="${cmid}"
             data-layout="${options.layout ?? 'split'}"
             data-entryfilename="Main.java"
             data-preview="${preview}">
            <div data-region="editor" data-starter="${starter}"></div>
            <textarea data-region="stdin"></textarea>
            <span data-region="status"></span>
            <span data-region="save"></span>
            <span data-region="ran"></span>
            <div data-region="console"></div>
            <div data-region="tests"></div>
            <div data-region="verdict"></div>
            <div data-region="verdict-surface"></div>
            <span data-region="attempts"></span>
            <span data-region="attempts-inline"></span>
            <div data-region="saylorcode-hints">
                <ol data-region="saylorcode-hintlist"></ol>
                <div data-region="saylorcode-hintstatus"></div>
            </div>
            <button type="button" data-action="run">Run</button>
            <button type="button" data-action="check">Check</button>
            <button type="button" data-action="submit">Submit</button>
            <button type="button" data-action="reset">Reset</button>
            <button type="button" data-action="theme">Theme</button>
        </div>
    `;

    return document.querySelector('[data-region="saylorcode-shell"]');
};

/**
 * Let queued promise callbacks run.
 *
 * @returns {Promise} Resolves after the microtask queue drains.
 */
export const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
