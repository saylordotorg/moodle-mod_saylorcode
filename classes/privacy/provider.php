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

namespace mod_saylorcode\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_saylorcode\local\attempt_cleanup;

/**
 * Privacy implementation for the Saylor Code Studio activity.
 *
 * The activity stores attempts, per step progress, snapshots of the code a
 * student wrote, and sanitised execution records. Snapshots are the sensitive
 * part: they contain the student's own source, so they are exported in full and
 * deleted with the attempt.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Describe the data this plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('saylorcode_attempts', [
            'userid' => 'privacy:metadata:attempts:userid',
            'status' => 'privacy:metadata:attempts:status',
            'score' => 'privacy:metadata:attempts:score',
            'timestarted' => 'privacy:metadata:attempts:timestarted',
            'hintsused' => 'privacy:metadata:attempts:hintsused',
            'solutionviewed' => 'privacy:metadata:attempts:solutionviewed',
        ], 'privacy:metadata:attempts');

        $collection->add_database_table('saylorcode_stepattempts', [
            'status' => 'privacy:metadata:stepattempts:status',
            'hintsused' => 'privacy:metadata:stepattempts:hintsused',
        ], 'privacy:metadata:stepattempts');

        $collection->add_database_table('saylorcode_snapshots', [
            'files' => 'privacy:metadata:snapshots:files',
            'timecreated' => 'privacy:metadata:snapshots:timecreated',
        ], 'privacy:metadata:snapshots');

        $collection->add_database_table('saylorcode_executions', [
            'state' => 'privacy:metadata:executions:state',
            'timecreated' => 'privacy:metadata:executions:timecreated',
        ], 'privacy:metadata:executions');

        $collection->add_database_table('saylorcode_testresults', [
            'testname' => 'privacy:metadata:testresults:testname',
            'passed' => 'privacy:metadata:testresults:passed',
            'timecreated' => 'privacy:metadata:testresults:timecreated',
        ], 'privacy:metadata:testresults');

        // Source code leaves Moodle to be executed. The service layer declares
        // that transmission in detail.
        $collection->link_subsystem('core_files', 'privacy:metadata:snapshots');

        return $collection;
    }

    /**
     * Contexts in which the given user has activity data.
     *
     * @param int $userid The user to search for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {saylorcode_attempts} a
                  JOIN {saylorcode} s ON s.id = a.saylorcodeid
                  JOIN {modules} m ON m.name = :modname
                  JOIN {course_modules} cm ON cm.instance = s.id AND cm.module = m.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE a.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'modname' => 'saylorcode',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Users who have data in the given context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $sql = "SELECT a.userid
                  FROM {saylorcode_attempts} a
                  JOIN {saylorcode} s ON s.id = a.saylorcodeid
                  JOIN {course_modules} cm ON cm.instance = s.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql('userid', $sql, [
            'modname' => 'saylorcode',
            'cmid' => $context->instanceid,
        ]);
    }

    /**
     * Export all data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('saylorcode', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $attempts = $DB->get_records('saylorcode_attempts', [
                'saylorcodeid' => $cm->instance,
                'userid' => $userid,
            ]);

            foreach ($attempts as $attempt) {
                $data = (object) [
                    'attemptnumber' => $attempt->attemptnumber,
                    'status' => $attempt->status,
                    'score' => $attempt->score,
                    'timestarted' => transform::datetime($attempt->timestarted),
                    // Exported because it is recorded about the student and
                    // shown to their teacher, so they are entitled to see it.
                    'hintsused' => $attempt->hintsused,
                    'solutionviewed' => transform::yesno($attempt->solutionviewed),
                    'timesubmitted' => $attempt->timesubmitted ? transform::datetime($attempt->timesubmitted) : null,
                    'snapshots' => self::export_snapshots($attempt->id),
                    // Recorded about the student and shown to their teacher, on
                    // the same footing as hints taken: if staff can see which
                    // cases a student failed, the student can see it too.
                    'testresults' => self::export_test_results($attempt->id),
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'mod_saylorcode'), 'attempt-' . $attempt->attemptnumber],
                    $data
                );
            }
        }
    }

    /**
     * The per test outcomes recorded for one attempt.
     *
     * Only the name and the outcome, which is all the table holds. Expected and
     * actual output are deliberately not stored anywhere reachable from here,
     * so a hidden case cannot be reconstructed from an export.
     *
     * @param int $attemptid The attempt.
     * @return array
     */
    protected static function export_test_results(int $attemptid): array {
        global $DB;

        $sql = "SELECT tr.id, tr.testname, tr.passed, tr.ispublic, tr.timecreated
                  FROM {saylorcode_testresults} tr
                  JOIN {saylorcode_executions} e ON e.id = tr.executionid
                 WHERE e.attemptid = :attemptid
              ORDER BY tr.timecreated ASC, tr.id ASC";

        $results = $DB->get_records_sql($sql, ['attemptid' => $attemptid]);

        $exported = [];
        foreach ($results as $result) {
            // A hidden case is never described to a student, and a privacy
            // export is read by the student it belongs to. The outcome is
            // theirs to have; the author's name for the case is not, so it is
            // masked here exactly as it is in an execution response.
            $name = empty($result->ispublic)
                ? get_string('hiddentest', 'local_saylorcode')
                : $result->testname;

            $exported[] = [
                'test' => $name,
                'passed' => transform::yesno($result->passed),
                'hidden' => transform::yesno(empty($result->ispublic)),
                'timecreated' => transform::datetime($result->timecreated),
            ];
        }

        return $exported;
    }

    /**
     * The snapshots recorded for one attempt.
     *
     * @param int $attemptid The attempt.
     * @return array
     */
    protected static function export_snapshots(int $attemptid): array {
        global $DB;

        $snapshots = $DB->get_records('saylorcode_snapshots', ['attemptid' => $attemptid], 'timecreated ASC');

        $exported = [];
        foreach ($snapshots as $snapshot) {
            $exported[] = [
                'type' => $snapshot->snapshottype,
                'label' => $snapshot->label,
                'timecreated' => transform::datetime($snapshot->timecreated),
                'files' => json_decode($snapshot->files, true),
            ];
        }

        return $exported;
    }

    /**
     * Delete all data for every user in a context.
     *
     * @param context $context The context to purge.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('saylorcode', $context->instanceid);
        if (!$cm) {
            return;
        }

        $attemptids = $DB->get_fieldset_select('saylorcode_attempts', 'id', 'saylorcodeid = ?', [$cm->instance]);
        self::delete_attempt_children($attemptids);

        $DB->delete_records('saylorcode_attempts', ['saylorcodeid' => $cm->instance]);
    }

    /**
     * Delete all data for one user across the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('saylorcode', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $attemptids = $DB->get_fieldset_select(
                'saylorcode_attempts',
                'id',
                'saylorcodeid = ? AND userid = ?',
                [$cm->instance, $userid]
            );
            self::delete_attempt_children($attemptids);

            $DB->delete_records('saylorcode_attempts', ['saylorcodeid' => $cm->instance, 'userid' => $userid]);
        }
    }

    /**
     * Delete data for a list of users in one context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('saylorcode', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['saylorcodeid'] = $cm->instance;

        $attemptids = $DB->get_fieldset_select(
            'saylorcode_attempts',
            'id',
            "saylorcodeid = :saylorcodeid AND userid $insql",
            $params
        );
        self::delete_attempt_children($attemptids);

        $DB->delete_records_select('saylorcode_attempts', "saylorcodeid = :saylorcodeid AND userid $insql", $params);
    }

    /**
     * Delete the rows that hang off a set of attempts.
     *
     * Snapshots hold student source, so they must go whenever their parent
     * attempt does; leaving them behind would retain the most sensitive data
     * after a deletion request.
     *
     * @param array $attemptids Attempt ids.
     */
    protected static function delete_attempt_children(array $attemptids): void {
        attempt_cleanup::delete_for_attempts($attemptids);
    }
}
