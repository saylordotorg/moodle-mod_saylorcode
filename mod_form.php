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

/**
 * Settings form for a Saylor Code Studio activity.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_saylorcode\local\runtime\profile_manager;
use local_saylorcode\local\stable_id;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Module settings form.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_saylorcode_mod_form extends moodleform_mod {
    /**
     * Build the form.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Exercise selection.
        $mform->addElement('header', 'exerciseheader', get_string('instructions', 'mod_saylorcode'));
        $mform->setExpanded('exerciseheader');

        $modes = [
            'guided' => get_string('modeguided', 'mod_saylorcode'),
            'challenge' => get_string('modechallenge', 'mod_saylorcode'),
            'project' => get_string('modeproject', 'mod_saylorcode'),
            'playground' => get_string('modeplayground', 'mod_saylorcode'),
        ];
        $mform->addElement('select', 'activitymode', get_string('activitymode', 'mod_saylorcode'), $modes);
        $mform->addHelpButton('activitymode', 'activitymode', 'mod_saylorcode');
        $mform->setDefault('activitymode', 'challenge');

        $mform->addElement('text', 'stableid', get_string('stableid', 'mod_saylorcode'), ['size' => '20']);
        $mform->setType('stableid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('stableid', 'stableid', 'mod_saylorcode');
        $mform->hideIf('stableid', 'activitymode', 'eq', 'playground');

        $versionpolicies = [
            'latest' => get_string('versionlatest', 'mod_saylorcode'),
            'pinned' => get_string('versionpinned', 'mod_saylorcode'),
        ];
        $mform->addElement('select', 'versionpolicy', get_string('versionpolicy', 'mod_saylorcode'), $versionpolicies);
        $mform->addHelpButton('versionpolicy', 'versionpolicy', 'mod_saylorcode');
        $mform->setDefault('versionpolicy', 'latest');
        $mform->hideIf('versionpolicy', 'activitymode', 'eq', 'playground');

        $profiles = (new profile_manager())->get_menu();
        $mform->addElement('select', 'profileid', get_string('profileid', 'mod_saylorcode'), $profiles);
        $mform->addHelpButton('profileid', 'profileid', 'mod_saylorcode');

        // Student experience.
        $mform->addElement('header', 'experienceheader', get_string('feedback', 'mod_saylorcode'));

        $attempts = [0 => get_string('unlimitedattempts', 'mod_saylorcode')];
        for ($i = 1; $i <= 10; $i++) {
            $attempts[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mod_saylorcode'), $attempts);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'mod_saylorcode');
        $mform->setDefault('maxattempts', 0);

        $mform->addElement('advcheckbox', 'allowhints', get_string('allowhints', 'mod_saylorcode'));
        $mform->setDefault('allowhints', 1);

        $mform->addElement('advcheckbox', 'allowsolution', get_string('allowsolution', 'mod_saylorcode'));
        $mform->addHelpButton('allowsolution', 'allowsolution', 'mod_saylorcode');
        $mform->setDefault('allowsolution', 0);

        $mform->addElement('advcheckbox', 'allowdownload', get_string('allowdownload', 'mod_saylorcode'));
        $mform->setDefault('allowdownload', 1);

        // Grading.
        $mform->addElement('header', 'gradingheader', get_string('grade'));

        $gradingmodes = [
            'none' => get_string('gradingmodenone', 'mod_saylorcode'),
            'completion' => get_string('gradingmodecompletion', 'mod_saylorcode'),
            'tests' => get_string('gradingmodetests', 'mod_saylorcode'),
            'manual' => get_string('gradingmodemanual', 'mod_saylorcode'),
            'mixed' => get_string('gradingmodemixed', 'mod_saylorcode'),
        ];
        $mform->addElement('select', 'gradingmode', get_string('gradingmode', 'mod_saylorcode'), $gradingmodes);
        $mform->addHelpButton('gradingmode', 'gradingmode', 'mod_saylorcode');
        $mform->setDefault('gradingmode', 'tests');

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Add the custom completion rules.
     *
     * @return string[] Names of the added elements.
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $mform->addElement(
            'advcheckbox',
            'completionpasstests',
            get_string('completionpasstests', 'mod_saylorcode'),
            get_string('completionpasstests', 'mod_saylorcode')
        );

        $mform->addElement(
            'text',
            'completionminscore',
            get_string('completionminscore', 'mod_saylorcode'),
            ['size' => 3]
        );
        $mform->setType('completionminscore', PARAM_INT);
        $mform->setDefault('completionminscore', 0);

        return ['completionpasstests', 'completionminscore'];
    }

    /**
     * Whether the author enabled any custom completion rule.
     *
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data['completionpasstests']) || ((int) ($data['completionminscore'] ?? 0) > 0);
    }

    /**
     * Server side validation.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // A playground has no predefined exercise, so a stable id is only
        // required for the modes that present one.
        if (($data['activitymode'] ?? '') !== 'playground') {
            $stableid = trim((string) ($data['stableid'] ?? ''));
            if ($stableid === '' || !stable_id::is_valid($stableid)) {
                $errors['stableid'] = get_string('stableidinvalid', 'mod_saylorcode');
            }
        }

        $minscore = (int) ($data['completionminscore'] ?? 0);
        if ($minscore < 0 || $minscore > 100) {
            $errors['completionminscore'] = get_string('completionminscore', 'mod_saylorcode');
        }

        return $errors;
    }
}
