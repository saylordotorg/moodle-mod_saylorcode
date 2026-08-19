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

use mod_saylorcode\external\open_step;
use mod_saylorcode\local\step_manager;

/**
 * Tests for the step navigation web service.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\external\open_step
 */
final class open_step_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private $course;

    /** @var \stdClass The activity. */
    private $instance;

    /** @var \stdClass The course module. */
    private $cm;

    /** @var \stdClass The student. */
    private $student;

    /**
     * A guided lesson with an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $this->course->id,
            'startercode' => "public class Main {\n}\n",
        ]);
        $this->cm = get_coursemodule_from_instance('saylorcode', $this->instance->id, 0, false, MUST_EXIST);

        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Add a step.
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
            'instructions' => '<p>Do the thing.</p>',
            'instructionsformat' => FORMAT_HTML,
            'versionpolicy' => 'latest',
            'carryforward' => 1,
            'completionrule' => step_manager::RULE_RUN,
            'allowrevisit' => 1,
            'points' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $step->id = $DB->insert_record('saylorcode_steps', $step);

        return $DB->get_record('saylorcode_steps', ['id' => $step->id], '*', MUST_EXIST);
    }

    /**
     * Opening the first step returns what the interface needs.
     */
    public function test_opening_the_first_step(): void {
        $step = $this->add_step();
        $this->add_step();

        $this->setUser($this->student);

        $result = open_step::execute($this->cm->id, (int) $step->id);
        $result = \core_external\external_api::clean_returnvalue(open_step::execute_returns(), $result);

        $this->assertSame((int) $step->id, $result['stepid']);
        $this->assertSame('Step 1', $result['title']);
        $this->assertStringContainsString('Do the thing.', $result['instructions']);
        $this->assertSame("public class Main {\n}\n", $result['code']);
        $this->assertSame(2, $result['stepstotal']);
        $this->assertSame(0, $result['stepscomplete']);
        $this->assertNotEmpty($result['completionhint']);
    }

    /**
     * A step the student has not reached is refused rather than opened.
     */
    public function test_a_locked_step_is_refused(): void {
        $this->add_step();
        $later = $this->add_step();

        $this->setUser($this->student);

        $this->expectException(\moodle_exception::class);
        open_step::execute($this->cm->id, (int) $later->id);
    }

    /**
     * An activity with no steps is not a guided lesson.
     */
    public function test_an_activity_without_steps_is_refused(): void {
        $this->setUser($this->student);

        $this->expectException(\moodle_exception::class);
        open_step::execute($this->cm->id, 1);
    }

    /**
     * Opening a step records the arrival, so a view step completes.
     */
    public function test_opening_a_view_step_completes_it(): void {
        $step = $this->add_step(['completionrule' => step_manager::RULE_VIEW]);
        $this->add_step();

        $this->setUser($this->student);

        $result = open_step::execute($this->cm->id, (int) $step->id);
        $result = \core_external\external_api::clean_returnvalue(open_step::execute_returns(), $result);

        $this->assertSame(step_manager::STATUS_COMPLETE, $result['status']);
        $this->assertSame(1, $result['stepscomplete']);
        $this->assertSame(50, $result['percent']);
    }

    /**
     * A step that does not carry forward opens on the starter code.
     */
    public function test_a_step_without_carry_forward_opens_on_the_starter(): void {
        $step = $this->add_step(['carryforward' => 0]);

        $this->setUser($this->student);

        $result = open_step::execute($this->cm->id, (int) $step->id);
        $result = \core_external\external_api::clean_returnvalue(open_step::execute_returns(), $result);

        $this->assertSame("public class Main {\n}\n", $result['code']);
    }

    /**
     * Someone who cannot attempt cannot open a step.
     */
    public function test_a_teacher_cannot_open_a_step(): void {
        $step = $this->add_step();
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        open_step::execute($this->cm->id, (int) $step->id);
    }
}
