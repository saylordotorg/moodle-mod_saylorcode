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
 * HTML and CSS activities: the previewed page, and the workspace drawing it.
 *
 * @module     mod_saylorcode/tests/preview
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {buildDocument, isFromFrame, MESSAGE_KEY} from 'mod_saylorcode/preview';
import {Workspace} from 'mod_saylorcode/workspace';
import {calls, reset as resetAjax} from 'core/ajax';
import {reset as resetNotification} from 'core/notification';
import {mount, settle} from './helpers/shell';

const ORIGIN = 'https://moodle.example';

describe('preview document', () => {
    it('renders an HTML page as written, after its doctype', () => {
        const page = '<!DOCTYPE html><html><body><h1>Hi</h1></body></html>';
        const built = buildDocument('html', page, '', ORIGIN);

        // The doctype stays first, or the page renders in quirks mode.
        expect(built.startsWith('<!DOCTYPE html><script>')).toBe(true);
        expect(built).toContain('<h1>Hi</h1>');
    });

    it('sends console output only to the workspace origin', () => {
        const built = buildDocument('html', '<p>x</p>', '', ORIGIN);

        expect(built).toContain(JSON.stringify(ORIGIN));
        expect(built).not.toContain('"*"');
    });

    it('applies a stylesheet to the author page, last in its head', () => {
        const page = '<html><head><link rel="stylesheet" href="base.css"></head><body><p class="note">x</p></body></html>';
        const built = buildDocument('css', '.note { color: red; }', page, ORIGIN);

        expect(built).toContain('<p class="note">x</p>');
        expect(built.indexOf('base.css')).toBeLessThan(built.indexOf('.note { color: red; }'));
        expect(built).toContain('<style>.note { color: red; }</style></head>');
    });

    it('wraps an author fragment in a document', () => {
        const built = buildDocument('css', 'p { margin: 0; }', '<p>Fragment</p>', ORIGIN);

        expect(built).toContain('<body><p>Fragment</p></body>');
        expect(built).toContain('<style>p { margin: 0; }</style>');
    });

    it('cannot be broken out of by a closing style tag in the CSS', () => {
        const built = buildDocument('css', 'p {}</style><script>alert(1)</script>', '<p>x</p>', ORIGIN);

        // Exactly one closing tag: the one the builder wrote.
        expect(built.match(/<\/style>/gi)).toHaveLength(1);
    });

    it('recognises messages only from its own frame', () => {
        const frame = document.createElement('iframe');
        document.body.appendChild(frame);
        const data = {[MESSAGE_KEY]: true, kind: 'log', text: 'hello'};

        expect(isFromFrame({source: frame.contentWindow, data}, frame)).toBe(true);
        expect(isFromFrame({source: window, data}, frame)).toBe(false);
        expect(isFromFrame({source: frame.contentWindow, data: {text: 'x'}}, frame)).toBe(false);
    });
});

describe('workspace with a browser language', () => {
    beforeEach(() => {
        resetAjax();
        resetNotification();
    });

    it('draws the page on load without calling the server', () => {
        const root = mount({browser: true, languageid: 'html', starter: '<h1>Hello</h1>'});

        new Workspace(root);

        expect(root.querySelector('[data-region="preview"]').srcdoc).toContain('<h1>Hello</h1>');
        expect(calls).toHaveLength(0);
    });

    it('runs by redrawing and saving, never by asking the runner', async() => {
        const root = mount({browser: true, languageid: 'html', starter: '<p>one</p>'});
        const workspace = new Workspace(root);

        workspace.code.setValue('<p>two</p>');
        workspace.handleAction('run');
        await settle();

        expect(root.querySelector('[data-region="preview"]').srcdoc).toContain('<p>two</p>');
        const methods = calls.map((call) => call.methodname);
        expect(methods).not.toContain('mod_saylorcode_run_code');
        expect(methods).toContain('mod_saylorcode_save_code');
        expect(workspace.busy).toBe(false);
    });

    it('styles the author page for a CSS activity', () => {
        const root = mount({
            browser: true,
            languageid: 'css',
            starter: 'h1 { color: green; }',
            previewpage: '<h1>Title</h1>',
        });

        new Workspace(root);

        const srcdoc = root.querySelector('[data-region="preview"]').srcdoc;
        expect(srcdoc).toContain('<h1>Title</h1>');
        expect(srcdoc).toContain('h1 { color: green; }');
    });

    it('shows console output from the page in the console', () => {
        const root = mount({browser: true, languageid: 'html', starter: '<p>x</p>'});
        new Workspace(root);
        const frame = root.querySelector('[data-region="preview"]');

        window.dispatchEvent(new MessageEvent('message', {
            source: frame.contentWindow,
            data: {[MESSAGE_KEY]: true, kind: 'error', text: 'boom is not defined'},
        }));

        const line = root.querySelector('[data-region="console"] .saylorcode-line-err');
        expect(line.textContent).toBe('boom is not defined');
    });
});
