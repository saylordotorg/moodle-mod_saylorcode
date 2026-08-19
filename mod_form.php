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

        // Pinning had nowhere to point until the exercise library existed, so
        // the policy could be chosen and the version could not. A graded
        // activity needs to hold one the way a graded step now can.
        $mform->addElement('text', 'pinnedversion', get_string('pinnedversion', 'mod_saylorcode'), ['size' => 6]);
        $mform->setType('pinnedversion', PARAM_INT);
        $mform->addHelpButton('pinnedversion', 'pinnedversion', 'mod_saylorcode');
        $mform->hideIf('pinnedversion', 'versionpolicy', 'neq', 'pinned');
        $mform->hideIf('pinnedversion', 'activitymode', 'eq', 'playground');

        $profiles = (new profile_manager())->get_menu();
        $mform->addElement('select', 'profileid', get_string('profileid', 'mod_saylorcode'), $profiles);
        $mform->addHelpButton('profileid', 'profileid', 'mod_saylorcode');

        $layouts = [
            'split' => get_string('layoutsplit', 'mod_saylorcode'),
            'drawer' => get_string('layoutdrawer', 'mod_saylorcode'),
            'tabs' => get_string('layouttabs', 'mod_saylorcode'),
        ];
        $mform->addElement('select', 'layout', get_string('layout', 'mod_saylorcode'), $layouts);
        $mform->addHelpButton('layout', 'layout', 'mod_saylorcode');
        $mform->setDefault('layout', 'split');

        $mform->addElement('text', 'entryfilename', get_string('entryfilename', 'mod_saylorcode'), ['size' => '40']);
        $mform->setType('entryfilename', PARAM_FILE);
        $mform->addHelpButton('entryfilename', 'entryfilename', 'mod_saylorcode');
        $mform->setDefault('entryfilename', 'Main.java');

        // Starter code and test cases live on the activity until the central
        // library can supply them. Keeping them here means CS101 is not blocked
        // on the library landing.
        $mform->addElement(
            'textarea',
            'startercode',
            get_string('startercode', 'mod_saylorcode'),
            ['rows' => 12, 'cols' => 80, 'spellcheck' => 'false', 'class' => 'saylorcode-codearea']
        );
        $mform->setType('startercode', PARAM_RAW);
        $mform->addHelpButton('startercode', 'startercode', 'mod_saylorcode');

        $mform->addElement(
            'textarea',
            'referencesolution',
            get_string('referencesolution', 'mod_saylorcode'),
            ['rows' => 10, 'cols' => 80, 'spellcheck' => 'false', 'class' => 'saylorcode-codearea']
        );
        $mform->setType('referencesolution', PARAM_RAW);
        $mform->addHelpButton('referencesolution', 'referencesolution', 'mod_saylorcode');
        $mform->hideIf('referencesolution', 'activitymode', 'eq', 'playground');

        // Test cases, as rows rather than as JSON. The stored column is still
        // JSON, so nothing about the data model changes and existing
        // activities keep working; only the way an author edits them does.
        $mform->addElement('header', 'testcasesheader', get_string('testcases', 'mod_saylorcode'));
        $mform->addElement('static', 'testcasesintro', '', get_string('testcases_help', 'mod_saylorcode'));

        $repeated = [
            $mform->createElement(
                'text',
                'tcname',
                get_string('tcname', 'mod_saylorcode'),
                ['size' => 45]
            ),
            $mform->createElement(
                'textarea',
                'tcstdin',
                get_string('tcstdin', 'mod_saylorcode'),
                ['rows' => 2, 'cols' => 45, 'spellcheck' => 'false']
            ),
            $mform->createElement(
                'textarea',
                'tcexpected',
                get_string('tcexpected', 'mod_saylorcode'),
                ['rows' => 3, 'cols' => 45, 'spellcheck' => 'false']
            ),
            $mform->createElement(
                'text',
                'tcfeedback',
                get_string('tcfeedback', 'mod_saylorcode'),
                ['size' => 60]
            ),
            $mform->createElement('advcheckbox', 'tcpublic', get_string('tcpublic', 'mod_saylorcode')),
            $mform->createElement('text', 'tcweight', get_string('tcweight', 'mod_saylorcode'), ['size' => 4]),
        ];

        $repeatoptions = [
            'tcname' => ['type' => PARAM_TEXT, 'helpbutton' => ['tcname', 'mod_saylorcode']],
            'tcstdin' => ['type' => PARAM_RAW],
            'tcexpected' => ['type' => PARAM_RAW],
            'tcfeedback' => ['type' => PARAM_TEXT, 'helpbutton' => ['tcfeedback', 'mod_saylorcode']],
            'tcpublic' => ['type' => PARAM_BOOL, 'default' => 1, 'helpbutton' => ['tcpublic', 'mod_saylorcode']],
            'tcweight' => ['type' => PARAM_FLOAT, 'default' => 1],
        ];

        $existing = $this->count_existing_cases();

        $this->repeat_elements(
            $repeated,
            max(1, $existing),
            $repeatoptions,
            'testcaserepeats',
            'testcaseadd',
            2,
            get_string('addtestcases', 'mod_saylorcode'),
            true
        );

        // Validate. Deliberately a button rather than a save time check, so an
        // author can iterate on a case and see the answer immediately instead
        // of discovering at submit that the exercise was never runnable.
        $mform->addElement(
            'static',
            'validatecontrol',
            get_string('validate', 'mod_saylorcode'),
            \html_writer::div(
                \html_writer::tag(
                    'button',
                    get_string('validaterun', 'mod_saylorcode'),
                    [
                        'type' => 'button',
                        'class' => 'btn btn-secondary',
                        'data-action' => 'saylorcode-validate',
                    ]
                ) .
                \html_writer::div('', 'saylorcode-validate-result mt-2', ['data-region' => 'validate-result']),
                'saylorcode-validate'
            )
        );
        $mform->addHelpButton('validatecontrol', 'validate', 'mod_saylorcode');

        global $PAGE;
        $PAGE->requires->js_call_amd('mod_saylorcode/authoring', 'init');

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

        // Hints are given one at a time in this order, so the first should be
        // the smallest nudge that could help and the last the largest.
        $hintelements = [
            $mform->createElement(
                'textarea',
                'hinttext',
                get_string('hint', 'mod_saylorcode'),
                ['rows' => 2, 'cols' => 60]
            ),
        ];

        $this->repeat_elements(
            $hintelements,
            max(1, $this->count_existing_hints()),
            ['hinttext' => ['type' => PARAM_TEXT, 'helpbutton' => ['hint', 'mod_saylorcode']]],
            'hintrepeats',
            'hintadd',
            2,
            get_string('addhints', 'mod_saylorcode'),
            true
        );

        $mform->hideIf('hintrepeats', 'allowhints', 'notchecked');
        $mform->hideIf('hintadd', 'allowhints', 'notchecked');

        $mform->addElement('advcheckbox', 'allowsolution', get_string('allowsolution', 'mod_saylorcode'));
        $mform->addHelpButton('allowsolution', 'allowsolution', 'mod_saylorcode');
        $mform->setDefault('allowsolution', 0);

        $mform->addElement('advcheckbox', 'allowdownload', get_string('allowdownload', 'mod_saylorcode'));
        $mform->setDefault('allowdownload', 1);

        // Grading.
        $mform->addElement('header', 'gradingheader', get_string('gradenoun'));

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
     * How many test cases the activity being edited already has.
     *
     * @return int
     */
    protected function count_existing_cases(): int {
        $current = $this->current ?? null;
        if (empty($current->testcases)) {
            return 0;
        }

        $decoded = json_decode((string) $current->testcases, true);

        return is_array($decoded) ? count($decoded) : 0;
    }

    /**
     * How many hints the activity already has.
     *
     * @return int
     */
    protected function count_existing_hints(): int {
        $current = $this->current ?? null;
        if (empty($current->hints)) {
            return 0;
        }

        $decoded = json_decode((string) $current->hints, true);

        return is_array($decoded) ? count($decoded) : 0;
    }

    /**
     * Spread the stored JSON across the repeated form rows.
     *
     * @param array $defaultvalues The values being loaded into the form.
     */
    public function data_preprocessing(&$defaultvalues): void {
        parent::data_preprocessing($defaultvalues);

        $hints = json_decode((string) ($defaultvalues['hints'] ?? ''), true);
        if (is_array($hints)) {
            foreach (array_values($hints) as $i => $hint) {
                $defaultvalues['hinttext[' . $i . ']'] = (string) ($hint['text'] ?? '');
            }
        }

        $cases = json_decode((string) ($defaultvalues['testcases'] ?? ''), true);
        if (!is_array($cases)) {
            return;
        }

        foreach (array_values($cases) as $i => $case) {
            $defaultvalues['tcname[' . $i . ']'] = (string) ($case['name'] ?? '');
            $defaultvalues['tcstdin[' . $i . ']'] = (string) ($case['stdin'] ?? '');
            $defaultvalues['tcexpected[' . $i . ']'] = (string) ($case['expected'] ?? '');
            $defaultvalues['tcfeedback[' . $i . ']'] = (string) ($case['feedback'] ?? '');
            $defaultvalues['tcpublic[' . $i . ']'] = !empty($case['ispublic']) ? 1 : 0;
            $defaultvalues['tcweight[' . $i . ']'] = (float) ($case['weight'] ?? 1);
        }
    }

    /**
     * Gather the rows back into the stored JSON.
     *
     * A row with no expected output is dropped rather than saved, because the
     * repeat control always renders spare rows and an author should not have to
     * clear them by hand.
     *
     * @return stdClass|null The submitted data.
     */
    public function get_data() {
        $data = parent::get_data();

        if (!$data) {
            return $data;
        }

        $cases = self::rows_to_cases($data);

        // Store nothing rather than "[]" for an empty list. The string "[]" is
        // not empty, so anything testing the column for emptiness would decide
        // the activity has tests and offer Check with nothing to check.
        $data->testcases = $cases ? json_encode($cases) : '';

        $hints = self::rows_to_hints($data);
        $data->hints = $hints ? json_encode($hints) : '';

        // Same reasoning for the reference solution: a value of pure
        // whitespace is not a solution, and storing it as one makes the
        // catalogue call an unfinished exercise ready.
        if (isset($data->referencesolution) && trim((string) $data->referencesolution) === '') {
            $data->referencesolution = '';
        }

        return $data;
    }

    /**
     * Turn submitted rows into the stored hint list.
     *
     * @param stdClass|array $data Submitted form data.
     * @return array
     */
    public static function rows_to_hints($data): array {
        $texts = (array) ($data->hinttext ?? []);
        $hints = [];

        foreach ($texts as $text) {
            $text = trim((string) $text);

            // Blank rows are how an author leaves room for one more, so they
            // are dropped rather than stored as an empty hint that would be
            // handed to a student as help.
            if ($text === '') {
                continue;
            }

            $hints[] = ['text' => $text];
        }

        return $hints;
    }

    /**
     * Turn submitted rows into test case records.
     *
     * @param stdClass|array $data Submitted form data.
     * @return array
     */
    public static function rows_to_cases($data): array {
        $data = (array) $data;
        $names = $data['tcname'] ?? [];
        $expected = $data['tcexpected'] ?? [];

        $cases = [];
        foreach (array_keys((array) $expected) as $i) {
            $out = (string) ($expected[$i] ?? '');
            $name = trim((string) ($names[$i] ?? ''));

            // An untouched spare row has nothing to say.
            if (trim($out) === '' && $name === '') {
                continue;
            }

            $cases[] = [
                'id' => 'T' . (count($cases) + 1),
                'name' => $name !== '' ? $name : get_string('tcdefaultname', 'mod_saylorcode', count($cases) + 1),
                'stdin' => (string) ($data['tcstdin'][$i] ?? ''),
                'expected' => $out,
                'ispublic' => !empty($data['tcpublic'][$i]),
                'weight' => (float) ($data['tcweight'][$i] ?? 1),
                'feedback' => (string) ($data['tcfeedback'][$i] ?? ''),
            ];
        }

        return $cases;
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

        // A pinned activity with no version resolves as a broken pin and falls
        // back to its own content without telling anybody, so it is refused
        // while the author can still see why. Not applied to a playground,
        // which has no exercise to pin.
        if (
            ($data['activitymode'] ?? '') !== 'playground'
                && ($data['versionpolicy'] ?? '') === 'pinned'
                && (int) ($data['pinnedversion'] ?? 0) < 1
        ) {
            $errors['pinnedversion'] = get_string('pinnedversionrequired', 'mod_saylorcode');
        }

        $minscore = (int) ($data['completionminscore'] ?? 0);
        if ($minscore < 0 || $minscore > 100) {
            $errors['completionminscore'] = get_string('completionminscore', 'mod_saylorcode');
        }

        // A row is only meaningful if it says what it expects. A weight of
        // zero would silently remove a case from the score, which is almost
        // never what an author means.
        foreach ((array) ($data['tcexpected'] ?? []) as $i => $expected) {
            $named = trim((string) ($data['tcname'][$i] ?? '')) !== '';
            $hasexpected = trim((string) $expected) !== '';

            // Active means the same thing here as it does in rows_to_cases():
            // a row with either a name or expected output is saved, so a row
            // with only expected output must be validated too.
            if (!$named && !$hasexpected) {
                continue;
            }

            if (!$hasexpected) {
                $errors['tcexpected[' . $i . ']'] = get_string('tcexpectedrequired', 'mod_saylorcode');
            }

            if ((float) ($data['tcweight'][$i] ?? 1) <= 0) {
                $errors['tcweight[' . $i . ']'] = get_string('tcweightpositive', 'mod_saylorcode');
            }
        }

        return $errors;
    }
}
