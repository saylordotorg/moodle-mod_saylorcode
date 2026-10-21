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
 * Controls in an author preview.
 *
 * Staff testing an HTML or CSS activity land on the author preview, where
 * nothing may reach a web service: they have no attempt. Run, Reset and
 * Download need no server for a language the browser draws, so they must
 * work there; anything that would call a web service must stay inert.
 *
 * @module     mod_saylorcode/tests/previewcontrols
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Workspace} from 'mod_saylorcode/workspace';
import {calls, reset as resetAjax} from 'core/ajax';
import {confirmations, reset as resetNotification} from 'core/notification';
import {mount, settle} from './helpers/shell';

describe('author preview controls', () => {
    beforeEach(() => {
        resetAjax();
        resetNotification();
    });

    it('runs an HTML page in the browser and saves nothing', async() => {
        const root = mount({preview: true, browser: true, languageid: 'html', starter: '<p>one</p>'});
        const workspace = new Workspace(root);

        workspace.code.setValue('<p>edited</p>');
        workspace.handleAction('run');
        await settle();

        expect(root.querySelector('[data-region="preview"]').srcdoc).toContain('<p>edited</p>');
        expect(calls).toHaveLength(0);
        expect(workspace.busy).toBe(false);
    });

    it('resets to the starter code locally, without asking the server', async() => {
        const root = mount({preview: true, browser: true, languageid: 'css', starter: 'p { color: red; }', previewpage: '<p>x</p>'});
        const workspace = new Workspace(root);

        workspace.code.setValue('p { color: blue; }');
        workspace.handleAction('reset');
        await settle();

        // Reset asks first, as it does for a student; the mock agrees.
        expect(confirmations).toHaveLength(1);

        expect(workspace.code.getValue()).toBe('p { color: red; }');
        expect(root.querySelector('[data-region="preview"]').srcdoc).toContain('p { color: red; }');
        expect(calls).toHaveLength(0);
    });

    it('typing in a preview never schedules a save', async() => {
        const root = mount({preview: true, browser: true, languageid: 'html', starter: '<p>one</p>'});
        const workspace = new Workspace(root);

        workspace.code.setValue('<p>two</p>');
        await settle();

        expect(workspace.dirty).toBe(false);
        expect(calls).toHaveLength(0);
    });

    it('keeps Run inert in a preview of a language the runner executes', async() => {
        const root = mount({preview: true, languageid: 'r', starter: 'cat(1)'});
        const workspace = new Workspace(root);

        workspace.handleAction('run');
        workspace.handleAction('check');
        workspace.handleAction('submit');
        workspace.handleAction('reset');
        await settle();

        expect(calls).toHaveLength(0);
        expect(confirmations).toHaveLength(0);
    });
});
