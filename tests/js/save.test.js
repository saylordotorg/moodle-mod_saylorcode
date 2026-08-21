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
 * Autosave, and what happens when two tabs disagree.
 *
 * The server side rule is covered by PHPUnit: attempt_manager decides what
 * counts as a conflict. What was never covered is this side of it, because
 * until there was a JavaScript harness there was no way to cover it -- and this
 * side is where a student's work actually gets lost. A tab that treats a
 * refused save as a successful one moves on believing the code is stored, and
 * the next thing to overwrite it does so without anyone noticing.
 *
 * @module     mod_saylorcode/tests/save
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Workspace} from 'mod_saylorcode/workspace';
import {calls, respondWith, reset as resetAjax} from 'core/ajax';
import {reset as resetNotification} from 'core/notification';
import {resolved, rejected} from './helpers/jquery_deferred';
import {mount, settle} from './helpers/shell';

/**
 * The arguments of the last save request.
 *
 * @returns {Object|null} The args, or null if no save was attempted.
 */
const lastSaveArgs = () => {
    const saves = calls.filter((call) => call.methodname === 'mod_saylorcode_save_code');

    return saves.length ? saves[saves.length - 1].args : null;
};

describe('workspace autosave', () => {
    let root;
    let workspace;

    beforeEach(() => {
        resetAjax();
        resetNotification();
        root = mount({starter: 'original'});
        workspace = new Workspace(root);
    });

    it('sends the snapshot it knows about, so the server can spot a conflict', async() => {
        respondWith(() => resolved({saved: true, conflict: false, snapshotid: 7, message: 'saved'}));

        // The first request carries zero, because nothing is known yet, and
        // checking only that one proves nothing: hard coding the argument to
        // zero would satisfy it while disabling conflict detection entirely,
        // since has_conflict() reads zero as a first save and returns false.
        // So a snapshot is established first, and the request after it is what
        // gets inspected.
        workspace.code.setValue('first version');
        await workspace.save();
        await settle();
        expect(workspace.knownSnapshotId).toBe(7);

        workspace.code.setValue('second version');
        await workspace.save();
        await settle();

        const args = lastSaveArgs();

        expect(args).not.toBeNull();
        expect(args.knownsnapshotid).toBe(7);
        expect(args.browsersession).toBeTruthy();
    });

    it('remembers the snapshot the server assigned', async() => {
        respondWith(() => resolved({saved: true, conflict: false, snapshotid: 7, message: 'saved'}));

        workspace.code.setValue('changed');
        await workspace.save();
        await settle();

        expect(workspace.knownSnapshotId).toBe(7);
        expect(workspace.dirty).toBe(false);
    });

    it('does not treat a refused save as a saved one', async() => {
        // A successful save first, so the known snapshot is something other
        // than its starting value. Asserting it "is not zero" from a standing
        // start proves nothing, because zero is where it begins.
        respondWith(() => resolved({saved: true, conflict: false, snapshotid: 5, message: 'saved'}));
        workspace.code.setValue('earlier work');
        await workspace.save();
        await settle();
        expect(workspace.knownSnapshotId).toBe(5);

        respondWith(() => resolved({saved: false, conflict: true, snapshotid: 0, message: 'changed elsewhere'}));
        workspace.code.setValue('my work');
        const result = await workspace.save();
        await settle();

        expect(result).toBe(false);

        // The three things that must not happen on a conflict: the code must
        // not be recorded as stored, the known snapshot must not be dragged
        // back by the refusal, and the work must still be in the editor.
        expect(workspace.lastSavedValue).not.toBe('my work');
        expect(workspace.knownSnapshotId).toBe(5);
        expect(workspace.code.getValue()).toBe('my work');
    });

    it('leaves a conflicted save marked as unsaved work', async() => {
        respondWith(() => resolved({saved: false, conflict: true, snapshotid: 0, message: 'changed elsewhere'}));

        workspace.code.setValue('my work');
        await workspace.save();
        await settle();

        // Still dirty: a later flush has to try again rather than assume the
        // work is safely stored.
        expect(workspace.dirty).toBe(true);
    });

    it('does not ask the server to store code that has not changed', async() => {
        respondWith(() => resolved({saved: true, conflict: false, snapshotid: 1, message: 'saved'}));

        await workspace.save();
        await settle();

        expect(lastSaveArgs()).toBeNull();
    });

    it('keeps the work in the editor when the save request fails outright', async() => {
        respondWith(() => rejected(new Error('network gone')));

        workspace.code.setValue('my work');
        await expect(workspace.save()).rejects.toThrow('network gone');
        await settle();

        expect(workspace.code.getValue()).toBe('my work');
        expect(workspace.lastSavedValue).not.toBe('my work');
    });

    it('uses one session identifier for every save from this tab', async() => {
        respondWith(() => resolved({saved: true, conflict: false, snapshotid: 1, message: 'saved'}));

        workspace.code.setValue('one');
        await workspace.save();
        workspace.code.setValue('two');
        await workspace.save();
        await settle();

        const saves = calls.filter((call) => call.methodname === 'mod_saylorcode_save_code');
        const sessions = new Set(saves.map((call) => call.args.browsersession));

        expect(saves.length).toBe(2);
        // A session that changed per request would make every save look like it
        // came from a different tab, and the same-session exemption on the
        // server would stop working.
        expect(sessions.size).toBe(1);
    });
});
