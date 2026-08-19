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

namespace mod_saylorcode\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_saylorcode\local\exercise_validator;

/**
 * Web service behind the Validate button on the activity form.
 *
 * Takes the form's current contents rather than what is stored, so an author
 * can check their work before saving, and while creating an activity that does
 * not exist yet. That is why this is scoped to a course rather than to a course
 * module.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validate_exercise extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course the exercise is being authored in'),
            'profileid' => new external_value(PARAM_ALPHANUMEXT, 'Runtime profile id'),
            'entryfilename' => new external_value(PARAM_FILE, 'File the solution lives in'),
            'referencesolution' => new external_value(PARAM_RAW, 'Reference solution source'),
            'testcases' => new external_value(PARAM_RAW, 'JSON encoded test cases'),
        ]);
    }

    /**
     * Run the reference solution against the test cases.
     *
     * @param int $courseid Course id.
     * @param string $profileid Runtime profile id.
     * @param string $entryfilename Entry filename.
     * @param string $referencesolution Reference solution source.
     * @param string $testcases JSON encoded test cases.
     * @return array
     */
    public static function execute(
        int $courseid,
        string $profileid,
        string $entryfilename,
        string $referencesolution,
        string $testcases
    ): array {
        [
            'courseid' => $courseid,
            'profileid' => $profileid,
            'entryfilename' => $entryfilename,
            'referencesolution' => $referencesolution,
            'testcases' => $testcases,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'profileid' => $profileid,
            'entryfilename' => $entryfilename,
            'referencesolution' => $referencesolution,
            'testcases' => $testcases,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Running arbitrary source on the runner is an authoring action, so it
        // is gated on being allowed to create one of these activities rather
        // than on being enrolled.
        require_capability('mod/saylorcode:addinstance', $context);

        $cases = json_decode($testcases, true);
        if (!is_array($cases)) {
            $cases = [];
        }

        $report = (new exercise_validator())->validate(
            $profileid,
            $entryfilename !== '' ? $entryfilename : 'Main.java',
            $referencesolution,
            $cases
        );

        return $report;
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'valid' => new external_value(PARAM_BOOL, 'Whether the solution satisfied every case'),
            'summary' => new external_value(PARAM_TEXT, 'A sentence the author can act on'),
            'compileroutput' => new external_value(PARAM_RAW, 'Sanitised compiler output, when relevant'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Case name'),
                    'passed' => new external_value(PARAM_BOOL, 'Whether the reference satisfied it'),
                    'ispublic' => new external_value(PARAM_BOOL, 'Whether students see this case'),
                    'expected' => new external_value(PARAM_RAW, 'Expected output'),
                    'actual' => new external_value(PARAM_RAW, 'Output the reference produced'),
                    'state' => new external_value(PARAM_ALPHAEXT, 'Execution state'),
                ]),
                'Per case results'
            ),
        ]);
    }
}
