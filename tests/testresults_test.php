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

use mod_saylorcode\local\attempt_cleanup;
use mod_saylorcode\local\progress_report;

/**
 * Tests for recorded test outcomes.
 *
 * All three cases here come from review of the change that introduced the
 * table, and each is a way the feature was wrong in a manner nothing would have
 * reported: a report that merges two unrelated cases, a hidden case promoted
 * into a visible report by a name collision, and rows left behind when the
 * activity that owns them is deleted.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\progress_report
 * @covers     \mod_saylorcode\local\attempt_cleanup
 */
final class testresults_test extends \advanced_testcase {
    /**
     * Build an activity, an attempt, and an execution to hang results off.
     *
     * @param array $results Each with caseid, stepid, name, passed, ispublic.
     * @return array [instance, cm, attemptid, executionid]
     */
    protected function scenario(array $results): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('saylorcode', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('saylorcode', $instance->id);
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

        $executionid = $DB->insert_record('saylorcode_executions', (object) [
            'requestid' => 'req-1',
            'attemptid' => $attemptid,
            'mode' => 'check',
            'profileid' => 'java17-console',
            'state' => 'completed',
            'queuetime' => 0,
            'runtime' => 0,
            'truncated' => 0,
            'testspassed' => 0,
            'teststotal' => count($results),
            'timecreated' => time(),
        ]);

        foreach ($results as $result) {
            $DB->insert_record('saylorcode_testresults', (object) [
                'executionid' => $executionid,
                'saylorcodeid' => $instance->id,
                'caseid' => $result['caseid'],
                'stepid' => $result['stepid'],
                'testname' => $result['name'],
                'passed' => $result['passed'],
                'ispublic' => $result['ispublic'],
                'timecreated' => time(),
            ]);
        }

        return [$instance, $cm, $attemptid, $executionid];
    }

    /**
     * Two cases sharing a display name are reported separately.
     *
     * The form does not stop two steps naming a case the same thing, and with
     * per step exercises that is ordinary rather than perverse. Grouping on the
     * name reported one combined failure rate for two unrelated cases.
     *
     * @return void
     */
    public function test_cases_sharing_a_name_are_not_merged(): void {
        $this->resetAfterTest();

        [$instance, $cm] = $this->scenario([
            ['caseid' => 'T1', 'stepid' => 10, 'name' => 'Prints the total', 'passed' => 0, 'ispublic' => 1],
            ['caseid' => 'T1', 'stepid' => 20, 'name' => 'Prints the total', 'passed' => 0, 'ispublic' => 1],
        ]);

        $report = new progress_report($instance, \context_module::instance($cm->id));
        $failed = $report->get_failed_tests();

        $this->assertCount(2, $failed, 'Two cases from different steps were merged into one row.');
    }

    /**
     * A hidden case is never promoted by sharing a name with a public one.
     *
     * The aggregate took the more permissive of the two visibilities, so a
     * collision could put a hidden case into the report students' teachers
     * read as public. Hidden has to win.
     *
     * @return void
     */
    public function test_a_hidden_case_is_not_promoted_by_a_name_collision(): void {
        $this->resetAfterTest();

        [$instance, $cm] = $this->scenario([
            ['caseid' => 'T1', 'stepid' => null, 'name' => 'Same name', 'passed' => 0, 'ispublic' => 1],
            ['caseid' => 'T1', 'stepid' => null, 'name' => 'Same name', 'passed' => 0, 'ispublic' => 0],
        ]);

        $report = new progress_report($instance, \context_module::instance($cm->id));
        $failed = $report->get_failed_tests();

        $this->assertCount(1, $failed);
        $this->assertFalse(
            $failed[0]['ispublic'],
            'A group containing a hidden case was reported as public.'
        );
    }

    /**
     * Deleting an attempt takes its test results with it.
     *
     * Results are reachable only through their execution. Deleting the
     * execution first leaves them unreachable, and on a database that enforces
     * the foreign key the delete fails outright.
     *
     * @return void
     */
    public function test_deleting_an_attempt_removes_its_test_results(): void {
        global $DB;

        $this->resetAfterTest();

        [, , $attemptid] = $this->scenario([
            ['caseid' => 'T1', 'stepid' => null, 'name' => 'Greets', 'passed' => 1, 'ispublic' => 1],
            ['caseid' => 'T2', 'stepid' => null, 'name' => 'Adds', 'passed' => 0, 'ispublic' => 0],
        ]);

        $this->assertSame(2, $DB->count_records('saylorcode_testresults'));

        attempt_cleanup::delete_for_attempts([$attemptid]);

        $this->assertSame(0, $DB->count_records('saylorcode_testresults'), 'Test results outlived their attempt.');
        $this->assertSame(0, $DB->count_records('saylorcode_executions'));
    }

    /**
     * Deleting the activity leaves nothing behind either.
     *
     * @return void
     */
    public function test_deleting_the_activity_removes_test_results(): void {
        global $DB;

        $this->resetAfterTest();

        [$instance] = $this->scenario([
            ['caseid' => 'T1', 'stepid' => null, 'name' => 'Greets', 'passed' => 0, 'ispublic' => 1],
        ]);

        $this->assertSame(1, $DB->count_records('saylorcode_testresults'));

        saylorcode_delete_instance($instance->id);

        $this->assertSame(0, $DB->count_records('saylorcode_testresults'), 'Test results outlived the activity.');
    }
}
