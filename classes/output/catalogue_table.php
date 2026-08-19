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
use mod_saylorcode\local\catalogue;
use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * The exercise catalogue.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogue_table extends table_sql {
    /**
     * Set the table up.
     *
     * @param string $uniqueid A unique id for this table.
     * @param moodle_url $baseurl The page the table lives on.
     * @param array $filters Active filters.
     */
    public function __construct(string $uniqueid, moodle_url $baseurl, array $filters) {
        parent::__construct($uniqueid);

        $this->define_baseurl($baseurl);

        $this->define_columns(['stableid', 'name', 'course', 'profileid', 'cases', 'readiness', 'timemodified']);
        $this->define_headers([
            get_string('cataloguestableid', 'mod_saylorcode'),
            get_string('cataloguename', 'mod_saylorcode'),
            get_string('cataloguecourse', 'mod_saylorcode'),
            get_string('catalogueprofile', 'mod_saylorcode'),
            get_string('cataloguecases', 'mod_saylorcode'),
            get_string('cataloguereadiness', 'mod_saylorcode'),
            get_string('cataloguemodified', 'mod_saylorcode'),
        ]);

        // Derived columns have no single database column behind them, so
        // sorting on them would silently sort on something else.
        $this->no_sorting('cases');
        $this->no_sorting('readiness');

        $this->sortable(true, 'stableid', SORT_ASC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable saylorcode-catalogue');

        [$fields, $from, $where, $params] = catalogue::build_query($filters);
        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * The stable id, linked to the preview.
     *
     * @param object $row A catalogue row.
     * @return string
     */
    public function col_stableid(object $row): string {
        $label = trim((string) $row->stableid);

        if ($label === '') {
            // A playground has no exercise reference, so there is nothing to
            // show here rather than an empty cell that reads as missing data.
            return html_writer::span(get_string('cataloguenostableid', 'mod_saylorcode'), 'text-muted');
        }

        return html_writer::link(
            new moodle_url('/mod/saylorcode/preview.php', ['id' => $row->cmid]),
            $label,
            ['class' => 'saylorcode-catalogue-id']
        );
    }

    /**
     * The exercise name, linked to the activity itself.
     *
     * @param object $row A catalogue row.
     * @return string
     */
    public function col_name(object $row): string {
        return html_writer::link(
            new moodle_url('/mod/saylorcode/view.php', ['id' => $row->cmid]),
            format_string($row->name)
        );
    }

    /**
     * The course the exercise sits in.
     *
     * @param object $row A catalogue row.
     * @return string
     */
    public function col_course(object $row): string {
        return html_writer::link(
            new moodle_url('/course/view.php', ['id' => $row->course]),
            format_string($row->courseshortname),
            ['title' => format_string($row->coursefullname)]
        );
    }

    /**
     * How many cases the exercise defines, and how many are hidden.
     *
     * @param object $row A catalogue row.
     * @return string
     */
    public function col_cases(object $row): string {
        [$total, $hidden] = catalogue::count_cases($row->testcases ?? null);

        if ($total === 0) {
            return html_writer::span(get_string('cataloguenocases', 'mod_saylorcode'), 'text-muted');
        }

        if ($hidden === 0) {
            return (string) $total;
        }

        return get_string('cataloguecasecount', 'mod_saylorcode', (object) [
            'total' => $total,
            'hidden' => $hidden,
        ]);
    }

    /**
     * Whether the exercise is finished enough to put in front of a student.
     *
     * @param object $row A catalogue row.
     * @return string
     */
    public function col_readiness(object $row): string {
        $state = catalogue::readiness($row);

        $classes = [
            'ready' => 'badge badge-success bg-success text-white',
            'nosolution' => 'badge badge-warning bg-warning text-dark',
            'notests' => 'badge badge-warning bg-warning text-dark',
            'empty' => 'badge badge-secondary bg-secondary text-white',
        ];

        return html_writer::span(
            get_string('cataloguestate' . $state, 'mod_saylorcode'),
            $classes[$state]
        );
    }

    /**
     * When the exercise last changed.
     *
     * @param object $row A catalogue row.
     * @return string
     */
    public function col_timemodified(object $row): string {
        if (empty($row->timemodified)) {
            return '';
        }

        return userdate($row->timemodified, get_string('strftimedatetimeshort', 'core_langconfig'));
    }
}
