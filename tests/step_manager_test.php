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

use mod_saylorcode\local\attempt_manager;
use mod_saylorcode\local\step_manager;

/**
 * Tests for progression through a guided lesson.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\step_manager
 */
final class step_manager_test extends \advanced_testcase {
    /** @var \stdClass The activity. */
    private $instance;

    /** @var step_manager The manager under test. */
    private $manager;

    /** @var int The attempt every test works against. */
    private $attemptid;

    /**
     * A guided activity with an attempt ready to go.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $course->id,
            'stableid' => 'CS101-U01-E01',
            'startercode' => "public class Main {\n}\n",
            'testcases' => json_encode([
                ['name' => 'One', 'expected' => 'x', 'ispublic' => 1, 'weight' => 1],
            ]),
        ]);

        $user = $this->getDataGenerator()->create_user();
        $attempt = (new attempt_manager($this->instance))->get_or_create_attempt((int) $user->id);
        $this->attemptid = (int) $attempt->id;

        $this->manager = new step_manager($this->instance);
    }

    /**
     * Add a step to the activity.
     *
     * @param array $fields Field overrides.
     * @return \stdClass The step.
     */
    private function add_step(array $fields = []): \stdClass {
        global $DB;

        static $order = 0;
        $order++;

        $step = (object) ($fields + [
            'saylorcodeid' => $this->instance->id,
            'sortorder' => $order,
            'steptype' => 'checkpoint',
            'title' => 'Step ' . $order,
            'instructions' => '',
            'instructionsformat' => FORMAT_HTML,
            'versionpolicy' => 'latest',
            'carryforward' => 1,
            'completionrule' => step_manager::RULE_PASSTESTS,
            'allowrevisit' => 1,
            'points' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $step->id = $DB->insert_record('saylorcode_steps', $step);

        return $DB->get_record('saylorcode_steps', ['id' => $step->id], '*', MUST_EXIST);
    }

    /**
     * An activity with no steps is not a guided lesson.
     */
    public function test_an_activity_without_steps_is_not_guided(): void {
        $this->assertFalse($this->manager->is_guided());
        $this->assertNull($this->manager->get_current_step($this->attemptid));
    }

    /**
     * Adding a step makes it one.
     */
    public function test_adding_a_step_makes_it_guided(): void {
        $step = $this->add_step();

        $this->assertTrue($this->manager->is_guided());
        $this->assertSame((int) $step->id, (int) $this->manager->get_current_step($this->attemptid)->id);
    }

    /**
     * A view rule is satisfied by arriving, and the arrival is recorded.
     *
     * Asserting only that the rule is satisfied is not enough: everything
     * downstream reads the stored status, so a step left in progress would
     * stall the lesson even though the rule says otherwise.
     */
    public function test_a_view_step_completes_on_arrival(): void {
        global $DB;

        $step = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);

        $this->assertTrue($this->manager->rule_satisfied($step, $stepattempt));
        $this->assertSame(step_manager::STATUS_COMPLETE, $stepattempt->status);

        $stored = $DB->get_record('saylorcode_stepattempts', ['id' => $stepattempt->id], '*', MUST_EXIST);
        $this->assertSame(step_manager::STATUS_COMPLETE, $stored->status);
        $this->assertNotEmpty($stored->timecompleted);
    }

    /**
     * A lesson made only of instruction steps can be finished by reading it.
     *
     * This is the case the arrival rule exists for. Without it the first step
     * stays current for ever and the lesson has no way to end.
     */
    public function test_an_instruction_only_lesson_can_be_completed(): void {
        $one = $this->add_step(['completionrule' => step_manager::RULE_VIEW, 'steptype' => 'instruction']);
        $two = $this->add_step(['completionrule' => step_manager::RULE_VIEW, 'steptype' => 'instruction']);

        $this->manager->get_or_create_step_attempt($this->attemptid, (int) $one->id);
        $this->assertSame((int) $two->id, (int) $this->manager->get_current_step($this->attemptid)->id);

        $this->manager->get_or_create_step_attempt($this->attemptid, (int) $two->id);
        $this->assertSame(100, $this->manager->get_progress($this->attemptid)['percent']);
    }

    /**
     * Arriving does not complete a step that asks for work.
     */
    public function test_arrival_does_not_complete_a_step_that_wants_work(): void {
        $run = $this->add_step(['completionrule' => step_manager::RULE_RUN]);
        $tests = $this->add_step(['completionrule' => step_manager::RULE_PASSTESTS]);
        $submit = $this->add_step(['completionrule' => step_manager::RULE_SUBMIT]);

        foreach ([$run, $tests, $submit] as $step) {
            $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);
            $this->assertSame(
                step_manager::STATUS_INPROGRESS,
                $stepattempt->status,
                'Rule ' . $step->completionrule . ' should not be satisfied by arriving.'
            );
        }
    }

