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

/**
 * Removing everything that hangs off an attempt.
 *
 * There are three callers -- deleting an activity, resetting a course, and
 * answering a privacy erasure request -- and they must agree, because the rows
 * form a chain: test results are found through executions, and executions
 * through attempts. Delete a parent before its children and the children become
 * unreachable, which means undeletable; on a database that enforces the foreign
 * key, the delete fails outright instead.
 *
 * Each caller previously carried its own copy of the sequence. When test
 * results were added, only one copy was updated, and the other two silently
 * left rows behind. Hence one implementation.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_cleanup {
    /**
     * Delete everything belonging to these attempts, leaves first.
     *
     * The attempts themselves are left alone: callers delete them by different
     * criteria, and doing it here would hide that difference.
     *
     * @param array $attemptids Attempt ids.
     * @return void
     */
    public static function delete_for_attempts(array $attemptids): void {
        global $DB;

        if (empty($attemptids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($attemptids);

        $DB->delete_records_select('saylorcode_snapshots', "attemptid $insql", $params);
        $DB->delete_records_select('saylorcode_stepattempts', "attemptid $insql", $params);

        // Before the executions they hang off, for the reason in the class
        // comment: afterwards there is no way left to find them.
        $DB->delete_records_select(
            'saylorcode_testresults',
            "executionid IN (SELECT id FROM {saylorcode_executions} WHERE attemptid $insql)",
            $params
        );

        $DB->delete_records_select('saylorcode_executions', "attemptid $insql", $params);
    }
}
