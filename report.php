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

/**
 * How a class is getting on with one activity.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_saylorcode\local\progress_report;
use mod_saylorcode\output\progress_table;

$cmid = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'saylorcode');
$instance = $DB->get_record('saylorcode', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/saylorcode:reviewallattempts', $context);

$pageurl = new moodle_url('/mod/saylorcode/report.php', ['id' => $cm->id]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('report', 'mod_saylorcode'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($instance);

$report = new progress_report($instance, $context);

$table = new progress_table('saylorcode-progress-' . $cm->id, $pageurl, $report);
$table->is_downloading($download, 'saylorcode-progress-' . $cm->id, get_string('report', 'mod_saylorcode'));

if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('reportfor', 'mod_saylorcode', format_string($instance->name)));

    $totals = $report->get_totals();

    echo html_writer::div(
        get_string('reporttotals', 'mod_saylorcode', (object) $totals),
        'saylorcode-report-totals'
    );

    $analytics = $report->get_analytics();

    $measures = [
        'analyticscompletionrate' => $analytics['completionrate'],
        'analyticsfirsttry' => $analytics['firsttrypassrate'],
        'analyticshintrate' => $analytics['hintrate'],
        'analyticssolutionrate' => $analytics['solutionrate'],
    ];

    $cards = '';
    foreach ($measures as $key => $value) {
        // A missing denominator reads as "nobody has done this yet", which is
        // a different fact from nought per cent.
        $shown = $value === null ? get_string('analyticsnodata', 'mod_saylorcode') : $value . '%';

        $cards .= html_writer::div(
            html_writer::div($shown, 'saylorcode-measure-value')
                . html_writer::div(get_string($key, 'mod_saylorcode'), 'saylorcode-measure-label'),
            'saylorcode-measure'
        );
    }

    $median = $analytics['medianchecks'];
    $cards .= html_writer::div(
        html_writer::div(
            $median === null ? get_string('analyticsnodata', 'mod_saylorcode') : format_float($median, 1),
            'saylorcode-measure-value'
        ) . html_writer::div(get_string('analyticsmedianchecks', 'mod_saylorcode'), 'saylorcode-measure-label'),
        'saylorcode-measure'
    );

    echo html_writer::div($cards, 'saylorcode-measures');

    // The step view comes first for a guided lesson, because a step everyone
    // is stuck on is a fact about the lesson, and worth knowing before reading
    // a list of names.
    $steps = $report->get_step_summary();

    if ($steps) {
        echo $OUTPUT->heading(get_string('reportsteps', 'mod_saylorcode'), 3);

        $steptable = new html_table();
        $steptable->head = [
            get_string('stepnumber', 'mod_saylorcode'),
            get_string('steptitle', 'mod_saylorcode'),
            get_string('stepcompletionrule', 'mod_saylorcode'),
            get_string('reportreached', 'mod_saylorcode'),
            get_string('reportcompleted', 'mod_saylorcode'),
            get_string('reportstuck', 'mod_saylorcode'),
            get_string('reportavgchecks', 'mod_saylorcode'),
        ];
        $steptable->attributes['class'] = 'generaltable saylorcode-stepreport';

        foreach ($steps as $step) {
            $stuck = $step['stuck'] > 0
                ? html_writer::span($step['stuck'], 'saylorcode-progress-stuck')
                : '0';

            $steptable->data[] = [
                $step['number'],
                format_string($step['title']),
                get_string('steprule' . $step['completionrule'], 'mod_saylorcode'),
                $step['reached'],
                $step['completed'],
                $stuck,
                $step['avgchecks'],
            ];
        }

        echo html_writer::table($steptable);
    }

    echo $OUTPUT->heading(get_string('reportstudents', 'mod_saylorcode'), 3);
}

$table->out(50, true);

if (!$table->is_downloading()) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/saylorcode/view.php', ['id' => $cm->id]),
            get_string('backtoactivity', 'mod_saylorcode'),
            ['class' => 'btn btn-link']
        ),
        'mt-3'
    );

    echo $OUTPUT->footer();
}
