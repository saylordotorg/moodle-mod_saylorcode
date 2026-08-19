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
 * Display a Saylor Code Studio activity.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$s = optional_param('s', 0, PARAM_INT);

if ($id) {
    [$course, $cm] = get_course_and_cm_from_cmid($id, 'saylorcode');
    $moduleinstance = $DB->get_record('saylorcode', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('saylorcode', ['id' => $s], '*', MUST_EXIST);
    [$course, $cm] = get_course_and_cm_from_instance($moduleinstance, 'saylorcode');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/saylorcode:view', $context);

// Record the view before rendering, so that a later rendering failure does not
// lose the fact that the student opened the activity.
$event = \mod_saylorcode\event\course_module_viewed::create([
    'objectid' => $moduleinstance->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('saylorcode', $moduleinstance);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/saylorcode/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($moduleinstance);

$renderer = $PAGE->get_renderer('mod_saylorcode');

echo $OUTPUT->header();

if (!empty($moduleinstance->intro)) {
    echo $OUTPUT->box(
        format_module_intro('saylorcode', $moduleinstance, $cm->id),
        'generalbox mod_introbox',
        'saylorcodeintro'
    );
}

// Staff hold no attempt, so this page shows them a notice where the workspace
// would be. The way to see what they actually built belongs right there.
if (has_capability('mod/saylorcode:addinstance', $context)) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/saylorcode/preview.php', ['id' => $cm->id]),
            get_string('preview', 'mod_saylorcode'),
            ['class' => 'btn btn-secondary']
        ) . (has_capability('mod/saylorcode:manageactivities', $context)
            ? ' ' . html_writer::link(
                new moodle_url('/mod/saylorcode/steps.php', ['id' => $cm->id]),
                get_string('managesteps', 'mod_saylorcode'),
                ['class' => 'btn btn-secondary']
            )
            : ''),
        'mb-3'
    );
}

echo $renderer->render_activity($moduleinstance, $cm, $context);

echo $OUTPUT->footer();
