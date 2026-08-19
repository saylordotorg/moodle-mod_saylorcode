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

namespace mod_saylorcode\local;

use cm_info;
use local_saylorcode\local\runner\execution_gate;
use local_saylorcode\local\runner\execution_request;
use local_saylorcode\local\runner\execution_response;
use local_saylorcode\local\runner\execution_state;
use local_saylorcode\local\runner\jobe_provider;
use local_saylorcode\local\runner\provider_interface;
use local_saylorcode\local\runner\test_result;
use stdClass;

/**
 * Runs student code and turns the result into something a course can use.
 *
 * The three student actions differ in consequence, not mechanism, and this
 * class is where that difference lives (specification section 9.4):
 *
 * - Run executes the program and grades nothing.
 * - Check evaluates the public tests and returns teaching feedback.
 * - Submit evaluates every test, records an official attempt and updates the
 *   gradebook and completion.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execution_service {
    /** @var stdClass The activity instance. */
    protected stdClass $instance;

    /** @var stdClass|null The guided lesson step being judged, where there is one. */
    protected ?stdClass $step = null;

    /** @var cm_info|stdClass The course module. */
    protected $cm;

    /** @var provider_interface The execution backend. */
    protected provider_interface $provider;

    /**
     * Build the service.
     *
     * @param stdClass $instance The activity instance.
     * @param cm_info|stdClass $cm The course module.
     * @param provider_interface|null $provider Backend, defaulting to the configured one.
     * @param stdClass|null $step The guided lesson step being judged, where there is one.
     */
    public function __construct(
        stdClass $instance,
        $cm,
        ?provider_interface $provider = null,
        ?stdClass $step = null
    ) {
        $this->instance = $instance;
        $this->cm = $cm;
        $this->provider = $provider ?? jobe_provider::create_from_config();
        $this->step = $step;
    }

    /**
     * Execute one of the student actions.
     *
     * @param stdClass $attempt The attempt.
     * @param array $files Relative path => contents.
     * @param string $mode One of the execution_request MODE_* constants.
     * @param string $stdin Standard input for a plain run.
     * @return array A payload safe to return to the browser.
     */
    public function execute(stdClass $attempt, array $files, string $mode, string $stdin = ''): array {
        $gate = new execution_gate((int) $attempt->userid);
        $lease = $gate->acquire();

        if ($lease === null) {
            return [
                'state' => execution_state::RUNNER_UNAVAILABLE,
                'stdout' => '',
                'stderr' => '',
                'compileroutput' => '',
                'tests' => [],
                'truncated' => false,
                'runtime' => 0,
                'diagnostic' => 'rate_limited',
                'message' => get_string('ratelimited', 'mod_saylorcode'),
                'score' => null,
            ];
        }

        try {
            if ($mode === execution_request::MODE_RUN) {
                $response = $this->run_once($files, $stdin);
            } else {
                $response = $this->run_tests($files, $mode);
            }

            $this->record_execution($attempt, $mode, $response);

            $payload = $response->export_for_student();
            $payload['message'] = $this->describe_state($response->get_state());
            $payload['score'] = null;

            if ($mode === execution_request::MODE_SUBMIT) {
                $fraction = $response->get_score_fraction();
                $payload['score'] = round($fraction * 100, 1);
                $this->finalise_submission($attempt, $fraction, $response);
            }

            return $payload;
        } finally {
            // Released even when the provider throws, so a failure cannot leave
            // the student locked out of their own limit.
            $gate->release($lease);
        }
    }

    /**
     * Execute the program once, without evaluating anything.
     *
     * @param array $files Relative path => contents.
     * @param string $stdin Standard input.
     * @return execution_response
     */
    protected function run_once(array $files, string $stdin): execution_response {
        $request = new execution_request(
            $this->new_request_id(),
            $this->instance->profileid,
            execution_request::MODE_RUN,
            $files,
            $stdin
        );

        return $this->provider->execute($request);
    }

    /**
     * Evaluate the activity's test cases.
     *
     * Each case is a separate execution, because a console program reads its
     * input once. A Check sees only the public cases; a Submit sees all of
     * them, and the hidden ones still contribute to the score without ever
     * being described to the student.
     *
     * @param array $files Relative path => contents.
     * @param string $mode MODE_CHECK or MODE_SUBMIT.
     * @return execution_response
     */
    protected function run_tests(array $files, string $mode): execution_response {
        $cases = $this->get_test_cases();
        $ispublic = $mode === execution_request::MODE_CHECK;

        if ($ispublic) {
            $cases = array_filter($cases, static function (array $case): bool {
                return !empty($case['ispublic']);
            });
        }

        if (empty($cases)) {
            // Nothing to evaluate. Reported as an ordinary run rather than a
            // pass, so a misconfigured activity cannot award credit.
            return $this->run_once($files, '');
        }

        $results = [];
        $laststate = execution_state::COMPLETED;
        $compileroutput = '';
        $totalruntime = 0.0;

        foreach ($cases as $case) {
            $request = new execution_request(
                $this->new_request_id(),
                $this->instance->profileid,
                $mode,
                $files,
                (string) ($case['stdin'] ?? '')
            );

            $response = $this->provider->execute($request);
            $totalruntime += $response->get_runtime();

            // A compile error is a property of the code, not of one case, so
            // it stops the whole evaluation rather than repeating per case.
            if ($response->get_state() === execution_state::COMPILE_ERROR) {
                return $response;
            }

            if (execution_state::is_platform_failure($response->get_state())) {
                return $response;
            }

            $passed = $response->get_state() === execution_state::COMPLETED
                && $this->output_matches($response->export_for_student()['stdout'], (string) ($case['expected'] ?? ''));

            if (!$passed) {
                $laststate = execution_state::FAILED_TESTS;
            }

            $results[] = new test_result(
                (string) ($case['id'] ?? ''),
                (string) ($case['name'] ?? ''),
                $passed,
                !empty($case['ispublic']),
                (float) ($case['weight'] ?? 1.0),
                $passed ? '' : (string) ($case['feedback'] ?? ''),
                $passed ? '' : $response->export_for_student()['stdout'],
                $passed ? '' : (string) ($case['expected'] ?? '')
            );
        }

        return new execution_response(
            $this->new_request_id(),
            $laststate,
            '',
            '',
            $compileroutput,
            $results,
            null,
            0.0,
            $totalruntime
        );
    }

    /**
     * Compare produced output with what a case expects.
     *
     * Delegates to the shared comparator so that the student path and the
     * author's Validate button can never disagree about whether an output
     * matches.
     *
     * @param string $actual Output the program produced.
     * @param string $expected Output the case expects.
     * @return bool
     */
    protected function output_matches(string $actual, string $expected): bool {
        return output_comparator::matches($actual, $expected);
    }

    /**
     * The activity's test cases.
     *
     * @return array
     */
    public function get_test_cases(): array {
        // Resolved, so a student is checked against the same version of the
        // exercise their starter code came from. Reading these off the instance
        // while the code came from the library would grade one thing against
        // another.
        //
        // A step with its own reference is judged on that exercise, not the
        // activity's: otherwise passing the activity's tests would unlock a
        // step it says nothing about, and a step with tests would stay locked
        // for ever on an activity that has none.
        $cases = $this->step !== null
            ? content::for_step($this->instance, $this->step)->get_test_cases()
            : content::for_instance($this->instance)->get_test_cases();

        if ($cases) {
            return $cases;
        }

        $raw = (string) ($this->instance->testcases ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Record grading, completion and the submitted snapshot.
     *
     * @param stdClass $attempt The attempt.
     * @param float $fraction Score between 0 and 1.
     * @param execution_response $response The assessed result.
     */
    protected function finalise_submission(stdClass $attempt, float $fraction, execution_response $response): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/saylorcode/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $manager = new attempt_manager($this->instance);
        $manager->record_submission($attempt, $fraction);

        if (($this->instance->gradingmode ?? '') !== 'none') {
            saylorcode_update_grades($this->instance, (int) $attempt->userid);
        }

        $course = get_course($this->cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($this->cm)) {
            $completion->update_state($this->cm, COMPLETION_UNKNOWN, (int) $attempt->userid);
        }
    }

    /**
     * Write the sanitised execution record.
     *
     * Deliberately holds no source code. The code lives with the attempt as a
     * snapshot; this table is operational telemetry.
     *
     * @param stdClass $attempt The attempt.
     * @param string $mode The execution mode.
     * @param execution_response $response The result.
     */
    protected function record_execution(stdClass $attempt, string $mode, execution_response $response): void {
        global $DB;

        $passed = 0;
        $total = 0;
        foreach ($response->get_test_results() as $result) {
            $total++;
            if ($result->has_passed()) {
                $passed++;
            }
        }

        $executionid = $DB->insert_record('saylorcode_executions', (object) [
            'requestid' => $response->get_request_id(),
            'attemptid' => $attempt->id,
            'snapshotid' => null,
            'mode' => $mode,
            'profileid' => $this->instance->profileid,
            'state' => $response->get_state(),
            'queuetime' => $response->get_queue_time(),
            'runtime' => $response->get_runtime(),
            'truncated' => $response->was_truncated() ? 1 : 0,
            'diagnostic' => $response->get_diagnostic(),
            'testspassed' => $passed,
            'teststotal' => $total,
            'timecreated' => time(),
        ]);

        $this->record_test_results($executionid, $response);
    }

    /**
     * A plain language description of an execution state.
     *
     * @param string $state One of the execution_state constants.
     * @return string
     */
    protected function describe_state(string $state): string {
        $map = [
            execution_state::COMPILE_ERROR => 'errorcompile',
            execution_state::RUNTIME_ERROR => 'errorruntime',
            execution_state::TIMEOUT => 'errortimeout',
            execution_state::MEMORY_LIMIT => 'errormemorylimit',
            execution_state::OUTPUT_LIMIT => 'erroroutputlimit',
            execution_state::PROCESS_LIMIT => 'errorprocesslimit',
            execution_state::RUNNER_UNAVAILABLE => 'runnerunavailable',
            execution_state::INTERNAL_ERROR => 'runnerunavailable',
        ];

        if (isset($map[$state])) {
            return get_string($map[$state], 'mod_saylorcode');
        }

        return '';
    }

    /**
     * A fresh opaque request id.
     *
     * Random rather than derived from a user or attempt id, so nothing about
     * the student travels to the runner with it.
     *
     * @return string
     */
    protected function new_request_id(): string {
        return bin2hex(random_bytes(16));
    }
    /**
     * Record which cases an execution passed.
     *
     * Only the author's case name and the outcome. No student code, no
     * expected value and no actual output, so this stays telemetry rather than
     * becoming a second copy of the student's work, and a hidden case is
     * recorded by name for the teacher without that name ever reaching a
     * browser.
     *
     * A report that says which test students fail most is the difference
     * between knowing a class is struggling and knowing what they are
     * struggling with, and it cannot be reconstructed from a passed-out-of-
     * total count after the fact.
     *
     * @param int $executionid The execution these belong to.
     * @param execution_response $response The runner's response.
     */
    protected function record_test_results(int $executionid, execution_response $response): void {
        global $DB;

        $rows = [];

        foreach ($response->get_test_results() as $result) {
            $rows[] = (object) [
                'executionid' => $executionid,
                'saylorcodeid' => $this->instance->id,
                // The identity a report groups on. The name is a label: two
                // steps may legitimately use the same one.
                'caseid' => \core_text::substr($result->get_test_id(), 0, 64),
                'stepid' => $this->step->id ?? null,
                'testname' => \core_text::substr($result->get_name(), 0, 255),
                'passed' => $result->has_passed() ? 1 : 0,
                'ispublic' => $result->is_public() ? 1 : 0,
                'timecreated' => time(),
            ];
        }

        if ($rows) {
            $DB->insert_records('saylorcode_testresults', $rows);
        }
    }
}
