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
 * Builds the page an HTML or CSS activity previews.
 *
 * Nothing here is sent anywhere. The page is handed to an iframe sandboxed
 * with allow-scripts and without allow-same-origin, so it gets an opaque
 * origin: the student's scripts run, but cannot read Moodle's cookies, its
 * storage or its DOM, and cannot call its web services as the student
 * (specification section 14.2).
 *
 * The one channel out is postMessage, which a small script placed ahead of the
 * student's carries console output and uncaught errors through, so the console
 * below the preview still says why a script failed.
 *
 * @module     mod_saylorcode/preview
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {string} Marks a message as console output from the preview. */
export const MESSAGE_KEY = 'saylorcodePreview';

/**
 * The console relay placed at the top of every previewed page.
 *
 * Written in ES5, because it runs in the page rather than through Moodle's
 * build, and kept to what any browser the workspace supports understands.
 *
 * @param {string} parentOrigin Where messages may be delivered.
 * @returns {string} A script element.
 */
const relay = (parentOrigin) => '<script>(function () {'
    + 'var origin = ' + JSON.stringify(parentOrigin) + ';'
    + 'var text = function (value) {'
    + '  if (typeof value === "string") { return value; }'
    + '  try { return JSON.stringify(value); } catch (e) { return String(value); }'
    + '};'
    + 'var send = function (kind, values) {'
    + '  try {'
    + '    var message = {kind: kind, text: Array.prototype.map.call(values, text).join(" ")};'
    + '    message.' + MESSAGE_KEY + ' = true;'
    + '    window.parent.postMessage(message, origin);'
    + '  } catch (e) { /* The preview must never fail because the relay did. */ }'
    + '};'
    + '["log", "info", "warn", "error"].forEach(function (kind) {'
    + '  var original = console[kind];'
    + '  console[kind] = function () {'
    + '    send(kind, arguments);'
    + '    if (original) { original.apply(console, arguments); }'
    + '  };'
    + '});'
    + 'window.addEventListener("error", function (e) { send("error", [e.message]); });'
    + '})();</script>';

/**
 * Put markup at the top of a document, after any doctype.
 *
 * Ahead of the doctype would drop the page into quirks mode, which changes
 * how CSS lays it out and would make a correct stylesheet look wrong.
 *
 * @param {string} page The document.
 * @param {string} markup What to insert.
 * @returns {string}
 */
const prepend = (page, markup) => {
    const doctype = /^\s*<!doctype[^>]*>/i.exec(page);
    if (!doctype) {
        return markup + page;
    }
    return doctype[0] + markup + page.slice(doctype[0].length);
};

/**
 * Apply a stylesheet to the author's page.
 *
 * The stylesheet goes last in the head, after anything the author's page
 * links, so the student's rules are the ones that win a tie.
 *
 * The page is parsed rather than searched. A regular expression for </head>
 * also matches that text inside a script string or a comment, which put the
 * stylesheet somewhere it never applied. The parser finds the real head, and
 * makes one for a fragment that has none. It does not run the page's scripts.
 *
 * The result is always in standards mode, which is what a stylesheet should be
 * written for.
 *
 * @param {string} stylesheet The student's CSS.
 * @param {string} page The author's HTML.
 * @returns {string}
 */
const styled = (stylesheet, page) => {
    const doc = new DOMParser().parseFromString(page, 'text/html');
    const style = doc.createElement('style');

    // A style element serialises its text raw, so a literal </style> in the
    // CSS would close it early and let the rest be parsed as markup in the
    // frame. Escaping the slash is a valid CSS escape, so the rule still means
    // what the student wrote.
    style.textContent = stylesheet.replace(/<\/(style)/gi, '<\\/$1');
    doc.head.appendChild(style);

    return '<!DOCTYPE html>' + doc.documentElement.outerHTML;
};

/**
 * The document the preview frame renders.
 *
 * @param {string} languageid html or css.
 * @param {string} code The student's file.
 * @param {string} page For css, the author's page the stylesheet styles.
 * @param {string} parentOrigin The workspace's origin, for console messages.
 * @returns {string}
 */
export const buildDocument = (languageid, code, page, parentOrigin) => {
    const markup = languageid === 'css' ? styled(code, page || '') : code;
    return prepend(markup, relay(parentOrigin));
};

/**
 * Whether a message event is console output from a given preview frame.
 *
 * The source is what is checked, not the origin: a sandboxed frame's origin is
 * the string "null", which every other sandboxed frame on the page shares.
 *
 * @param {MessageEvent} event The event.
 * @param {HTMLIFrameElement} frame The preview frame.
 * @returns {boolean}
 */
export const isFromFrame = (event, frame) => !!frame
    && event.source === frame.contentWindow
    && !!event.data
    && event.data[MESSAGE_KEY] === true
    && typeof event.data.text === 'string';
