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

use context_module;
use stdClass;

/**
 * What an instructor needs to know about how an activity is going.
 *
 * The question this exists to answer is not "what did each student score" but
 * "who is stuck, and on what". A teacher who has to open every execution to
 * find that out will not do it (specification section 13), so the counts that
 * reveal it are aggregated here rather than left to be eyeballed.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_report {
    /** @var stdClass The activity instance. */
    protected stdClass $instance;

    /** @var context_module The module context. */
    protected context_module $context;

    /**
     * Build a report for one activity.
     *
     * @param stdClass $instance The activity instance.
     * @param context_module $context The module context.
     */
    public function __construct(stdClass $instance, context_module $context) {
        $this->instance = $instance;
        $this->context = $context;
    }

    /**
     * The SQL behind the per student table.
     *
     * Built as pieces so table_sql can sort and page over it.
     *
     * @return array [fields, from, where, params]
     */
    public function build_query(): array {
        global $DB;

        // Everyone who could attempt, whether or not they have. A student who
        // has not started is exactly who a teacher is looking for, and an inner
        // join to attempts would hide them.
        [$esql, $eparams] = get_enrolled_sql($this->context, 'mod/saylorcode:attempt', 0, true);

        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false);

        $fields = 'u.id, u.id AS userid' . $userfields->selects . ', '
            . 'a.id AS attemptid, a.status, a.score, a.timestarted, a.timemodified, a.timesubmitted, '
            . 'COALESCE(x.runs, 0) AS runs, '
            . 'COALESCE(x.checks, 0) AS checks, '
            . 'COALESCE(x.submits, 0) AS submits, '
            . 'COALESCE(x.failedchecks, 0) AS failedchecks, '
            . 'x.lastrun';

        $from = "($esql) enrolled
                 JOIN {user} u ON u.id = enrolled.id
            LEFT JOIN {saylorcode_attempts} a ON a.userid = u.id AND a.saylorcodeid = :instanceid
            LEFT JOIN (
                    SELECT e.attemptid,
                           SUM(CASE WHEN e.mode = 'run' THEN 1 ELSE 0 END) AS runs,
                           SUM(CASE WHEN e.mode = 'check' THEN 1 ELSE 0 END) AS checks,
                           SUM(CASE WHEN e.mode = 'submit' THEN 1 ELSE 0 END) AS submits,
                           SUM(CASE WHEN e.mode <> 'run' AND e.teststotal > 0
                                     AND e.testspassed < e.teststotal THEN 1 ELSE 0 END) AS failedchecks,
                           MAX(e.timecreated) AS lastrun
                      FROM {saylorcode_executions} e
                  GROUP BY e.attemptid
                 ) x ON x.attemptid = a.id";

        $params = $eparams + [
            'instanceid' => $this->instance->id,
        ];

        return [$fields, $from, '1 = 1', $params];
    }

    /**
     * How each step of a guided lesson is going across the whole class.
     *
     * This is the view that answers the question a teacher actually has. A step
     * where many students have started and few have finished is a step whose
     * instructions are wrong, not a class that is struggling.
     *
     * @return array One row per step, in lesson order.
     */
    public function get_step_summary(): array {
        global $DB;

        $steps = $DB->get_records('saylorcode_steps', ['saylorcodeid' => $this->instance->id], 'sortorder ASC, id ASC');

        if (!$steps) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($steps), SQL_PARAMS_NAMED, 'step');

        $sql = "SELECT sa.stepid,
                       COUNT(1) AS reached,
                       SUM(CASE WHEN sa.status = :complete THEN 1 ELSE 0 END) AS completed,
                       SUM(sa.checkcount) AS checks,
                       AVG(sa.checkcount) AS avgchecks
                  FROM {saylorcode_stepattempts} sa
                  JOIN {saylorcode_attempts} a ON a.id = sa.attemptid
                 WHERE sa.stepid $insql AND a.saylorcodeid = :instanceid
              GROUP BY sa.stepid";

        $counts = $DB->get_records_sql($sql, $inparams + [
            'complete' => step_manager::STATUS_COMPLETE,
            'instanceid' => $this->instance->id,
        ]);

        $rows = [];
        $number = 0;

        foreach ($steps as $step) {
            $number++;
            $count = $counts[$step->id] ?? null;

            $reached = $count ? (int) $count->reached : 0;
            $completed = $count ? (int) $count->completed : 0;

            $rows[] = [
                'number' => $number,
                'title' => $step->title,
                'completionrule' => $step->completionrule,
                'reached' => $reached,
                'completed' => $completed,
                'stuck' => $reached - $completed,
                'avgchecks' => $count && $count->avgchecks !== null ? round((float) $count->avgchecks, 1) : 0,
            ];
        }

        return $rows;
    }

    /**
     * Headline numbers for the activity.
     *
     * @return array
     */
    public function get_totals(): array {
        global $DB;

        [$esql, $eparams] = get_enrolled_sql($this->context, 'mod/saylorcode:attempt', 0, true);

        $enrolled = $DB->count_records_sql("SELECT COUNT(1) FROM ($esql) e", $eparams);

        $started = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {saylorcode_attempts} WHERE saylorcodeid = :id',
            ['id' => $this->instance->id]
        );

        $submitted = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {saylorcode_attempts} WHERE saylorcodeid = :id AND timesubmitted IS NOT NULL',
            ['id' => $this->instance->id]
        );

        return [
            'enrolled' => $enrolled,
            'started' => $started,
            'notstarted' => max(0, $enrolled - $started),
            'submitted' => $submitted,
        ];
    }
}
