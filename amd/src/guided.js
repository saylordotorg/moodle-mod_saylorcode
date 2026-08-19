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
 * The step panel of a guided lesson.
 *
 * Owns which step is open and how the lesson is drawn. The workspace beneath it
 * knows nothing about steps beyond reporting what the student did, which keeps
 * the editor usable on its own.
 *
 * @module     mod_saylorcode/guided
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    PANEL: '[data-region="saylorcode-guided"]',
    STEP_BUTTON: '[data-action="openstep"]',
    TITLE: '[data-region="saylorcode-steptitle"]',
    INSTRUCTIONS: '[data-region="saylorcode-stepinstructions"]',
    HINT: '[data-region="saylorcode-stephint"]',
    PROGRESS_TEXT: '.saylorcode-guided-progress-text',
    BAR_FILL: '.saylorcode-guided-bar-fill',
    ITEM: '.saylorcode-guided-step',
};

/**
 * The step panel.
 */
class Guided {

    /**
     * Wire up the panel.
     *
     * @param {HTMLElement} panel The panel element.
     */
    constructor(panel) {
        this.panel = panel;
        this.cmid = parseInt(panel.dataset.cmid, 10);

        panel.addEventListener('click', (e) => {
            const button = e.target.closest(SELECTORS.STEP_BUTTON);
            if (button && panel.contains(button)) {
                e.preventDefault();
                this.open(parseInt(button.dataset.stepid, 10));
            }
        });

        // The workspace reports what the student did; the panel decides what
        // that means for the lesson.
        document.addEventListener('saylorcode:stepprogress', (e) => this.applyProgress(e.detail));
    }

    /**
     * Open a step.
     *
     * @param {number} stepid The step to open.
     * @returns {Promise} Resolved once the panel and editor have caught up.
     */
    open(stepid) {
        return Ajax.call([{
            methodname: 'mod_saylorcode_open_step',
            args: {cmid: this.cmid, stepid: stepid},
        }])[0].then((step) => {
            this.render(step);
            this.loadCode(step.code);

            return step;
        }).catch(Notification.exception);
    }

    /**
     * Draw a step.
     *
     * @param {Object} step The step payload.
     */
    render(step) {
        this.panel.dataset.currentstepid = step.stepid;

        const title = this.panel.querySelector(SELECTORS.TITLE);
        const instructions = this.panel.querySelector(SELECTORS.INSTRUCTIONS);
        const hint = this.panel.querySelector(SELECTORS.HINT);

        if (title) {
            title.textContent = step.title;
        }
        if (instructions) {
            // Server rendered through format_text, which is what makes this
            // safe to insert as markup rather than as text.
            instructions.innerHTML = step.instructions;
        }
        if (hint) {
            hint.textContent = step.completionhint;
        }

        this.markCurrent(step.stepid);
        this.setProgress(step.stepscomplete, step.stepstotal, step.percent);
    }

    /**
     * Update the lesson after the student ran, checked or submitted.
     *
     * @param {Object} progress The step progress from the execution response.
     */
    applyProgress(progress) {
        this.setProgress(progress.stepscomplete, progress.stepstotal, progress.percent);

        const item = this.itemFor(progress.stepid);
        if (item && progress.complete) {
            item.classList.add('saylorcode-guided-step-done');
        }

        // A finished step unlocks the next one, so the button that was locked
        // has to become usable without a page reload. The progress readout is
        // role="status" and setProgress has just rewritten it, which is what
        // tells a screen reader the lesson moved on.
        if (progress.complete && progress.currentstepid && progress.currentstepid !== progress.stepid) {
            this.unlock(progress.currentstepid);
        }
    }

    /**
     * Turn a locked step into one the student can open.
     *
     * @param {number} stepid The step to unlock.
     */
    unlock(stepid) {
        const item = this.itemFor(stepid);
        if (!item) {
            return;
        }

        item.classList.remove('saylorcode-guided-step-locked');

        const label = item.querySelector('.saylorcode-guided-steplabel');
        if (!label || label.tagName === 'BUTTON') {
            return;
        }

        // Rendered as a span while locked, because a disabled button is
        // announced as a control the student could use. Becoming available
        // means becoming a real control.
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'saylorcode-guided-steplabel';
        button.dataset.action = 'openstep';
        button.dataset.stepid = String(stepid);
        button.textContent = label.textContent.trim();
        label.replaceWith(button);
    }

    /**
     * Move the current marker.
     *
     * @param {number} stepid The step now open.
     */
    markCurrent(stepid) {
        this.panel.querySelectorAll(SELECTORS.ITEM).forEach((item) => {
            item.classList.remove('saylorcode-guided-step-current');
        });

        this.panel.querySelectorAll(SELECTORS.STEP_BUTTON).forEach((button) => {
            button.removeAttribute('aria-current');
        });

        const item = this.itemFor(stepid);
        if (item) {
            item.classList.add('saylorcode-guided-step-current');
            const button = item.querySelector(SELECTORS.STEP_BUTTON);
            if (button) {
                button.setAttribute('aria-current', 'step');
            }
        }
    }

    /**
     * Update the progress readout.
     *
     * @param {number} complete Steps finished.
     * @param {number} total Steps in the lesson.
     * @param {number} percent Percentage complete.
     */
    setProgress(complete, total, percent) {
        const text = this.panel.querySelector(SELECTORS.PROGRESS_TEXT);
        const fill = this.panel.querySelector(SELECTORS.BAR_FILL);

        if (text) {
            getString('stepprogress', 'mod_saylorcode', complete + '/' + total).then((label) => {
                text.textContent = label;

                return label;
            }).catch(Notification.exception);
        }

        if (fill) {
            fill.style.width = percent + '%';
        }
    }

    /**
     * The list item for a step.
     *
     * @param {number} stepid The step.
     * @returns {HTMLElement|null} The item.
     */
    itemFor(stepid) {
        // Looked up on the list item, not the button: a locked step is a span
        // rather than a button, and unlock() has to be able to find it.
        return this.panel.querySelector(SELECTORS.ITEM + '[data-stepid="' + stepid + '"]');
    }

    /**
     * Put a step's code into the editor.
     *
     * @param {string} code The code to show.
     */
    loadCode(code) {
        // The workspace owns the editor, so this asks rather than reaches in.
        document.dispatchEvent(new CustomEvent('saylorcode:setcode', {
            detail: {code: code},
        }));
    }
}

/**
 * Start the panel.
 *
 * @param {number} cmid The course module id.
 */
export const init = (cmid) => {
    document.querySelectorAll(SELECTORS.PANEL).forEach((panel) => {
        if (parseInt(panel.dataset.cmid, 10) === cmid) {
            new Guided(panel);
        }
    });
};
