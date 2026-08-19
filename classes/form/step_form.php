<?php
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

namespace mod_saylorcode\form;

use local_saylorcode\local\stable_id;
use mod_saylorcode\local\step_manager;
use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * One step of a guided lesson.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_form extends moodleform {
    /**
     * Build the form.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'stepid', $this->_customdata['stepid']);
        $mform->setType('stepid', PARAM_INT);

        $mform->addElement(
            'text',
            'title',
            get_string('steptitle', 'mod_saylorcode'),
            ['size' => 60, 'maxlength' => 255]
        );
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        // The column holds 255 characters. Without this an author who pastes
        // something longer meets a database error rather than a form message.
        $mform->addRule('title', get_string('steptitletoolong', 'mod_saylorcode'), 'maxlength', 255, 'client');

        $mform->addElement('select', 'steptype', get_string('steptype', 'mod_saylorcode'), [
            'instruction' => get_string('steptypeinstruction', 'mod_saylorcode'),
            'example' => get_string('steptypeexample', 'mod_saylorcode'),
            'checkpoint' => get_string('steptypecheckpoint', 'mod_saylorcode'),
            'reflection' => get_string('steptypereflection', 'mod_saylorcode'),
            'summary' => get_string('steptypesummary', 'mod_saylorcode'),
        ]);
        $mform->setDefault('steptype', 'checkpoint');
        $mform->addHelpButton('steptype', 'steptype', 'mod_saylorcode');

        $mform->addElement('editor', 'instructions', get_string('stepinstructions', 'mod_saylorcode'));
        $mform->setType('instructions', PARAM_RAW);

        $mform->addElement('text', 'sectiontitle', get_string('stepsection', 'mod_saylorcode'), ['size' => 60]);
        $mform->setType('sectiontitle', PARAM_TEXT);
        $mform->addHelpButton('sectiontitle', 'stepsection', 'mod_saylorcode');

        $mform->addElement('header', 'progression', get_string('stepprogression', 'mod_saylorcode'));
        $mform->setExpanded('progression', true);

        $mform->addElement('select', 'completionrule', get_string('stepcompletionrule', 'mod_saylorcode'), [
            step_manager::RULE_VIEW => get_string('steprule' . step_manager::RULE_VIEW, 'mod_saylorcode'),
            step_manager::RULE_RUN => get_string('steprule' . step_manager::RULE_RUN, 'mod_saylorcode'),
            step_manager::RULE_PASSTESTS => get_string('steprule' . step_manager::RULE_PASSTESTS, 'mod_saylorcode'),
            step_manager::RULE_SUBMIT => get_string('steprule' . step_manager::RULE_SUBMIT, 'mod_saylorcode'),
        ]);
        $mform->setDefault('completionrule', step_manager::RULE_PASSTESTS);
        $mform->addHelpButton('completionrule', 'stepcompletionrule', 'mod_saylorcode');

        $mform->addElement('advcheckbox', 'carryforward', get_string('stepcarryforward', 'mod_saylorcode'));
        $mform->setDefault('carryforward', 1);
        $mform->addHelpButton('carryforward', 'stepcarryforward', 'mod_saylorcode');

        $mform->addElement('advcheckbox', 'allowrevisit', get_string('stepallowrevisit', 'mod_saylorcode'));
        $mform->setDefault('allowrevisit', 1);
        $mform->addHelpButton('allowrevisit', 'stepallowrevisit', 'mod_saylorcode');

        $mform->addElement('text', 'stableid', get_string('stepstableid', 'mod_saylorcode'), ['size' => 24]);
        $mform->setType('stableid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('stableid', 'stepstableid', 'mod_saylorcode');

        $mform->addElement('text', 'points', get_string('steppoints', 'mod_saylorcode'), ['size' => 6]);
        $mform->setType('points', PARAM_FLOAT);
        $mform->setDefault('points', 0);

        $this->add_action_buttons();
    }

    /**
     * Check the form.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('steptitlerequired', 'mod_saylorcode');
        }

        // An exercise reference is optional, but a malformed one is a mistake
        // that would only show up as a broken step for a student.
        $stableid = trim((string) ($data['stableid'] ?? ''));
        if ($stableid !== '' && !stable_id::is_valid($stableid)) {
            $errors['stableid'] = get_string('stableidinvalid', 'mod_saylorcode');
        }

        if ((float) ($data['points'] ?? 0) < 0) {
            $errors['points'] = get_string('steppointsnegative', 'mod_saylorcode');
        }

        return $errors;
    }
}
