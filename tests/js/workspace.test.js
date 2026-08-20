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
 * The workspace busy lifecycle.
 *
 * Every test here exists because of one incident. A run left the controls
 * permanently dead -- no rerun, no reset, nothing but a page reload -- and the
 * cause was a chain ending in finally on a jQuery promise, which has no
 * finally. The throw happened while the chain was being built, after the then
 * handler had been registered, so the run appeared to succeed: output arrived,
 * the status said "Ran", and the flag that gates every control was never
 * cleared.
 *
 * Nothing could catch that, because there were no JavaScript tests at all.
 *
 * @module     mod_saylorcode/tests/workspace
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Workspace} from 'mod_saylorcode/workspace';
import Ajax, {calls, respondWith, reset as resetAjax} from 'core/ajax';
import {exceptions, reset as resetNotification} from 'core/notification';
import {resolved, rejected, deferred} from './helpers/jquery_deferred';
import {mount, settle} from './helpers/shell';

describe('workspace busy lifecycle', () => {
    let root;

    beforeEach(() => {
        resetAjax();
        resetNotification();
        root = mount();
    });

    it('clears busy after a successful run', async() => {
        respondWith(() => resolved({
            state: 'completed',
            stdout: 'Hello, World!',
            stderr: '',
            compileroutput: '',
            tests: [],
            truncated: false,
            attempts: 1,
        }));

        const workspace = new Workspace(root);
        await workspace.execute('run');
        await settle();

        expect(workspace.busy).toBe(false);
    });

    it('clears busy when the request is rejected', async() => {
        respondWith(() => rejected(new Error('runner unavailable')));

        const workspace = new Workspace(root);
        await expect(workspace.execute('run')).rejects.toThrow('runner unavailable');
        await settle();

        expect(workspace.busy).toBe(false);
    });

    it('clears busy when the request throws before a promise exists', async() => {
        respondWith(() => {
            throw new Error('exploded while building the request');
        });

        const workspace = new Workspace(root);
        await expect(workspace.execute('run')).rejects.toThrow('exploded');

        expect(workspace.busy).toBe(false);
    });

    it('clears busy after a reset', async() => {
        respondWith(() => resolved({files: JSON.stringify({'Main.java': 'starter'})}));

        const workspace = new Workspace(root);
        await workspace.reset();
        await settle();

        expect(workspace.busy).toBe(false);
    });

    it('leaves the workspace usable after a run, which is the whole point', async() => {
        respondWith(() => resolved({
            state: 'completed',
            stdout: 'ok',
            stderr: '',
            compileroutput: '',
            tests: [],
            truncated: false,
            attempts: 1,
        }));

        const workspace = new Workspace(root);
        await workspace.execute('run');
        await settle();

        // The reported symptom: a second run, and reset, both did nothing.
        const before = calls.length;
        workspace.handleAction('run');
        await settle();

        expect(calls.length).toBeGreaterThan(before);
    });

    it('ignores a second action while one is genuinely in flight', async() => {
        const pending = deferred();
        respondWith(() => pending.promise);

        const workspace = new Workspace(root);
        const running = workspace.execute('run');

        expect(workspace.busy).toBe(true);

        const during = calls.length;
        workspace.handleAction('run');
        await settle();

        expect(calls.length).toBe(during);

        pending.resolve({
            state: 'completed',
            stdout: '',
            stderr: '',
            compileroutput: '',
            tests: [],
            truncated: false,
            attempts: 1,
        });
        await running;
        await settle();

        expect(workspace.busy).toBe(false);
    });

    it('surfaces a failure rather than swallowing it', async() => {
        respondWith(() => rejected(new Error('runner unavailable')));

        const workspace = new Workspace(root);
        workspace.handleAction('run');
        await settle();

        expect(exceptions.length).toBeGreaterThan(0);
        expect(workspace.busy).toBe(false);
    });
});

describe('workspace preview mode', () => {
    beforeEach(() => {
        resetAjax();
        resetNotification();
    });

    it('reaches no web service at all', async() => {
        const root = mount({preview: true});
        const workspace = new Workspace(root);

        workspace.handleAction('run');
        workspace.handleAction('check');
        workspace.handleAction('reset');
        await settle();

        expect(calls.length).toBe(0);
    });
});
