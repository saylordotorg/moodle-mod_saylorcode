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
 * Preview an exercise as a student meets it.
 *
 * Staff have no attempt of their own, so opening the activity shows them the
 * "you may not attempt this" notice rather than the workspace. This page is
 * how an author sees what they actually built.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'saylorcode');
$moduleinstance = $DB->get_record('saylorcode', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Previewing is an authoring act, so it is gated on being able to build one of
// these rather than on being able to view it.
require_capability('mod/saylorcode:addinstance', $context);

$PAGE->set_url('/mod/saylorcode/preview.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('previewtitle', 'mod_saylorcode', format_string($moduleinstance->name)));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($moduleinstance);

$renderer = $PAGE->get_renderer('mod_saylorcode');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('previewtitle', 'mod_saylorcode', format_string($moduleinstance->name)));

// Said plainly and before the workspace, because an inert Run button is
// otherwise indistinguishable from a broken one.
echo $OUTPUT->notification(get_string('previewnotice', 'mod_saylorcode'), \core\output\notification::NOTIFY_INFO);

echo html_writer::div(
    html_writer::link(
        new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]),
        get_string('previewedit', 'mod_saylorcode'),
        ['class' => 'btn btn-secondary']
    ) . ' ' . html_writer::link(
        new moodle_url('/mod/saylorcode/library.php'),
        get_string('previewbacktocatalogue', 'mod_saylorcode'),
        ['class' => 'btn btn-link']
    ),
    'mb-3'
);

if (!empty($moduleinstance->intro)) {
    echo $OUTPUT->box(
        format_module_intro('saylorcode', $moduleinstance, $cm->id),
        'generalbox mod_introbox',
        'saylorcodeintro'
    );
}

echo $renderer->render_preview($moduleinstance, $cm, $context);

echo $OUTPUT->footer();
