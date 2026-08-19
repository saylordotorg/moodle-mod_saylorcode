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
 * Library of interface functions and constants for mod_saylorcode.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare which Moodle features this activity supports.
 *
 * @param string $feature Constant from the FEATURE_* family.
 * @return mixed True or false for boolean features, a string for others, null if unknown.
 */
function saylorcode_supports(string $feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;

        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_ADVANCED_GRADING:
            return false;

        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;

        default:
            return null;
    }
}

/**
 * Create a new activity instance.
 *
 * @param stdClass $moduleinstance Data from the module form.
 * @param mod_saylorcode_mod_form|null $mform The form itself, if submitted.
 * @return int The id of the new instance.
 */
function saylorcode_add_instance(stdClass $moduleinstance, $mform = null): int {
    global $DB;

    $moduleinstance->timecreated = time();
    $moduleinstance->timemodified = $moduleinstance->timecreated;

    $moduleinstance->id = $DB->insert_record('saylorcode', $moduleinstance);

    saylorcode_grade_item_update($moduleinstance);

    return $moduleinstance->id;
}

/**
 * Update an existing activity instance.
 *
 * @param stdClass $moduleinstance Data from the module form.
 * @param mod_saylorcode_mod_form|null $mform The form itself, if submitted.
 * @return bool
 */
function saylorcode_update_instance(stdClass $moduleinstance, $mform = null): bool {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;

    $updated = $DB->update_record('saylorcode', $moduleinstance);

    saylorcode_grade_item_update($moduleinstance);

    return $updated;
}

/**
 * Delete an activity instance and everything belonging to it.
 *
 * @param int $id Instance id.
 * @return bool
 */
function saylorcode_delete_instance(int $id): bool {
    global $DB;

    $instance = $DB->get_record('saylorcode', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    // Delete from the leaves inwards so that no orphan rows are left behind if
    // one of these calls fails part way through.
    $attemptids = $DB->get_fieldset_select('saylorcode_attempts', 'id', 'saylorcodeid = ?', [$id]);
    if (!empty($attemptids)) {
        [$insql, $params] = $DB->get_in_or_equal($attemptids);
        $DB->delete_records_select('saylorcode_snapshots', "attemptid $insql", $params);
        $DB->delete_records_select('saylorcode_stepattempts', "attemptid $insql", $params);
        $DB->delete_records_select('saylorcode_executions', "attemptid $insql", $params);
    }

    $DB->delete_records('saylorcode_attempts', ['saylorcodeid' => $id]);
    $DB->delete_records('saylorcode_steps', ['saylorcodeid' => $id]);
    $DB->delete_records('saylorcode', ['id' => $id]);

    saylorcode_grade_item_delete($instance);

    return true;
}

/**
 * Create or update the grade item for an activity instance.
 *
 * @param stdClass $moduleinstance The activity instance.
 * @param mixed $grades Grades to push, or null.
 * @return int GRADE_UPDATE_OK or a failure constant.
 */
function saylorcode_grade_item_update(stdClass $moduleinstance, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname' => clean_param($moduleinstance->name, PARAM_NOTAGS),
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => $moduleinstance->grade ?? 100,
        'grademin' => 0,
    ];

    if (($moduleinstance->gradingmode ?? '') === 'none' || (int) ($moduleinstance->grade ?? 0) === 0) {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/saylorcode',
        $moduleinstance->course,
        'mod',
        'saylorcode',
        $moduleinstance->id,
        0,
        $grades,
        $item
    );
}

/**
 * Remove the grade item for an activity instance.
 *
 * @param stdClass $moduleinstance The activity instance.
 * @return int GRADE_UPDATE_OK or a failure constant.
 */
