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

$string['actionsheading'] = 'Code actions';
$string['activitymode'] = 'Activity mode';
$string['activitymode_help'] = 'A guided lesson leads a student through small sequenced steps. A challenge presents one problem with less scaffolding. A project is a larger open task. A playground is an ungraded workspace for experimenting.';
$string['allowdownload'] = 'Allow students to download their code';
$string['allowhints'] = 'Allow progressive hints';
$string['allowsolution'] = 'Allow students to view the reference solution';
$string['allowsolution_help'] = 'When enabled, a student may reveal the reference approach. Viewing is recorded, and the activity may be marked as completed with the solution rather than independently. This is normally left off for graded work.';
$string['attemptsubmitted'] = 'Your attempt has been submitted.';
$string['attemptsummary'] = 'Attempt {$a->attempt} · best {$a->best}/{$a->total}';
$string['check'] = 'Check';
$string['completiondetail:minscore'] = 'Score at least {$a}%';
$string['completiondetail:passtests'] = 'Pass the required tests';
$string['completionminscore'] = 'Require a minimum score';
$string['completionpasstests'] = 'Require the required tests to pass';
$string['console'] = 'Console';
$string['consoleempty'] = 'Output from your program appears here when you run it.';
$string['editorheading'] = 'Code editor';
$string['editorlabel'] = 'Code editor for {$a}. Press Tab to indent, or Escape then Tab to leave the editor.';
$string['entryfilename'] = 'Entry file name';
$string['entryfilename_help'] = 'The file the student edits, for example Main.java. For Java the class name must match this file name.';
$string['errorcompile'] = 'Your code did not compile.';
$string['errormemorylimit'] = 'Your program used too much memory and was stopped.';
$string['erroroutputlimit'] = 'Your program produced too much output. The output below has been shortened.';
$string['errorprocesslimit'] = 'Your program tried to do something that is not allowed in this environment.';
$string['errorruntime'] = 'Your program started but stopped with an error.';
$string['errortimeout'] = 'Your program ran for too long and was stopped. Check for a loop that never ends.';
$string['eventcode_executed'] = 'Code executed';
$string['eventcode_reset'] = 'Code reset';
$string['eventcode_saved'] = 'Code saved';
$string['expectedoutput'] = 'Expected output';
$string['feedback'] = 'Feedback';
$string['gradingmode'] = 'Grading';
$string['gradingmode_help'] = 'Choose whether this activity is ungraded, tracks completion only, is scored automatically from its tests, is graded by hand, or combines an automatic score with an instructor mark.';
$string['gradingmodecompletion'] = 'Completion only';
$string['gradingmodemanual'] = 'Manual grading';
$string['gradingmodemixed'] = 'Automatic score plus instructor mark';
$string['gradingmodenone'] = 'Not graded';
$string['gradingmodetests'] = 'Automatic points from tests';
$string['hideconsole'] = 'Hide the console';
$string['instructions'] = 'Instructions';
$string['layout'] = 'Layout';
$string['layout_help'] = 'Split view keeps the console beside the code and suits a wide page. Compact drawer hides the console until the student runs something, and takes the least room in a dense reading. Workbench tabs put output, input and feedback in a tab strip, and give the most guidance where a student needs to compare against expected output.';
$string['layoutdrawer'] = 'Compact drawer — console appears after a run';
$string['layoutsplit'] = 'Split view — console beside the code';
$string['layouttabs'] = 'Workbench tabs — output, input and feedback';
$string['maxattempts'] = 'Attempts allowed';
$string['maxattempts_help'] = 'How many attempts a student may make. Leave at unlimited for practice activities.';
$string['modechallenge'] = 'Coding challenge';
$string['modeguided'] = 'Guided lesson';
$string['modeplayground'] = 'Playground';
$string['modeproject'] = 'Project';
$string['modulename'] = 'Saylor Code Studio';
$string['modulename_help'] = 'Saylor Code Studio provides a browser based coding environment inside your course. Students read instructions, edit code, run it, and receive feedback without installing software or creating an external account.

Student code runs on a separate sandbox service, never on the Moodle server.';
$string['modulenameplural'] = 'Saylor Code Studio activities';
$string['noattempts'] = 'No attempts yet';
$string['noinstances'] = 'There are no Saylor Code Studio activities in this course.';
$string['nomoreattempts'] = 'You have used all the submissions allowed for this activity.';
$string['nopermissiontoattempt'] = 'You do not have permission to attempt this activity.';
$string['noverdict'] = 'Run a Check or Submit to see how your output compares.';
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
$string['ranat'] = 'ran {$a}';
$string['ratelimited'] = 'You already have code running. Wait for it to finish, then try again.';
$string['reset'] = 'Reset';
$string['resetattempts'] = 'Delete all attempts, snapshots and execution records';
$string['resetconfirm'] = 'Resetting restores the starter code. A snapshot of your current work is saved first, so you can recover it.';
$string['resetdone'] = 'The starter code has been restored. Your previous work was saved and can be recovered.';
$string['resultsheading'] = 'Results';
$string['run'] = 'Run';
$string['runnerunavailable'] = 'The code runner is temporarily unavailable. Your work has been saved. Please try again shortly.';
$string['running'] = 'Running your code';
$string['runshortcut'] = 'Ctrl ↵';
$string['saveconflict'] = 'This exercise was changed in another tab. Reload the page to see the newer version before saving again.';
$string['saved'] = 'Saved';
$string['savefailed'] = 'Not saved';
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
$string['startercode'] = 'Starter code';
$string['startercode_help'] = 'The code a student sees when they open the activity, and what Reset restores. This will come from the exercise library once that is available.';
$string['statuserror'] = '✗ Error';
$string['statusfailed'] = '✗ Failed';
$string['statuspassed'] = '✓ Passed';
$string['statusran'] = '✓ Ran';
$string['statusready'] = 'Ready';
$string['statusrunning'] = 'Running…';
$string['stdin'] = 'Standard input';
$string['stdin_help'] = 'Text supplied to your program as if you had typed it. Leave empty if your program does not read input.';
$string['stdinplaceholder'] = 'Text your program reads from System.in';
$string['submit'] = 'Submit';
$string['submitconfirm'] = 'Submitting records an official attempt. Do you want to continue?';
$string['tabfeedback'] = 'Feedback';
$string['tabinput'] = 'Input';
$string['taboutput'] = 'Output';
$string['testcases'] = 'Test cases';
$string['testcases_help'] = 'A JSON array of test cases. Each entry may set id, name, stdin, expected, ispublic, weight and feedback. Public cases are shown by Check, every case counts towards a submission, and hidden cases are never described to the student.';
$string['testcasesinvalid'] = 'Test cases must be a JSON array. Each entry needs at least an expected value.';
$string['tests'] = 'Tests';
$string['toggleeditortheme'] = 'Switch the editor between dark and light';
$string['unlimitedattempts'] = 'Unlimited';
$string['verdictchecked'] = '{$a->passed}/{$a->total} checks passed.';
$string['verdictsubmitted'] = 'Attempt {$a->attempt} recorded — {$a->passed}/{$a->total} checks passed.';
$string['versionlatest'] = 'Latest approved';
$string['versionpinned'] = 'Pinned version';
$string['versionpolicy'] = 'Exercise version';
$string['versionpolicy_help'] = 'Latest approved keeps this activity current as the exercise is improved. Pinned holds it at one version, which is the safer choice for graded work because it prevents a content change from altering a live assessment.';
$string['yourprogress'] = 'Your progress';
