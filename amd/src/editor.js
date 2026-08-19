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
 * CodeMirror is vendored with this plugin rather than borrowed from core. The
 * copy core ships for the TinyMCE HTML plugin exports only HTML, JavaScript and
 * XML languages, and none of the extension API, so no Java grammar can be added
 * to it. Borrowing the TypeScript grammar instead was rejected: TypeScript
 * writes parameter types after a colon, so every "main(String[] args)" in the
 * course would render as a syntax error on line one.
 *
 * The cost is a large dependency this plugin now maintains. If construction
 * fails for any reason the workspace still falls back to the plain textarea
 * rather than breaking.
 *
 * @module     mod_saylorcode/editor
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {EditorState, EditorView, basicSetup, java, indentUnit,
    HighlightStyle, syntaxHighlighting, tags} from 'mod_saylorcode/codemirror-lazy';

// Token colours as CSS custom properties rather than literals, so they
// follow the editor's own light and dark surfaces. CodeMirror's default
// palette is written for a light background and fails WCAG AA badly on the
// dark one: its keyword purple measures 1.43:1 against our editor.
const highlighting = HighlightStyle.define([
    {tag: tags.keyword, color: 'var(--sc-tok-keyword)'},
    {tag: [tags.name, tags.deleted, tags.character, tags.propertyName, tags.macroName],
        color: 'var(--sc-tok-name)'},
    {tag: [tags.function(tags.variableName), tags.labelName], color: 'var(--sc-tok-function)'},
    {tag: [tags.color, tags.constant(tags.name), tags.standard(tags.name)], color: 'var(--sc-tok-constant)'},
    {tag: [tags.typeName, tags.className, tags.changed, tags.annotation, tags.modifier,
        tags.self, tags.namespace], color: 'var(--sc-tok-type)'},
    {tag: [tags.operator, tags.operatorKeyword, tags.punctuation], color: 'var(--sc-tok-operator)'},
    {tag: [tags.string, tags.inserted, tags.special(tags.string)], color: 'var(--sc-tok-string)'},
    {tag: [tags.number, tags.bool, tags.null], color: 'var(--sc-tok-number)'},
    {tag: [tags.comment, tags.lineComment, tags.blockComment], color: 'var(--sc-tok-comment)',
        fontStyle: 'italic'},
    {tag: tags.invalid, color: 'var(--sc-tok-invalid)'},
]);

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

                // Java, which is the whole reason this bundle is vendored
                // rather than borrowed from core.
                java(),
                syntaxHighlighting(highlighting),

                // Four spaces, which is what the Java the students read uses.
                indentUnit.of('    '),
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