function saylorcode_grade_item_delete(stdClass $moduleinstance): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update(
        'mod/saylorcode',
        $moduleinstance->course,
        'mod',
        'saylorcode',
        $moduleinstance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Push grades for one or all users into the gradebook.
 *
 * @param stdClass $moduleinstance The activity instance.
 * @param int $userid Update one user, or 0 for all.
 * @param bool $nullifnone Insert a null grade when the user has no attempt.
 */
function saylorcode_update_grades(stdClass $moduleinstance, int $userid = 0, bool $nullifnone = true): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if (($moduleinstance->gradingmode ?? '') === 'none') {
        saylorcode_grade_item_update($moduleinstance);
        return;
    }

    $params = ['saylorcodeid' => $moduleinstance->id];
    if ($userid) {
        $params['userid'] = $userid;
    }

    $attempts = $DB->get_records('saylorcode_attempts', $params);

    $grades = [];
    foreach ($attempts as $attempt) {
        if ($attempt->score === null) {
            continue;
        }
        // Keep the best score across attempts, which is the default policy.
        $scaled = (float) $attempt->score * (float) ($moduleinstance->grade ?? 100);
        if (!isset($grades[$attempt->userid]) || $scaled > $grades[$attempt->userid]['rawgrade']) {
            $grades[$attempt->userid] = [
                'userid' => $attempt->userid,
                'rawgrade' => $scaled,
            ];
        }
    }

    if (empty($grades) && $userid && $nullifnone) {
        $grades[$userid] = ['userid' => $userid, 'rawgrade' => null];
    }

    saylorcode_grade_item_update($moduleinstance, $grades ?: null);
}

/**
 * Describe the custom completion rules this activity offers.
 *
 * @return string[]
 */
function saylorcode_get_completion_rule_descriptions(): array {
    return ['completionpasstests', 'completionminscore'];
}

/**
 * Reset user data when a course is reset.
 *
 * @param stdClass $data The reset form data.
 * @return array Status entries for the reset report.
 */
function saylorcode_reset_userdata(stdClass $data): array {
    global $DB;

    $status = [];
    if (empty($data->reset_saylorcode_attempts)) {
        return $status;
    }

    $instanceids = $DB->get_fieldset_select('saylorcode', 'id', 'course = ?', [$data->courseid]);
    if (!empty($instanceids)) {
        [$insql, $params] = $DB->get_in_or_equal($instanceids);
        $attemptids = $DB->get_fieldset_select('saylorcode_attempts', 'id', "saylorcodeid $insql", $params);

        if (!empty($attemptids)) {
            [$attemptsql, $attemptparams] = $DB->get_in_or_equal($attemptids);
            $DB->delete_records_select('saylorcode_snapshots', "attemptid $attemptsql", $attemptparams);
            $DB->delete_records_select('saylorcode_stepattempts', "attemptid $attemptsql", $attemptparams);
            $DB->delete_records_select('saylorcode_executions', "attemptid $attemptsql", $attemptparams);
        }

        $DB->delete_records_select('saylorcode_attempts', "saylorcodeid $insql", $params);
    }

    $status[] = [
        'component' => get_string('modulenameplural', 'mod_saylorcode'),
        'item' => get_string('resetattempts', 'mod_saylorcode'),
        'error' => false,
    ];

    return $status;
}

/**
 * Add the reset options to the course reset form.
 *
 * @param MoodleQuickForm $mform The reset form.
 */
function saylorcode_reset_course_form_definition(MoodleQuickForm $mform): void {
    $mform->addElement('header', 'saylorcodeheader', get_string('modulenameplural', 'mod_saylorcode'));
    $mform->addElement('advcheckbox', 'reset_saylorcode_attempts', get_string('resetattempts', 'mod_saylorcode'));
}

/**
 * Add the exercise catalogue to a course's navigation.
 *
 * The catalogue spans the whole site, but a teacher reaches for it while
 * working inside a course, so that is where the way in belongs. Site
 * administration also carries a link, for people who never open a course.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 */
function saylorcode_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    // The capability is held at the system level because the page reaches
    // across every course, not only this one.
    if (!has_capability('local/saylorcode:viewlibrary', context_system::instance())) {
        return;
    }

    $navigation->add(
        get_string('catalogue', 'mod_saylorcode'),
        new moodle_url('/mod/saylorcode/library.php'),
        navigation_node::TYPE_SETTING,
        null,
        'saylorcodecatalogue',
        new pix_icon('icon', '', 'mod_saylorcode')
    );
}
