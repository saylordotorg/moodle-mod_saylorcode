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

// Bundle entry for mod_saylorcode.
//
// Exports the same shape as Moodle core's tiny_html/codemirror-lazy, so the
// editor can move between them, plus a grammar for every language the
// workspace offers. Core exports no Java or R grammar and no StreamLanguage,
// which is why this bundle exists at all. Rebuild with build/build.sh.

import {EditorView, basicSetup} from "codemirror";
import {EditorState} from "@codemirror/state";
import {keymap} from "@codemirror/view";
import {indentWithTab} from "@codemirror/commands";
import {HighlightStyle, StreamLanguage, indentUnit, syntaxHighlighting} from "@codemirror/language";
import {tags} from "@lezer/highlight";
import {java as javaMode} from "@codemirror/legacy-modes/mode/clike";
import {r as rMode} from "@codemirror/legacy-modes/mode/r";
import {javascript} from "@codemirror/lang-javascript";
import {html} from "@codemirror/lang-html";
import {css} from "@codemirror/lang-css";

const java = () => StreamLanguage.define(javaMode);
const r = () => StreamLanguage.define(rMode);

export {
    EditorState,
    EditorView,
    HighlightStyle,
    basicSetup,
    css,
    html,
    indentUnit,
    indentWithTab,
    java,
    javascript,
    keymap,
    r,
    syntaxHighlighting,
    tags,
};
