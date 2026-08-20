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

namespace mod_saylorcode\task;

use core\task\scheduled_task;
use local_saylorcode\local\runner\execution_request;

/**
 * Delete execution records once they are older than the configured retention.
 *
 * The setting promising this has existed since the runner did: "How long
 * sanitised execution records are kept before scheduled deletion." Nothing read
 * it, so nothing was ever deleted and the records accumulated for the life of
 * the site. An administrator setting a retention period had no way to tell.
 *
 * The records hold no student code -- that lives with the attempt as snapshots,
 * and is governed by the attempt, not by this. What is here is the state of each
 * run, its timings, and the sanitised diagnostic, plus the per case outcomes
 * that hang off it.
 *
 * Submissions are exempt. The attempt limit is enforced by counting submit rows
 * in this table, so deleting them hands a student back submissions they have
 * already spent: an activity capped at two attempts becomes uncapped once the
 * retention period passes. They are assessment records rather than telemetry,
 * and they are removed with the attempt they belong to, which is where deleting
 * them correctly belongs.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_execution_logs extends scheduled_task {
    /**
     * The name shown in the scheduled task administration screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskpurgeexecutionlogs', 'mod_saylorcode');
    }

    /**
     * How many executions to remove per pass.
     *
     * A method rather than a constant so a test can lower it and actually
     * exercise the loop. Proving the batching works by inserting five thousand
     * rows is not a test anyone would keep.
     *
     * @return int
     */
    protected function batch_size(): int {
        return 5000;
    }

    /**
     * Delete what is past its retention.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $retention = (int) get_config('local_saylorcode', 'executionlogretention');

        // Zero is the conventional Moodle reading of "keep indefinitely", and
        // an unset value must not be read as "delete everything".
        if ($retention <= 0) {
            mtrace('Execution log retention is not set, so nothing is deleted.');
            return;
        }

        $cutoff = time() - $retention;
        $total = 0;

        do {
            // Asked per pass rather than once, so that a test can count the
            // passes and tell real batching from a limit that is being ignored.
            $batch = $this->batch_size();

            // Deliberately get_records_sql, not get_fieldset_select: the
            // latter takes no limit arguments, so passing them is silently
            // ignored and the first run on a site with any history loads every
            // expired id into one IN clause.
            $ids = array_keys($DB->get_records_sql(
                "SELECT id
                   FROM {saylorcode_executions}
                  WHERE timecreated < ?
                    AND mode <> ?",
                [$cutoff, execution_request::MODE_SUBMIT],
                0,
                $batch
            ));

            if (empty($ids)) {
                break;
            }

            [$insql, $params] = $DB->get_in_or_equal($ids);

            // Children first. Test results are reachable only through their
            // execution, so removing the execution first would strand them, and
            // fails outright where the foreign key is enforced.
            $DB->delete_records_select('saylorcode_testresults', "executionid $insql", $params);
            $DB->delete_records_select('saylorcode_executions', "id $insql", $params);

            $total += count($ids);
        } while (count($ids) === $batch);

        mtrace("Deleted {$total} execution records older than " . format_time($retention) . '.');
    }
}
