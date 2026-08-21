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

namespace mod_saylorcode;

use mod_saylorcode\completion\custom_completion;

/**
 * The activity's own completion rules.
 *
 * These rules were configurable and inert. The activity declares
 * FEATURE_COMPLETION_HAS_RULES and the form offers both of them, but the class
 * Moodle resolves them through did not exist, and a missing class there is
 * skipped in silence. A teacher could require students to pass the tests, and
 * students who passed every test were never marked complete.
 *
 * The test that matters most is the one asserting the class is resolvable at
 * all, because that is the failure that produced no symptom.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    /**
     * Build an activity, a user, and an attempt with a given score.
     *
     * @param array $settings Activity settings to override.
     * @param float|null $score The best score to record, or null for no submission.
     * @return array [cm_info, userid]
     */
    protected function scenario(array $settings, ?float $score) {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('saylorcode', array_merge([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ], $settings));

        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        if ($score !== null) {
            $DB->insert_record('saylorcode_attempts', (object) [
                'saylorcodeid' => $instance->id,
                'userid' => $user->id,
                'attemptnumber' => 1,
                'status' => 'submitted',
                'score' => $score,
                'hintsused' => 0,
                'solutionviewed' => 0,
                'timestarted' => time(),
                'timesubmitted' => time(),
                'timemodified' => time(),
            ]);
        }

        $cm = get_coursemodule_from_instance('saylorcode', $instance->id, $course->id, false, MUST_EXIST);
        $cminfo = \cm_info::create($cm, $user->id);

        return [$cminfo, $user->id];
    }

    /**
     * Moodle can find the class at all.
     *
     * This is the one that was failing. get_cm_completion_class() returns null
     * for a module without this class and completion_info then skips every
     * custom rule without complaining, so both rules were configurable and
     * inert with nothing to indicate it.
     *
     * @return void
     */
    public function test_moodle_can_resolve_the_completion_class(): void {
        $this->resetAfterTest();

        $resolved = \core_completion\activity_custom_completion::get_cm_completion_class('saylorcode');

        $this->assertNotNull($resolved, 'Moodle cannot find the class, so the custom rules never run.');
        $this->assertSame(custom_completion::class, $resolved);
    }

    /**
     * The declared rules match what the form offers.
     *
     * A rule offered on the form but missing here is unreachable, which is the
     * same silent failure in a smaller shape.
     *
     * @return void
     */
    public function test_the_declared_rules_match_the_form(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/saylorcode/lib.php');

        $this->assertSame(
            saylorcode_get_completion_rule_descriptions(),
            custom_completion::get_defined_custom_rules()
        );
    }

    /**
     * Passing everything completes the activity.
     *
     * @return void
     */
    public function test_passing_every_case_completes(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario(['completionpasstests' => 1], 1.0);

        $completion = new custom_completion($cm, $userid);

        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionpasstests'));
    }

    /**
     * Passing nearly everything does not.
     *
     * @return void
     */
    public function test_passing_most_cases_does_not_complete(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario(['completionpasstests' => 1], 0.75);

        $completion = new custom_completion($cm, $userid);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionpasstests'));
    }

    /**
     * Never submitting is never complete.
     *
     * A rule about how well the student did cannot be satisfied by their not
     * having done it.
     *
     * @return void
     */
    public function test_no_submission_is_not_complete(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario(['completionpasstests' => 1], null);

        $completion = new custom_completion($cm, $userid);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionpasstests'));
    }

    /**
     * The minimum score rule compares against the configured percentage.
     *
     * @return void
     */
    public function test_the_minimum_score_rule_uses_the_configured_percentage(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario(['completionminscore' => 60], 0.6);
        $this->assertEquals(
            COMPLETION_COMPLETE,
            (new custom_completion($cm, $userid))->get_state('completionminscore'),
            'Exactly the required score should count as reaching it.'
        );

        [$cm2, $userid2] = $this->scenario(['completionminscore' => 60], 0.59);
        $this->assertEquals(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cm2, $userid2))->get_state('completionminscore')
        );
    }

    /**
     * An unset minimum score is not a rule this activity uses.
     *
     * My first version of this asserted that get_state() returned incomplete
     * for a threshold of zero. That is a call Moodle never makes:
     * is_available() requires a non-empty value, so a rule left at zero is
     * refused before it is judged. Asserting an answer to a question nobody
     * asks is not coverage, so this asserts the real behaviour instead -- the
     * rule simply does not take part.
     *
     * @return void
     */
    public function test_an_unset_minimum_score_is_not_in_play(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario(['completionpasstests' => 1, 'completionminscore' => 0], 0.1);

        $completion = new custom_completion($cm, $userid);

        $this->assertNotContains('completionminscore', $completion->get_available_custom_rules());
        $this->assertContains('completionpasstests', $completion->get_available_custom_rules());
    }

    /**
     * A near miss on uneven weights is not a pass.
     *
     * Found in review. The comparison allowed anything at or above 0.9999,
     * which sounds like a rounding allowance and is not: a case of weight 9999
     * passing while one of weight 1 fails scores 0.99990, so a required case
     * could fail and the rule still complete. The column is number(10,5), so a
     * real full pass is exactly 1.00000 and no allowance is needed.
     *
     * @return void
     */
    public function test_a_near_miss_on_uneven_weights_is_not_a_pass(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario(['completionpasstests' => 1], 0.9999);

        $this->assertEquals(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cm, $userid))->get_state('completionpasstests'),
            'A score short of every case passing was accepted as passing every case.'
        );
    }

    /**
     * A guided lesson is judged on the lesson, not on one step.
     *
     * Found in review, and the more serious of the two. Every step submission
     * writes the attempt's score, so passing the first step of ten stores a one
     * and the best-score query read that as the whole activity. The lesson
     * would complete with nine steps never opened.
     *
     * @return void
     */
    public function test_a_guided_lesson_is_not_complete_after_one_step(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'activitymode' => 'guided',
            'completionpasstests' => 1,
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Three steps.
        $stepids = [];
        foreach ([1, 2, 3] as $order) {
            $stepids[] = $DB->insert_record('saylorcode_steps', (object) [
                'saylorcodeid' => $instance->id,
                'sortorder' => $order,
                'title' => "Step {$order}",
                'steptype' => 'checkpoint',
                'instructions' => '',
                'instructionsformat' => FORMAT_HTML,
                'completionrule' => 'passtests',
                'carryforward' => 1,
                'allowrevisit' => 1,
                'points' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        // One step passed, and the attempt score written as a submission does.
        $attemptid = $DB->insert_record('saylorcode_attempts', (object) [
            'saylorcodeid' => $instance->id,
            'userid' => $user->id,
            'attemptnumber' => 1,
            'status' => 'submitted',
            'score' => 1.0,
            'hintsused' => 0,
            'solutionviewed' => 0,
            'timestarted' => time(),
            'timesubmitted' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('saylorcode_stepattempts', (object) [
            'attemptid' => $attemptid,
            'stepid' => $stepids[0],
            'status' => 'complete',
            'runcount' => 1,
            'checkcount' => 1,
            'submitcount' => 1,
            'hintsused' => 0,
            'solutionviewed' => 0,
            'bestscore' => 1.0,
            'latestscore' => 1.0,
            'timecompleted' => time(),
            'timemodified' => time(),
        ]);

        $cm = \cm_info::create(
            get_coursemodule_from_instance('saylorcode', $instance->id, $course->id, false, MUST_EXIST),
            $user->id
        );

        $this->assertEquals(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cm, $user->id))->get_state('completionpasstests'),
            'One step of three completed the whole lesson.'
        );

        // Finish the other two and it should complete.
        foreach ([$stepids[1], $stepids[2]] as $stepid) {
            $DB->insert_record('saylorcode_stepattempts', (object) [
                'attemptid' => $attemptid,
                'stepid' => $stepid,
                'status' => 'complete',
                'runcount' => 1,
                'checkcount' => 1,
                'submitcount' => 1,
                'hintsused' => 0,
                'solutionviewed' => 0,
                'bestscore' => 1.0,
                'latestscore' => 1.0,
                'timecompleted' => time(),
                'timemodified' => time(),
            ]);
        }

        $this->assertEquals(
            COMPLETION_COMPLETE,
            (new custom_completion($cm, $user->id))->get_state('completionpasstests'),
            'Every step complete should complete the lesson.'
        );
    }

    /**
     * A guided lesson with no steps is not complete.
     *
     * Dividing by the step count would either error or, worse, report every
     * newly created guided activity as finished.
     *
     * @return void
     */
    public function test_a_guided_lesson_with_no_steps_is_not_complete(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario([
            'activitymode' => 'guided',
            'completionpasstests' => 1,
        ], 1.0);

        $this->assertEquals(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cm, $userid))->get_state('completionpasstests')
        );
    }

    /**
     * Each rule has something to show a student.
     *
     * @return void
     */
    public function test_every_rule_has_a_description(): void {
        $this->resetAfterTest();

        [$cm, $userid] = $this->scenario(['completionpasstests' => 1, 'completionminscore' => 40], 1.0);

        $descriptions = (new custom_completion($cm, $userid))->get_custom_rule_descriptions();

        foreach (custom_completion::get_defined_custom_rules() as $rule) {
            $this->assertArrayHasKey($rule, $descriptions);
            $this->assertNotEmpty($descriptions[$rule]);
        }

        $this->assertStringContainsString('40', $descriptions['completionminscore']);
    }
}
