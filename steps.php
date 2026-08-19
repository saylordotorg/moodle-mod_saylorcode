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
 * Build the steps of a guided lesson.
 *
 * Steps were only insertable directly into the database until now, which meant
 * the guided lesson existed but nobody could author one.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_saylorcode\form\step_form;
use mod_saylorcode\local\step_editor;

$cmid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$stepid = optional_param('stepid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'saylorcode');
$instance = $DB->get_record('saylorcode', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// The write capability held at this module, not the course level right to
// create one. A role can be allowed to add activities and still be denied
// management of a particular one, and editing steps deletes student progress.
require_capability('mod/saylorcode:manageactivities', $context);

$pageurl = new moodle_url('/mod/saylorcode/steps.php', ['id' => $cm->id]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('steps', 'mod_saylorcode'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($instance);

$editor = new step_editor($instance);

// Actions that change something require a session key, because they are
// reachable by a plain link and would otherwise be triggerable from elsewhere.
if (in_array($action, ['delete', 'moveup', 'movedown'], true)) {
    require_sesskey();

    if ($action === 'delete') {
        if ($editor->delete($stepid)) {
            redirect(
                $pageurl,
                get_string('stepdeleted', 'mod_saylorcode'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    } else {
        $editor->move($stepid, $action === 'moveup' ? -1 : 1);
    }

    redirect($pageurl);
}

if ($action === 'add' || $action === 'edit') {
    $step = $action === 'edit' ? $editor->get_step($stepid) : null;

    if ($action === 'edit' && $step === null) {
        throw new moodle_exception('stepnotfound', 'mod_saylorcode');
    }

    $form = new step_form(
        new moodle_url('/mod/saylorcode/steps.php', ['id' => $cm->id, 'action' => $action, 'stepid' => $stepid]),
        ['cmid' => $cm->id, 'stepid' => $stepid]
    );

    if ($form->is_cancelled()) {
        redirect($pageurl);
    }

    if ($data = $form->get_data()) {
        if ($action === 'edit') {
            $editor->update($stepid, $data);
            $message = get_string('stepsaved', 'mod_saylorcode');
        } else {
            $editor->create($data);
            $message = get_string('stepadded', 'mod_saylorcode');
        }

        redirect($pageurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($step !== null) {
        $form->set_data([
            'id' => $cm->id,
            'stepid' => $stepid,
            'title' => $step->title,
            'steptype' => $step->steptype,
            'sectiontitle' => $step->sectiontitle,
            'instructions' => [
                'text' => $step->instructions,
                'format' => $step->instructionsformat,
            ],
            'completionrule' => $step->completionrule,
            'carryforward' => $step->carryforward,
            'allowrevisit' => $step->allowrevisit,
            'stableid' => $step->stableid,
            'versionpolicy' => $step->versionpolicy,
            'pinnedversion' => $step->pinnedversion,
            'points' => $step->points,
        ]);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'edit' ? 'stepedit' : 'stepadd', 'mod_saylorcode'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$steps = $editor->get_steps();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('stepsfor', 'mod_saylorcode', format_string($instance->name)));

if (!$steps) {
    echo $OUTPUT->notification(get_string('stepsnone', 'mod_saylorcode'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('stepnumber', 'mod_saylorcode'),
        get_string('steptitle', 'mod_saylorcode'),
        get_string('steptype', 'mod_saylorcode'),
        get_string('stepcompletionrule', 'mod_saylorcode'),
        get_string('stepcarryforward', 'mod_saylorcode'),
        get_string('stepexercise', 'mod_saylorcode'),
        get_string('stepactions', 'mod_saylorcode'),
    ];
    $table->attributes['class'] = 'generaltable saylorcode-steps';

    $number = 0;
    $last = count($steps);

    foreach ($steps as $step) {
        $number++;

        $actions = [];

        $actions[] = html_writer::link(
            new moodle_url($pageurl, ['action' => 'edit', 'stepid' => $step->id]),
            get_string('edit')
        );

        if ($number > 1) {
            $actions[] = html_writer::link(
                new moodle_url($pageurl, ['action' => 'moveup', 'stepid' => $step->id, 'sesskey' => sesskey()]),
                get_string('moveup')
            );
        }

        if ($number < $last) {
            $actions[] = html_writer::link(
                new moodle_url($pageurl, ['action' => 'movedown', 'stepid' => $step->id, 'sesskey' => sesskey()]),
                get_string('movedown')
            );
        }

        // A real confirmation rather than a data attribute nothing reads.
        // Deleting a step also discards every student's progress on it.
        $actions[] = $OUTPUT->action_link(
            new moodle_url($pageurl, ['action' => 'delete', 'stepid' => $step->id, 'sesskey' => sesskey()]),
            get_string('delete'),
            new confirm_action(get_string('stepdeleteconfirm', 'mod_saylorcode'))
        );

        // What this step actually resolves to. An author who typed a reference
        // for an exercise that is not published would otherwise see nothing
        // wrong while students silently got the activity's content instead.
        $resolved = \mod_saylorcode\local\content::for_step($instance, $step);
        $source = $resolved->get_source();

        if ($resolved->is_from_library()) {
            // A step with no reference of its own inherits the activity's, so
            // the label has to name that one rather than an empty string.
            $reference = trim((string) $step->stableid) !== ''
                ? $step->stableid
                : $instance->stableid;

            $exercise = html_writer::span(
                get_string('stepexerciseversion', 'mod_saylorcode', (object) [
                    'stableid' => $reference,
                    'version' => $resolved->get_version_number(),
                ]),
                'badge badge-success bg-success text-white'
            );
        } else if ($source === 'noreference') {
            $exercise = html_writer::span(get_string('stepexerciseactivity', 'mod_saylorcode'), 'text-muted');
        } else {
            $exercise = html_writer::span(
                get_string('stepexercise' . $source, 'mod_saylorcode', $step->stableid),
                'saylorcode-progress-stuck'
            );
        }

        $table->data[] = [
            $number,
            format_string($step->title),
            get_string('steptype' . $step->steptype, 'mod_saylorcode'),
            get_string('steprule' . $step->completionrule, 'mod_saylorcode'),
            empty($step->carryforward) ? get_string('no') : get_string('yes'),
            $exercise,
            implode(' &nbsp; ', $actions),
        ];
    }

    echo html_writer::table($table);
}

echo html_writer::div(
    html_writer::link(
        new moodle_url($pageurl, ['action' => 'add']),
        get_string('stepadd', 'mod_saylorcode'),
        ['class' => 'btn btn-primary']
    ) . ' ' . html_writer::link(
        new moodle_url('/mod/saylorcode/view.php', ['id' => $cm->id]),
        get_string('backtoactivity', 'mod_saylorcode'),
        ['class' => 'btn btn-link']
    ),
    'mt-3'
);

echo $OUTPUT->footer();
