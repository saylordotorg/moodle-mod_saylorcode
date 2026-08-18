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

use local_saylorcode\local\runner\execution_request;
use local_saylorcode\local\runner\execution_state;
use mod_saylorcode\tests\fixtures\scripted_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/scripted_provider.php');

/**
 * Tests for running, checking and submitting.
 *
 * A scripted provider stands in for the sandbox, so the behaviour under test is
 * the grading and disclosure logic rather than the runner.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\execution_service
 */
final class execution_service_test extends \advanced_testcase {
    /** @var string Test cases used across these tests. Two public, one hidden. */
    private const CASES = '[
        {"id":"T1","name":"Doubles four","stdin":"4\n","expected":"8\n",
         "ispublic":true,"weight":1,"feedback":"Check the arithmetic."},
        {"id":"T2","name":"Doubles zero","stdin":"0\n","expected":"0\n",
         "ispublic":true,"weight":1,"feedback":"Zero is still zero."},
        {"id":"T3","name":"Secret negative case","stdin":"-7\n","expected":"-14\n",
         "ispublic":false,"weight":2,"feedback":"Negatives double too."}
    ]';

    /**
     * Build an activity, an attempt and a service wired to a scripted provider.
     *
     * @param array $outputs Map of stdin to stdout.
     * @param string $state State the provider should report.
     * @return array The instance, cm, attempt and service.
     */
    private function build_fixture(array $outputs, string $state = execution_state::COMPLETED): array {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $course->id,
            'testcases' => self::CASES,
        ]);

        $cm = get_coursemodule_from_instance('saylorcode', $instance->id);

        $manager = new attempt_manager($instance);
        $attempt = $manager->get_or_create_attempt((int) $student->id);

        $provider = new scripted_provider($outputs, $state);
        $service = new execution_service($instance, $cm, $provider);

        return [$instance, $cm, $attempt, $service, $provider, $student];
    }

    /**
     * A correct solution passes every case and scores full marks on submit.
     */
    public function test_submit_scores_all_tests(): void {
        [, , $attempt, $service] = $this->build_fixture([
            "4\n" => "8\n",
            "0\n" => "0\n",
            "-7\n" => "-14\n",
        ]);

        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_SUBMIT);

        $this->assertSame(execution_state::COMPLETED, $result['state']);
        $this->assertEqualsWithDelta(100.0, $result['score'], 0.01);
        $this->assertCount(3, $result['tests']);
    }

    /**
     * A check evaluates only the public cases.
     */
    public function test_check_runs_only_public_cases(): void {
        [, , $attempt, $service, $provider] = $this->build_fixture([
            "4\n" => "8\n",
            "0\n" => "0\n",
            "-7\n" => "-14\n",
        ]);

        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_CHECK);

        $this->assertCount(2, $result['tests']);
        $this->assertCount(2, $provider->requests);
    }

    /**
     * A check must not disclose a hidden case, even indirectly.
     */
    public function test_check_never_discloses_hidden_cases(): void {
        [, , $attempt, $service] = $this->build_fixture([
            "4\n" => "8\n",
            "0\n" => "0\n",
        ]);

        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_CHECK);
        $serialised = json_encode($result);

        $this->assertStringNotContainsString('Secret negative case', $serialised);
        $this->assertStringNotContainsString('Negatives double too', $serialised);
        $this->assertStringNotContainsString('-14', $serialised);
    }

    /**
     * A submit counts hidden cases towards the score but still hides them.
     */
    public function test_submit_counts_hidden_cases_without_naming_them(): void {
        // The negative case fails; the two public ones pass.
        [, , $attempt, $service] = $this->build_fixture([
            "4\n" => "8\n",
            "0\n" => "0\n",
            "-7\n" => "-21\n",
        ]);

        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_SUBMIT);
        $serialised = json_encode($result);

        // Weights are 1, 1 and 2, so two of four points are earned.
        $this->assertEqualsWithDelta(50.0, $result['score'], 0.01);
        $this->assertSame(execution_state::FAILED_TESTS, $result['state']);
        $this->assertStringNotContainsString('Secret negative case', $serialised);
    }

    /**
     * A run grades nothing, whatever the code does.
     */
    public function test_run_does_not_grade(): void {
        [$instance, , $attempt, $service] = $this->build_fixture(["" => "hello\n"]);

        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_RUN);

        $this->assertNull($result['score']);

        $reloaded = $this->get_attempt($instance->id, $attempt->userid);
        $this->assertNull($reloaded->score);
        $this->assertSame('inprogress', $reloaded->status);
    }

    /**
     * A submit records the attempt and keeps the best score.
     */
    public function test_submit_keeps_the_best_score(): void {
        [$instance, , $attempt, $service] = $this->build_fixture([
            "4\n" => "8\n",
            "0\n" => "0\n",
            "-7\n" => "-14\n",
        ]);

        $service->execute($attempt, ['Main.java' => 'good'], execution_request::MODE_SUBMIT);
        $first = $this->get_attempt($instance->id, $attempt->userid);
        $this->assertEqualsWithDelta(1.0, (float) $first->score, 0.01);

        // A worse follow up submission must not lower the recorded score.
        $provider = new scripted_provider(["4\n" => "9\n", "0\n" => "1\n", "-7\n" => "0\n"]);
        $cm = get_coursemodule_from_instance('saylorcode', $instance->id);
        $service2 = new execution_service($instance, $cm, $provider);
        $service2->execute($first, ['Main.java' => 'bad'], execution_request::MODE_SUBMIT);

        $second = $this->get_attempt($instance->id, $attempt->userid);
        $this->assertEqualsWithDelta(1.0, (float) $second->score, 0.01);
    }

    /**
     * Submitting as the very first action must work.
     *
     * Regression test. get_or_create_attempt() used to return the object it had
     * just inserted, which carried only the fields set on it, so a student whose
     * first action was Submit hit an undefined score property. Running or
     * checking first hid the bug, because those paths re-read the full row.
     */
    public function test_submit_as_the_very_first_action(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $course->id,
            'testcases' => self::CASES,
        ]);
        $cm = get_coursemodule_from_instance('saylorcode', $instance->id);

        $manager = new attempt_manager($instance);
        $attempt = $manager->get_or_create_attempt((int) $student->id);

        // The freshly created attempt must be a complete record.
        $this->assertObjectHasProperty('score', $attempt);
        $this->assertNull($attempt->score);

        $provider = new scripted_provider(["4\n" => "8\n", "0\n" => "0\n", "-7\n" => "-14\n"]);
        $service = new execution_service($instance, $cm, $provider);

        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_SUBMIT);

        $this->assertEqualsWithDelta(100.0, $result['score'], 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $this->get_attempt($instance->id, $student->id)->score, 0.01);
    }

    /**
     * Output comparison ignores trailing whitespace, which is not the lesson.
     */
    public function test_trailing_whitespace_does_not_fail_a_student(): void {
        [, , $attempt, $service] = $this->build_fixture([
            "4\n" => "8   \n\n",
            "0\n" => "0\n",
            "-7\n" => "-14\n",
        ]);

        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_SUBMIT);

        $this->assertEqualsWithDelta(100.0, $result['score'], 0.01);
    }

    /**
     * An activity with no test cases must not award credit by accident.
     */
    public function test_activity_without_cases_scores_nothing(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $course->id,
            'testcases' => '',
        ]);
        $cm = get_coursemodule_from_instance('saylorcode', $instance->id);

        $manager = new attempt_manager($instance);
        $attempt = $manager->get_or_create_attempt((int) $student->id);

        $service = new execution_service($instance, $cm, new scripted_provider(['' => 'anything']));
        $result = $service->execute($attempt, ['Main.java' => 'x'], execution_request::MODE_SUBMIT);

        $this->assertEqualsWithDelta(0.0, $result['score'], 0.01);
    }

    /**
     * A compile error stops the evaluation instead of repeating per case.
     */
    public function test_compile_error_stops_evaluation(): void {
        [, , $attempt, $service, $provider] = $this->build_fixture([], execution_state::COMPILE_ERROR);

        $result = $service->execute($attempt, ['Main.java' => 'broken'], execution_request::MODE_SUBMIT);

        $this->assertSame(execution_state::COMPILE_ERROR, $result['state']);
        $this->assertCount(1, $provider->requests);
    }

    /**
     * The execution record must never contain source code.
     */
    public function test_execution_record_holds_no_source(): void {
        global $DB;

        [, , $attempt, $service] = $this->build_fixture(["4\n" => "8\n", "0\n" => "0\n", "-7\n" => "-14\n"]);

        $service->execute($attempt, ['Main.java' => 'public class Main { /* secret */ }'], execution_request::MODE_SUBMIT);

        $records = $DB->get_records('saylorcode_executions', ['attemptid' => $attempt->id]);
        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            $this->assertStringNotContainsString('public class Main', json_encode($record));
            $this->assertStringNotContainsString('secret', json_encode($record));
        }
    }

    /**
     * Reload an attempt from the database.
     *
     * @param int $instanceid The activity instance.
     * @param int $userid The student.
     * @return \stdClass
     */
    private function get_attempt(int $instanceid, int $userid): \stdClass {
        global $DB;

        return $DB->get_record('saylorcode_attempts', [
            'saylorcodeid' => $instanceid,
            'userid' => $userid,
        ], '*', MUST_EXIST);
    }
}
