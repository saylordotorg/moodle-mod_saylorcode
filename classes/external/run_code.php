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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_saylorcode\local\runner\execution_request;
use mod_saylorcode\local\attempt_manager;
use mod_saylorcode\local\execution_service;
use mod_saylorcode\local\workspace_context;

/**
 * Web service that runs, checks or submits student code.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_code extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'mode' => new external_value(PARAM_ALPHA, 'One of run, check or submit'),
            'files' => new external_value(PARAM_RAW, 'JSON object of relative path to file contents'),
            'stdin' => new external_value(PARAM_RAW, 'Standard input for a plain run', VALUE_DEFAULT, ''),
            'browsersession' => new external_value(PARAM_ALPHANUMEXT, 'Identifier of the writing browser session', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Run, check or submit.
     *
     * @param int $cmid Course module id.
     * @param string $mode run, check or submit.
     * @param string $files JSON file map.
     * @param string $stdin Standard input.
     * @param string $browsersession Writing browser session.
     * @return array
     */
    public static function execute(
        int $cmid,
        string $mode,
        string $files,
        string $stdin = '',
        string $browsersession = ''
    ): array {
        [
            'cmid' => $cmid,
            'mode' => $mode,
            'files' => $files,
            'stdin' => $stdin,
            'browsersession' => $browsersession,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'mode' => $mode,
            'files' => $files,
            'stdin' => $stdin,
            'browsersession' => $browsersession,
        ]);

        global $USER;

        // Validation mode belongs to authoring, not to a student action, so it
        // is deliberately not reachable from this endpoint.
        $allowed = [
            execution_request::MODE_RUN,
            execution_request::MODE_CHECK,
            execution_request::MODE_SUBMIT,
        ];
        if (!in_array($mode, $allowed, true)) {
            throw new \invalid_parameter_exception('Unsupported execution mode: ' . $mode);
        }

        $workspace = workspace_context::resolve($cmid);
        self::validate_context($workspace->context);

        $decoded = workspace_context::decode_files($files);

        $manager = new attempt_manager($workspace->instance);
        $attempt = $manager->get_or_create_attempt((int) $USER->id);

        if ($mode === execution_request::MODE_SUBMIT) {
            self::guard_attempt_limit($workspace->instance, $attempt);
        }

        $session = $browsersession !== '' ? $browsersession : null;

        // Every action saves first, so what runs is always what is stored.
        $snapshottype = [
            execution_request::MODE_RUN => attempt_manager::SNAPSHOT_RUN,
            execution_request::MODE_CHECK => attempt_manager::SNAPSHOT_CHECK,
            execution_request::MODE_SUBMIT => attempt_manager::SNAPSHOT_SUBMIT,
        ][$mode];
        $manager->save_snapshot($attempt, $decoded, $snapshottype, $session);

        $service = new execution_service($workspace->instance, $workspace->cm);
        $result = $service->execute($attempt, $decoded, $mode, $stdin);

        \mod_saylorcode\event\code_executed::create_from_attempt(
            $workspace->context,
            $attempt,
            $mode,
            $result['state']
        )->trigger();

        return [
            'state' => $result['state'],
            'message' => $result['message'],
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'compileroutput' => $result['compileroutput'],
            'truncated' => $result['truncated'],
            'runtime' => $result['runtime'],
            'score' => $result['score'],
            'tests' => array_map(static function (array $test): array {
                return [
                    'name' => $test['name'],
                    'passed' => $test['passed'],
                    'ispublic' => $test['ispublic'],
                    'feedback' => $test['feedback'],
                    'expected' => $test['expected'],
                    'actual' => $test['actual'],
                ];
            }, $result['tests']),
        ];
    }

    /**
     * Refuse a submission once the configured attempt limit is reached.
     *
     * @param \stdClass $instance The activity instance.
     * @param \stdClass $attempt The attempt.
     * @throws \moodle_exception If no submissions remain.
     */
    protected static function guard_attempt_limit(\stdClass $instance, \stdClass $attempt): void {
        global $DB;

        $max = (int) $instance->maxattempts;
        if ($max <= 0) {
            return;
        }

        $used = $DB->count_records('saylorcode_executions', [
            'attemptid' => $attempt->id,
            'mode' => execution_request::MODE_SUBMIT,
        ]);

        if ($used >= $max) {
            throw new \moodle_exception('nomoreattempts', 'mod_saylorcode');
        }
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'state' => new external_value(PARAM_ALPHAEXT, 'Canonical execution state'),
            'message' => new external_value(PARAM_TEXT, 'Plain language description of the state'),
            'stdout' => new external_value(PARAM_RAW, 'Program output'),
            'stderr' => new external_value(PARAM_RAW, 'Sanitised error output'),
            'compileroutput' => new external_value(PARAM_RAW, 'Sanitised compiler output'),
            'truncated' => new external_value(PARAM_BOOL, 'Whether output was shortened'),
            'runtime' => new external_value(PARAM_FLOAT, 'Seconds spent executing'),
            'score' => new external_value(PARAM_FLOAT, 'Percentage scored, for a submission', VALUE_OPTIONAL),
            'tests' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Test name, or a placeholder for a hidden test'),
                    'passed' => new external_value(PARAM_BOOL, 'Whether it passed'),
                    'ispublic' => new external_value(PARAM_BOOL, 'Whether details may be shown'),
                    'feedback' => new external_value(PARAM_RAW, 'Author feedback, empty for a hidden test'),
                    'expected' => new external_value(PARAM_RAW, 'Expected output, empty for a hidden test'),
                    'actual' => new external_value(PARAM_RAW, 'Produced output, empty for a hidden test'),
                ]),
                'Test results, with hidden tests reduced to an outcome'
            ),
        ]);
    }
}