    /**
     * A run rule is satisfied by running, whatever the code did.
     */
    public function test_a_run_step_completes_even_when_the_code_fails(): void {
        $step = $this->add_step(['completionrule' => step_manager::RULE_RUN]);
        $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);

        $this->assertFalse($this->manager->rule_satisfied($step, $stepattempt));

        $stepattempt = $this->manager->record_action($step, $stepattempt, 'run', false);

        $this->assertTrue($this->manager->rule_satisfied($step, $stepattempt));
        $this->assertSame(step_manager::STATUS_COMPLETE, $stepattempt->status);
    }

    /**
     * A passtests rule needs the tests to have passed, not merely to have run.
     */
    public function test_a_passtests_step_needs_a_pass(): void {
        $step = $this->add_step(['completionrule' => step_manager::RULE_PASSTESTS]);
        $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);

        $stepattempt = $this->manager->record_action($step, $stepattempt, 'check', false, 0.5);
        $this->assertSame(step_manager::STATUS_INPROGRESS, $stepattempt->status);

        $stepattempt = $this->manager->record_action($step, $stepattempt, 'check', true, 1.0);
        $this->assertSame(step_manager::STATUS_COMPLETE, $stepattempt->status);
    }

    /**
     * Once passed, a step stays passed.
     *
     * A student who solves a step and then breaks their own code while
     * exploring must not have the step taken back off them.
     */
    public function test_a_passed_step_is_not_lost_by_later_failure(): void {
        $step = $this->add_step(['completionrule' => step_manager::RULE_PASSTESTS]);
        $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);

        $stepattempt = $this->manager->record_action($step, $stepattempt, 'check', true, 1.0);
        $firstpassed = $stepattempt->timefirstpassed;

        $stepattempt = $this->manager->record_action($step, $stepattempt, 'check', false, 0.0);

        $this->assertSame(step_manager::STATUS_COMPLETE, $stepattempt->status);
        $this->assertEquals($firstpassed, $stepattempt->timefirstpassed);
        $this->assertEquals(1.0, (float) $stepattempt->bestscore);
        $this->assertEquals(0.0, (float) $stepattempt->latestscore);
    }

    /**
     * A step whose activity defines no tests cannot demand passing them.
     *
     * Otherwise an author who forgot the tests locks every student out of the
     * rest of the lesson, with no way forward.
     */
    public function test_a_passtests_step_without_tests_falls_back_to_running(): void {
        global $DB;

        $DB->set_field('saylorcode', 'testcases', '', ['id' => $this->instance->id]);
        $instance = $DB->get_record('saylorcode', ['id' => $this->instance->id], '*', MUST_EXIST);
        $manager = new step_manager($instance);

        $step = $this->add_step(['completionrule' => step_manager::RULE_PASSTESTS]);
        $stepattempt = $manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);

        $this->assertFalse($manager->rule_satisfied($step, $stepattempt));

        $stepattempt = $manager->record_action($step, $stepattempt, 'run', null);

        $this->assertTrue($manager->rule_satisfied($step, $stepattempt));
    }

    /**
     * The current step is the first one not finished.
     */
    public function test_the_current_step_is_the_first_incomplete_one(): void {
        $one = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $two = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $three = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);

        $this->assertSame((int) $one->id, (int) $this->manager->get_current_step($this->attemptid)->id);

        $first = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $one->id);
        $this->manager->record_action($one, $first, 'run');

        $this->assertSame((int) $two->id, (int) $this->manager->get_current_step($this->attemptid)->id);

        $second = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $two->id);
        $this->manager->record_action($two, $second, 'run');

        $this->assertSame((int) $three->id, (int) $this->manager->get_current_step($this->attemptid)->id);
    }

    /**
     * A finished lesson rests on its last step.
     */
    public function test_a_finished_lesson_stays_on_the_last_step(): void {
        $one = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $two = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);

        foreach ([$one, $two] as $step) {
            $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);
            $this->manager->record_action($step, $stepattempt, 'run');
        }

        $this->assertSame((int) $two->id, (int) $this->manager->get_current_step($this->attemptid)->id);
        $this->assertSame(100, $this->manager->get_progress($this->attemptid)['percent']);
    }

    /**
     * Steps beyond the current one are locked.
     */
    public function test_a_later_step_cannot_be_opened_early(): void {
        $one = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $two = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $three = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);

        $this->assertTrue($this->manager->can_open_step($this->attemptid, (int) $one->id));
        $this->assertFalse($this->manager->can_open_step($this->attemptid, (int) $two->id));
        $this->assertFalse($this->manager->can_open_step($this->attemptid, (int) $three->id));
    }

    /**
     * Going back is the author's decision.
     */
    public function test_revisiting_a_finished_step_follows_the_author_setting(): void {
        $open = $this->add_step(['completionrule' => step_manager::RULE_VIEW, 'allowrevisit' => 1]);
        $closed = $this->add_step(['completionrule' => step_manager::RULE_VIEW, 'allowrevisit' => 0]);
        $this->add_step(['completionrule' => step_manager::RULE_VIEW]);

        foreach ([$open, $closed] as $step) {
            $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);
            $this->manager->record_action($step, $stepattempt, 'run');
        }

        $this->assertTrue($this->manager->can_open_step($this->attemptid, (int) $open->id));
        $this->assertFalse($this->manager->can_open_step($this->attemptid, (int) $closed->id));
    }

    /**
     * A step that carries forward opens on the previous step's work.
     */
    public function test_carry_forward_keeps_the_students_work(): void {
        $step = $this->add_step(['carryforward' => 1]);
        $carried = ['Main.java' => 'the student edits'];

        $this->assertSame($carried, $this->manager->starting_files_for($step, $carried));
    }

    /**
     * A step that does not carry forward opens on the starter code.
     */
    public function test_without_carry_forward_the_starter_code_returns(): void {
        $step = $this->add_step(['carryforward' => 0]);
        $carried = ['Main.java' => 'the student edits'];

        $files = $this->manager->starting_files_for($step, $carried);

        $this->assertSame(["public class Main {\n}\n"], array_values($files));
    }

    /**
     * The first step has nothing to carry, so it opens on the starter code.
     */
    public function test_the_first_step_opens_on_the_starter_code(): void {
        $step = $this->add_step(['carryforward' => 1]);

        $files = $this->manager->starting_files_for($step, []);

        $this->assertSame(["public class Main {\n}\n"], array_values($files));
    }

    /**
     * Progress counts completed steps.
     */
    public function test_progress_counts_completed_steps(): void {
        $one = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $this->add_step(['completionrule' => step_manager::RULE_VIEW]);

        $this->assertSame(
            ['total' => 4, 'complete' => 0, 'percent' => 0],
            $this->manager->get_progress($this->attemptid)
        );

        $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $one->id);
        $this->manager->record_action($one, $stepattempt, 'run');

        $this->assertSame(
            ['total' => 4, 'complete' => 1, 'percent' => 25],
            $this->manager->get_progress($this->attemptid)
        );
    }

    /**
     * A new step attempt carries the defaults the database declares.
     */
    public function test_a_new_step_attempt_has_usable_defaults(): void {
        $step = $this->add_step();
        $stepattempt = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);

        $this->assertSame(0, (int) $stepattempt->runcount);
        $this->assertSame(0, (int) $stepattempt->hintsused);
        $this->assertSame(0, (int) $stepattempt->solutionviewed);
        $this->assertNull($stepattempt->timefirstpassed);
    }

    /**
     * Opening the same step twice does not start it again.
     */
    public function test_reopening_a_step_keeps_its_progress(): void {
        $step = $this->add_step(['completionrule' => step_manager::RULE_RUN]);

        $first = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);
        $this->manager->record_action($step, $first, 'run');

        $again = $this->manager->get_or_create_step_attempt($this->attemptid, (int) $step->id);

        $this->assertSame((int) $first->id, (int) $again->id);
        $this->assertSame(1, (int) $again->runcount);
    }
}
