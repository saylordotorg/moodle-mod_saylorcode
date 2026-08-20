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

use mod_saylorcode\task\purge_execution_logs;

/**
 * Tests for the execution log retention task.
 *
 * The setting this reads has promised scheduled deletion since the runner
 * existed, and nothing performed it. These cover the three ways getting it wrong
 * would be worse than the original omission: deleting what should be kept,
 * keeping what should be deleted, and stranding the rows that hang off an
 * execution.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\task\purge_execution_logs
 */
final class purge_execution_logs_test extends \advanced_testcase {
    /**
     * Record one execution of a given age, with a test result hanging off it.
     *
     * @param int $instanceid The activity.
     * @param int $attemptid The attempt.
     * @param int $age How long ago it ran, in seconds.
     * @return int The execution id.
     */
    protected function execution(int $instanceid, int $attemptid, int $age): int {
        global $DB;

        $executionid = $DB->insert_record('saylorcode_executions', (object) [
            'requestid' => 'req-' . $age,
            'attemptid' => $attemptid,
            'mode' => 'check',
            'profileid' => 'java17-console',
            'state' => 'completed',
            'queuetime' => 0,
            'runtime' => 0,
            'truncated' => 0,
            'testspassed' => 1,
            'teststotal' => 1,
            'timecreated' => time() - $age,
        ]);

        $DB->insert_record('saylorcode_testresults', (object) [
            'executionid' => $executionid,
            'saylorcodeid' => $instanceid,
            'caseid' => 'T1',
            'stepid' => null,
            'testname' => 'Greets',
            'passed' => 1,
            'ispublic' => 1,
            'timecreated' => time() - $age,
        ]);

        return $executionid;
    }

    /**
     * An activity and an attempt to hang executions off.
     *
     * @return array [instanceid, attemptid]
     */
    protected function scenario(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('saylorcode', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $attemptid = $DB->insert_record('saylorcode_attempts', (object) [
            'saylorcodeid' => $instance->id,
            'userid' => $user->id,
            'attemptnumber' => 1,
            'status' => 'inprogress',
            'score' => 0,
            'hintsused' => 0,
            'solutionviewed' => 0,
            'timestarted' => time(),
            'timemodified' => time(),
        ]);

        return [$instance->id, $attemptid];
    }

    /**
     * Records past the retention go, and recent ones stay.
     *
     * @return void
     */
    public function test_only_records_past_the_retention_are_deleted(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('executionlogretention', 30 * DAYSECS, 'local_saylorcode');

        [$instanceid, $attemptid] = $this->scenario();
        $old = $this->execution($instanceid, $attemptid, 60 * DAYSECS);
        $recent = $this->execution($instanceid, $attemptid, 5 * DAYSECS);

        ob_start();
        (new purge_execution_logs())->execute();
        ob_end_clean();

        $this->assertFalse($DB->record_exists('saylorcode_executions', ['id' => $old]));
        $this->assertTrue($DB->record_exists('saylorcode_executions', ['id' => $recent]));
    }

    /**
     * The results hanging off a deleted execution go with it.
     *
     * Deleting the execution and leaving these behind would strand rows nothing
     * can reach, and fails outright where the foreign key is enforced.
     *
     * @return void
     */
    public function test_test_results_are_deleted_with_their_execution(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('executionlogretention', 30 * DAYSECS, 'local_saylorcode');

        [$instanceid, $attemptid] = $this->scenario();
        $old = $this->execution($instanceid, $attemptid, 60 * DAYSECS);
        $recent = $this->execution($instanceid, $attemptid, 1 * DAYSECS);

        ob_start();
        (new purge_execution_logs())->execute();
        ob_end_clean();

        $this->assertFalse(
            $DB->record_exists('saylorcode_testresults', ['executionid' => $old]),
            'Test results outlived the execution they hang off.'
        );
        $this->assertTrue($DB->record_exists('saylorcode_testresults', ['executionid' => $recent]));
    }

    /**
     * No retention set means keep everything.
     *
     * Zero is how Moodle spells "indefinitely", and an unset value must never be
     * read as a cutoff of now, which would delete the lot on first run.
     *
     * @return void
     */
    public function test_an_unset_retention_deletes_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('executionlogretention', 0, 'local_saylorcode');

        [$instanceid, $attemptid] = $this->scenario();
        $this->execution($instanceid, $attemptid, 5000 * DAYSECS);

        ob_start();
        (new purge_execution_logs())->execute();
        ob_end_clean();

        $this->assertSame(1, $DB->count_records('saylorcode_executions'));
        $this->assertSame(1, $DB->count_records('saylorcode_testresults'));
    }

    /**
     * The task is registered, and not just written.
     *
     * @return void
     */
    public function test_the_task_is_scheduled(): void {
        $this->resetAfterTest();

        $task = \core\task\manager::get_scheduled_task(purge_execution_logs::class);

        $this->assertInstanceOf(purge_execution_logs::class, $task);
        $this->assertNotEmpty($task->get_name());
    }
}
