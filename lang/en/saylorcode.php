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
 * Language strings for mod_saylorcode.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitymode'] = 'Activity mode';
$string['activitymode_help'] = 'A guided lesson leads a student through small sequenced steps. A challenge presents one problem with less scaffolding. A project is a larger open task. A playground is an ungraded workspace for experimenting.';
$string['allowdownload'] = 'Allow students to download their code';
$string['allowhints'] = 'Allow progressive hints';
$string['allowsolution'] = 'Allow students to view the reference solution';
$string['allowsolution_help'] = 'When enabled, a student may reveal the reference approach. Viewing is recorded, and the activity may be marked as completed with the solution rather than independently. This is normally left off for graded work.';
$string['attemptsubmitted'] = 'Your attempt has been submitted.';
$string['check'] = 'Check';
$string['completiondetail:minscore'] = 'Score at least {$a}%';
$string['completiondetail:passtests'] = 'Pass the required tests';
$string['completionminscore'] = 'Require a minimum score';
$string['completionpasstests'] = 'Require the required tests to pass';
$string['console'] = 'Console';
$string['errorcompile'] = 'Your code did not compile.';
$string['errormemorylimit'] = 'Your program used too much memory and was stopped.';
$string['erroroutputlimit'] = 'Your program produced too much output. The output below has been shortened.';
$string['errorprocesslimit'] = 'Your program tried to do something that is not allowed in this environment.';
$string['errorruntime'] = 'Your program started but stopped with an error.';
$string['errortimeout'] = 'Your program ran for too long and was stopped. Check for a loop that never ends.';
$string['feedback'] = 'Feedback';
$string['gradingmode'] = 'Grading';
$string['gradingmode_help'] = 'Choose whether this activity is ungraded, tracks completion only, is scored automatically from its tests, is graded by hand, or combines an automatic score with an instructor mark.';
$string['gradingmodecompletion'] = 'Completion only';
$string['gradingmodemanual'] = 'Manual grading';
$string['gradingmodemixed'] = 'Automatic score plus instructor mark';
$string['gradingmodenone'] = 'Not graded';
$string['gradingmodetests'] = 'Automatic points from tests';
$string['instructions'] = 'Instructions';
$string['maxattempts'] = 'Attempts allowed';
$string['maxattempts_help'] = 'How many attempts a student may make. Leave at unlimited for practice activities.';
$string['modeguided'] = 'Guided lesson';
$string['modechallenge'] = 'Coding challenge';
$string['modeplayground'] = 'Playground';
$string['modeproject'] = 'Project';
$string['modulename'] = 'Saylor Code Studio';
$string['modulename_help'] = 'Saylor Code Studio provides a browser based coding environment inside your course. Students read instructions, edit code, run it, and receive feedback without installing software or creating an external account.

Student code runs on a separate sandbox service, never on the Moodle server.';
$string['modulenameplural'] = 'Saylor Code Studio activities';
$string['noinstances'] = 'There are no Saylor Code Studio activities in this course.';
$string['nopermissiontoattempt'] = 'You do not have permission to attempt this activity.';
$string['pluginadministration'] = 'Saylor Code Studio administration';
$string['pluginname'] = 'Saylor Code Studio';
$string['privacy:metadata:attempts'] = 'Records of a student attempt at a coding activity.';
$string['privacy:metadata:attempts:score'] = 'The score achieved on the attempt.';
$string['privacy:metadata:attempts:status'] = 'Whether the attempt is in progress, submitted or completed.';
$string['privacy:metadata:attempts:timestarted'] = 'When the attempt was started.';
$string['privacy:metadata:attempts:userid'] = 'The student the attempt belongs to.';
$string['privacy:metadata:executions'] = 'Sanitised records of code executions. These hold timing and outcome only, never source code.';
$string['privacy:metadata:executions:state'] = 'The outcome of the execution.';
$string['privacy:metadata:executions:timecreated'] = 'When the execution was requested.';
$string['privacy:metadata:snapshots'] = 'Point in time copies of the code a student wrote.';
$string['privacy:metadata:snapshots:files'] = 'The source files saved at that moment.';
$string['privacy:metadata:snapshots:timecreated'] = 'When the snapshot was taken.';
$string['privacy:metadata:stepattempts'] = 'Per step progress within an attempt.';
$string['privacy:metadata:stepattempts:hintsused'] = 'How many hints the student revealed.';
$string['privacy:metadata:stepattempts:status'] = 'Whether the step is complete.';
$string['profileid'] = 'Language';
$string['profileid_help'] = 'The runtime this activity uses. Only languages an administrator has enabled appear here.';
$string['reset'] = 'Reset';
$string['resetattempts'] = 'Delete all attempts, snapshots and execution records';
$string['resetconfirm'] = 'Resetting restores the starter code. A snapshot of your current work is saved first, so you can recover it.';
$string['run'] = 'Run';
$string['runnerunavailable'] = 'The code runner is temporarily unavailable. Your work has been saved. Please try again shortly.';
$string['saved'] = 'Saved';
$string['saving'] = 'Saving';
$string['saylorcode:addinstance'] = 'Add a new Saylor Code Studio activity';
$string['saylorcode:attempt'] = 'Attempt a Saylor Code Studio activity';
$string['saylorcode:grade'] = 'Grade Saylor Code Studio attempts';
$string['saylorcode:manageactivities'] = 'Manage Saylor Code Studio activity settings';
$string['saylorcode:reviewallattempts'] = 'Review all student attempts';
$string['saylorcode:reviewownattempts'] = 'Review your own attempts';
$string['saylorcode:view'] = 'View a Saylor Code Studio activity';
$string['saylorcode:viewsolutions'] = 'View reference solutions';
$string['stableid'] = 'Exercise stable ID';
$string['stableid_help'] = 'The permanent identifier of the exercise this activity presents, for example CS101-U05-E03. The exercise is stored once centrally and referenced here, so correcting it in one place updates every use.';
$string['stableidinvalid'] = 'This is not a valid exercise ID. The expected form is COURSE-Unn-Enn, for example CS101-U05-E03.';
$string['submit'] = 'Submit';
$string['submitconfirm'] = 'Submitting records an official attempt. Do you want to continue?';
$string['tests'] = 'Tests';
$string['unlimitedattempts'] = 'Unlimited';
$string['versionpinned'] = 'Pinned version';
$string['versionpolicy'] = 'Exercise version';
$string['versionpolicy_help'] = 'Latest approved keeps this activity current as the exercise is improved. Pinned holds it at one version, which is the safer choice for graded work because it prevents a content change from altering a live assessment.';
$string['versionlatest'] = 'Latest approved';
