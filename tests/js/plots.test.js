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
 * Plots an R program drew, shown under its output.
 *
 * @module     mod_saylorcode/tests/plots
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Workspace} from 'mod_saylorcode/workspace';
import {reset as resetAjax} from 'core/ajax';
import {reset as resetNotification} from 'core/notification';
import {mount, settle} from './helpers/shell';

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

const result = (plots) => ({
    state: 'completed', stdout: 'done', stderr: '', compileroutput: '', tests: [], truncated: false, plots,
});

describe('plots', () => {
    beforeEach(() => {
        resetAjax();
        resetNotification();
    });

    it('draws each plot as a PNG image, in order', async() => {
        const root = mount({languageid: 'r'});
        const workspace = new Workspace(root);

        workspace.renderResult(result([PNG, PNG]), 'run');
        await settle();

        const region = root.querySelector('[data-region="plots"]');
        const images = region.querySelectorAll('img');
        expect(region.hidden).toBe(false);
        expect(images).toHaveLength(2);
        expect(images[0].getAttribute('src')).toBe(`data:image/png;base64,${PNG}`);
        // The label comes from the language string, so it is never left empty.
        expect(images[0].alt).not.toBe('');
    });

    it('refuses anything that is not plain base64', () => {
        const root = mount({languageid: 'r'});
        const workspace = new Workspace(root);

        workspace.renderResult(result([
            'abc" onerror="alert(1)',
            'data:image/svg+xml,<svg/>',
            '<img src=x>',
            42,
            PNG,
        ]), 'run');

        const images = root.querySelectorAll('[data-region="plots"] img');
        expect(images).toHaveLength(1);
        expect(images[0].getAttribute('src')).toBe(`data:image/png;base64,${PNG}`);
    });

    it('stays hidden when a run draws nothing, and clears on the next run', () => {
        const root = mount({languageid: 'r'});
        const workspace = new Workspace(root);
        const region = root.querySelector('[data-region="plots"]');

        workspace.renderResult(result([PNG]), 'run');
        expect(region.hidden).toBe(false);

        workspace.clearResults();
        expect(region.hidden).toBe(true);
        expect(region.children).toHaveLength(0);

        workspace.renderResult(result(undefined), 'run');
        expect(region.hidden).toBe(true);
    });
});
