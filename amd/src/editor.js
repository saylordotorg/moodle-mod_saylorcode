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
 * The code editor behind the workspace.
 *
 * Wraps CodeMirror where it is available and falls back to the plain textarea
 * where it is not, behind one small interface. The rest of the workspace only
 * ever asks for the value, sets the value, or subscribes to changes, so it does
 * not need to know which one it got.
 *
 * The textarea always remains in the DOM and always holds the current value.
 * That is deliberate: it is the non-JavaScript fallback, it is what a screen
 * reader falls back to if the rich editor fails to construct, and it keeps the
 * save path identical in both modes.
 *
 * CodeMirror comes from tiny_html, which ships it in Moodle core. Reusing it
 * avoids vendoring a second copy of a large library and inheriting its upkeep,
 * at the cost of depending on a module that core owns rather than a documented
 * API. If that module ever moves, construction throws and the workspace falls
 * back to the textarea rather than breaking.
 *
 * @module     mod_saylorcode/editor
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {EditorState, EditorView, basicSetup} from 'tiny_html/codemirror-lazy';

/**
 * An editor backed by the plain textarea.
 *
 * @param {HTMLTextAreaElement} textarea The textarea.
 * @returns {Object} The editor interface.
 */
const plainEditor = (textarea) => ({
    rich: false,

    getValue: () => textarea.value,

    setValue: (value) => {
        textarea.value = value;
    },

    onChange: (callback) => {
        textarea.addEventListener('input', callback);
    },

    focus: () => textarea.focus(),
});

/**
 * Build a CodeMirror editor over the textarea.
 *
 * @param {HTMLTextAreaElement} textarea The textarea to enhance.
 * @param {string} ariaLabel Accessible name for the editing surface.
 * @returns {Object} The editor interface.
 */
const richEditor = (textarea, ariaLabel) => {
    const host = document.createElement('div');
    host.className = 'saylorcode-cm';
    textarea.parentNode.insertBefore(host, textarea);

    // The textarea stays in the DOM and stays authoritative, but it is taken
    // out of the tab order and hidden from assistive technology, because
    // CodeMirror now provides the editing surface and announcing both would
    // present the same field twice.
    textarea.classList.add('saylorcode-editor-hidden');
    textarea.setAttribute('tabindex', '-1');
    textarea.setAttribute('aria-hidden', 'true');

    // Replaced by onChange(); until then a change has nowhere to go.
    let notify = () => false;

    const view = new EditorView({
        parent: host,
        state: EditorState.create({
            doc: textarea.value,
            extensions: [
                // The basic setup supplies the line number gutter, the fold
                // gutter, bracket matching, undo history and the keymap.
                basicSetup,
                EditorView.updateListener.of((update) => {
                    if (!update.docChanged) {
                        return;
                    }
                    // Mirrored into the textarea so the save path, the download
                    // and the non-JavaScript fallback all keep working.
                    textarea.value = update.state.doc.toString();
                    notify();
                }),
                EditorView.contentAttributes.of({
                    'aria-label': ariaLabel,
                    'data-gramm': 'false',
                }),
            ],
        }),
    });

    return {
        rich: true,

        getValue: () => view.state.doc.toString(),

        setValue: (value) => {
            view.dispatch({
                changes: {from: 0, to: view.state.doc.length, insert: value},
            });
            textarea.value = value;
        },

        onChange: (callback) => {
            notify = callback;
        },

        focus: () => view.focus(),
    };
};

/**
 * Create the best editor available for a textarea.
 *
 * @param {HTMLTextAreaElement} textarea The textarea to enhance.
 * @param {string} ariaLabel Accessible name for the editing surface.
 * @returns {Object} The editor interface.
 */
export const create = (textarea, ariaLabel) => {
    if (!textarea) {
        return null;
    }

    try {
        return richEditor(textarea, ariaLabel);
    } catch (e) {
        // A workspace that edits plainly is far better than one that does not
        // load, so any failure to construct the rich editor degrades silently.
        return plainEditor(textarea);
    }
};
