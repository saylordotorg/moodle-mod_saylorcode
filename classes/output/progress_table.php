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

namespace mod_saylorcode\output;

use html_writer;
use mod_saylorcode\local\progress_report;
use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * How each student is getting on with one activity.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_table extends table_sql {
    /**
     * Set the table up.
     *
     * @param string $uniqueid A unique id for this table.
     * @param moodle_url $baseurl The page the table lives on.
     * @param progress_report $report The report behind it.
     */
    public function __construct(string $uniqueid, moodle_url $baseurl, progress_report $report) {
        parent::__construct($uniqueid);

        $this->define_baseurl($baseurl);

        $this->define_columns(['fullname', 'status', 'score', 'attempts', 'failedchecks', 'help', 'lastrun']);
        $this->define_headers([
            get_string('reportstudent', 'mod_saylorcode'),
            get_string('reportstatus', 'mod_saylorcode'),
            get_string('reportscore', 'mod_saylorcode'),
            get_string('reportactivity', 'mod_saylorcode'),
            get_string('reportfailedchecks', 'mod_saylorcode'),
            get_string('reporthelp', 'mod_saylorcode'),
            get_string('reportlastseen', 'mod_saylorcode'),
        ]);

        // Derived from three columns at once, so sorting it would sort on
        // something other than what is shown.
        $this->no_sorting('attempts');
        $this->no_sorting('help');

        $this->sortable(true, 'lastrun', SORT_DESC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable saylorcode-progress');

        [$fields, $from, $where, $params] = $report->build_query();
        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * The student.
     *
     * @param object $row A report row.
     * @return string
     */
    public function col_fullname($row): string {
        return fullname($row);
    }

    /**
     * Where the student has got to.
     *
     * @param object $row A report row.
     * @return string
     */
    public function col_status(object $row): string {
        if ($row->attemptid === null) {
            // The row a teacher is scanning for. Said plainly rather than left
            // as an empty cell, which reads as missing data.
            return html_writer::span(
                get_string('reportnotstarted', 'mod_saylorcode'),
                'badge badge-secondary bg-secondary text-white'
            );
        }

        $classes = [
            'completed' => 'badge badge-success bg-success text-white',
            'submitted' => 'badge badge-success bg-success text-white',
            'inprogress' => 'badge badge-info bg-info text-dark',
            'abandoned' => 'badge badge-warning bg-warning text-dark',
        ];

        return html_writer::span(
            get_string('reportstate' . $row->status, 'mod_saylorcode'),
            $classes[$row->status] ?? 'badge badge-secondary bg-secondary text-white'
        );
    }

    /**
     * The score, where there is one.
     *
     * @param object $row A report row.
     * @return string
     */
    public function col_score(object $row): string {
        if ($row->score === null) {
            return html_writer::span('&mdash;', 'text-muted');
        }

        return format_float((float) $row->score * 100, 0) . '%';
    }

    /**
     * How much the student has done.
     *
     * @param object $row A report row.
     * @return string
     */
    public function col_attempts(object $row): string {
        if ($row->attemptid === null) {
            return html_writer::span('&mdash;', 'text-muted');
        }

        return get_string('reportattemptcounts', 'mod_saylorcode', (object) [
            'runs' => (int) $row->runs,
            'checks' => (int) $row->checks,
            'submits' => (int) $row->submits,
        ]);
    }

    /**
     * How often the student's checks came back failing.
     *
     * The number that says who is stuck. Emphasised past a threshold rather
     * than at any failure, because failing a few times is how learning a
     * language works and flagging it would make the column useless.
     *
     * @param object $row A report row.
     * @return string
     */
    public function col_failedchecks(object $row): string {
        $failed = (int) $row->failedchecks;

        if ($row->attemptid === null) {
            return html_writer::span('&mdash;', 'text-muted');
        }

        if ($failed >= 5 && $row->status !== 'completed') {
            return html_writer::span($failed, 'saylorcode-progress-stuck');
        }

        return (string) $failed;
    }

    /**
     * What help the student took.
     *
     * The form tells authors this is shown to teachers, so it has to be.
     *
     * @param object $row A report row.
     * @return string
     */
    public function col_help(object $row): string {
        if ($row->attemptid === null) {
            return html_writer::span('&mdash;', 'text-muted');
        }

        $parts = [];

        if ((int) $row->hintsused > 0) {
            $parts[] = get_string('reporthintstaken', 'mod_saylorcode', (int) $row->hintsused);
        }

        if (!empty($row->solutionviewed)) {
            $parts[] = get_string('reportsawsolution', 'mod_saylorcode');
        }

        if (!$parts) {
            return html_writer::span(get_string('reportnohelp', 'mod_saylorcode'), 'text-muted');
        }

        return implode(', ', $parts);
    }

    /**
     * When the student last ran anything.
     *
     * @param object $row A report row.
     * @return string
     */
    public function col_lastrun(object $row): string {
        if (empty($row->lastrun)) {
            return html_writer::span('&mdash;', 'text-muted');
        }

        return userdate($row->lastrun, get_string('strftimedatetimeshort', 'core_langconfig'));
    }
}
