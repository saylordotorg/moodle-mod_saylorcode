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

namespace mod_saylorcode;

use mod_saylorcode\local\attempt_manager;
use mod_saylorcode\local\progress_report;
use mod_saylorcode\local\step_manager;

/**
 * Tests for the instructor progress report.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\progress_report
 */
final class progress_report_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private $course;

    /** @var \stdClass The activity. */
    private $instance;

    /** @var \context_module The module context. */
    private $context;

    /** @var progress_report The report under test. */
    private $report;

    /**
     * An activity in a course with a context.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $this->course->id,
        ]);

        $cm = get_coursemodule_from_instance('saylorcode', $this->instance->id, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($cm->id);
        $this->report = new progress_report($this->instance, $this->context);
    }

    /**
     * Run the report query and return rows keyed by user id.
     *
     * @return array
     */
    private function rows(): array {
        global $DB;

        [$fields, $from, $where, $params] = $this->report->build_query();

        return $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
    }

    /**
     * Record an execution against an attempt.
     *
     * @param int $attemptid The attempt.
     * @param string $mode run, check or submit.
     * @param int $passed Tests passed.
     * @param int $total Tests in total.
     */
    private function record_execution(int $attemptid, string $mode, int $passed = 0, int $total = 0): void {
        global $DB;

        $DB->insert_record('saylorcode_executions', (object) [
            'requestid' => bin2hex(random_bytes(8)),
            'attemptid' => $attemptid,
            'mode' => $mode,
            'profileid' => 'java17-console',
            'state' => 'completed',
            'queuetime' => 0,
            'runtime' => 0.1,
            'truncated' => 0,
            'testspassed' => $passed,
            'teststotal' => $total,
            'timecreated' => time(),
        ]);
    }

    /**
     * A student who has never opened the activity still appears.
     *
     * This is the row a teacher is looking for. An inner join to attempts would
     * hide exactly the people worth chasing.
     */
    public function test_a_student_who_has_not_started_still_appears(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $rows = $this->rows();

        $this->assertArrayHasKey($student->id, $rows);
        $this->assertNull($rows[$student->id]->attemptid);
        $this->assertEquals(0, $rows[$student->id]->runs);
    }

    /**
     * Teachers are not students and do not belong in the list.
     */
    public function test_teachers_are_not_listed(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $rows = $this->rows();

        $this->assertArrayHasKey($student->id, $rows);
        $this->assertArrayNotHasKey($teacher->id, $rows);
    }

    /**
     * Actions are counted by kind.
     */
    public function test_actions_are_counted_by_kind(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $attempt = (new attempt_manager($this->instance))->get_or_create_attempt((int) $student->id);

        $this->record_execution((int) $attempt->id, 'run');
        $this->record_execution((int) $attempt->id, 'run');
        $this->record_execution((int) $attempt->id, 'check', 1, 2);
        $this->record_execution((int) $attempt->id, 'submit', 2, 2);

        $row = $this->rows()[$student->id];

        $this->assertEquals(2, $row->runs);
        $this->assertEquals(1, $row->checks);
        $this->assertEquals(1, $row->submits);
    }

    /**
     * A failed check is one where the tests did not all pass.
     *
     * A plain run has no tests and says nothing about correctness, so counting
     * it as a failure would make the column meaningless.
     */
    public function test_failed_checks_count_only_failing_tests(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $attempt = (new attempt_manager($this->instance))->get_or_create_attempt((int) $student->id);

        $this->record_execution((int) $attempt->id, 'run');
        $this->record_execution((int) $attempt->id, 'check', 0, 2);
        $this->record_execution((int) $attempt->id, 'check', 1, 2);
        $this->record_execution((int) $attempt->id, 'check', 2, 2);

        $row = $this->rows()[$student->id];

        $this->assertEquals(2, $row->failedchecks);
    }

    /**
     * One student's work does not appear against another.
     */
    public function test_students_are_not_confused_with_each_other(): void {
        $one = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $two = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $manager = new attempt_manager($this->instance);
        $attemptone = $manager->get_or_create_attempt((int) $one->id);
        $manager->get_or_create_attempt((int) $two->id);

        $this->record_execution((int) $attemptone->id, 'run');
        $this->record_execution((int) $attemptone->id, 'run');

        $rows = $this->rows();

        $this->assertEquals(2, $rows[$one->id]->runs);
        $this->assertEquals(0, $rows[$two->id]->runs);
    }

    /**
     * Work on another activity does not leak into this report.
     */
    public function test_another_activity_does_not_leak_in(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $other = $this->getDataGenerator()->create_module('saylorcode', ['course' => $this->course->id]);
        $otherattempt = (new attempt_manager($other))->get_or_create_attempt((int) $student->id);
        $this->record_execution((int) $otherattempt->id, 'run');
        $this->record_execution((int) $otherattempt->id, 'run');

        $row = $this->rows()[$student->id];

        $this->assertNull($row->attemptid);
        $this->assertEquals(0, $row->runs);
    }

    /**
     * The totals describe the class.
     */
    public function test_totals_describe_the_class(): void {
        $one = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        (new attempt_manager($this->instance))->get_or_create_attempt((int) $one->id);

        $totals = $this->report->get_totals();

        $this->assertSame(3, $totals['enrolled']);
        $this->assertSame(1, $totals['started']);
        $this->assertSame(2, $totals['notstarted']);
    }

    /**
     * An activity with no steps has no step summary.
     */
    public function test_an_activity_without_steps_has_no_step_summary(): void {
        $this->assertSame([], $this->report->get_step_summary());
    }

    /**
     * The step summary shows where a class is stuck.
     */
    public function test_the_step_summary_shows_where_the_class_is_stuck(): void {
        global $DB;

        $step = (object) [
            'saylorcodeid' => $this->instance->id,
            'sortorder' => 1,
            'steptype' => 'checkpoint',
            'title' => 'The hard one',
            'instructions' => '',
            'instructionsformat' => FORMAT_HTML,
            'versionpolicy' => 'latest',
            'carryforward' => 1,
            'completionrule' => step_manager::RULE_RUN,
            'allowrevisit' => 1,
            'points' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $step->id = $DB->insert_record('saylorcode_steps', $step);
        $step = $DB->get_record('saylorcode_steps', ['id' => $step->id], '*', MUST_EXIST);

        $manager = new step_manager($this->instance);
        $attempts = new attempt_manager($this->instance);

        // Three students reach it; one finishes.
        $finisher = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $stuckone = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $stucktwo = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        foreach ([$finisher, $stuckone, $stucktwo] as $user) {
            $attempt = $attempts->get_or_create_attempt((int) $user->id);
            $manager->get_or_create_step_attempt((int) $attempt->id, (int) $step->id);
        }

        $attempt = $attempts->get_or_create_attempt((int) $finisher->id);
        $stepattempt = $manager->get_or_create_step_attempt((int) $attempt->id, (int) $step->id);
        $manager->record_action($step, $stepattempt, 'run');

        $summary = $this->report->get_step_summary();

        $this->assertCount(1, $summary);
        $this->assertSame('The hard one', $summary[0]['title']);
        $this->assertSame(3, $summary[0]['reached']);
        $this->assertSame(1, $summary[0]['completed']);
        $this->assertSame(2, $summary[0]['stuck']);
    }
}
