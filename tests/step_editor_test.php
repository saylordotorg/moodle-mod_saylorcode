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
use mod_saylorcode\local\step_editor;
use mod_saylorcode\local\step_manager;

/**
 * Tests for authoring the steps of a guided lesson.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\step_editor
 */
final class step_editor_test extends \advanced_testcase {
    /** @var \stdClass The activity. */
    private $instance;

    /** @var step_editor The editor under test. */
    private $editor;

    /**
     * An activity to hang steps off.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('saylorcode', ['course' => $course->id]);
        $this->editor = new step_editor($this->instance);
    }

    /**
     * Submitted data for a step.
     *
     * @param string $title The title.
     * @param array $extra Field overrides.
     * @return \stdClass
     */
    private function submission(string $title, array $extra = []): \stdClass {
        return (object) ($extra + [
            'title' => $title,
            'steptype' => 'checkpoint',
            'instructions' => ['text' => '<p>Do it.</p>', 'format' => FORMAT_HTML],
            'completionrule' => step_manager::RULE_PASSTESTS,
            'carryforward' => 1,
            'allowrevisit' => 1,
            'points' => 0,
        ]);
    }

    /**
     * The titles of the steps, in order.
     *
     * @return string[]
     */
    private function titles(): array {
        return array_values(array_map(static function (\stdClass $step): string {
            return $step->title;
        }, $this->editor->get_steps()));
    }

    /**
     * A created step lands at the end of the lesson.
     */
    public function test_new_steps_go_to_the_end(): void {
        $this->editor->create($this->submission('First'));
        $this->editor->create($this->submission('Second'));
        $this->editor->create($this->submission('Third'));

        $this->assertSame(['First', 'Second', 'Third'], $this->titles());
    }

    /**
     * The editor unpacks the editor element's array form.
     */
    public function test_instructions_are_stored_with_their_format(): void {
        $id = $this->editor->create($this->submission('First', [
            'instructions' => ['text' => '<p>Read this.</p>', 'format' => FORMAT_MARKDOWN],
        ]));

        $step = $this->editor->get_step($id);

        $this->assertSame('<p>Read this.</p>', $step->instructions);
        $this->assertSame(FORMAT_MARKDOWN, (int) $step->instructionsformat);
    }

    /**
     * A step can be changed.
     */
    public function test_a_step_can_be_edited(): void {
        $id = $this->editor->create($this->submission('Before'));

        $this->assertTrue($this->editor->update($id, $this->submission('After', [
            'completionrule' => step_manager::RULE_VIEW,
            'carryforward' => 0,
        ])));

        $step = $this->editor->get_step($id);

        $this->assertSame('After', $step->title);
        $this->assertSame(step_manager::RULE_VIEW, $step->completionrule);
        $this->assertSame(0, (int) $step->carryforward);
    }

    /**
     * Steps move one place at a time.
     */
    public function test_steps_can_be_reordered(): void {
        $this->editor->create($this->submission('First'));
        $second = $this->editor->create($this->submission('Second'));
        $this->editor->create($this->submission('Third'));

        $this->assertTrue($this->editor->move($second, -1));
        $this->assertSame(['Second', 'First', 'Third'], $this->titles());

        $this->assertTrue($this->editor->move($second, 1));
        $this->assertSame(['First', 'Second', 'Third'], $this->titles());
    }

    /**
     * Moving off either end does nothing rather than failing.
     */
    public function test_moving_past_the_ends_is_refused(): void {
        $first = $this->editor->create($this->submission('First'));
        $last = $this->editor->create($this->submission('Second'));

        $this->assertFalse($this->editor->move($first, -1));
        $this->assertFalse($this->editor->move($last, 1));
        $this->assertSame(['First', 'Second'], $this->titles());
    }

    /**
     * Deleting a step closes the gap it leaves.
     */
    public function test_deleting_renumbers_the_rest(): void {
        global $DB;

        $this->editor->create($this->submission('First'));
        $second = $this->editor->create($this->submission('Second'));
        $this->editor->create($this->submission('Third'));

        $this->assertTrue($this->editor->delete($second));
        $this->assertSame(['First', 'Third'], $this->titles());

        $orders = $DB->get_fieldset_sql(
            'SELECT sortorder FROM {saylorcode_steps} WHERE saylorcodeid = ? ORDER BY sortorder',
            [$this->instance->id]
        );

        $this->assertSame([1, 2], array_map('intval', $orders));
    }

    /**
     * Deleting a step discards the progress recorded against it.
     */
    public function test_deleting_a_step_discards_its_progress(): void {
        global $DB;

        $id = $this->editor->create($this->submission('First'));

        $user = $this->getDataGenerator()->create_user();
        $attempt = (new attempt_manager($this->instance))->get_or_create_attempt((int) $user->id);
        $manager = new step_manager($this->instance);
        $manager->get_or_create_step_attempt((int) $attempt->id, $id);

        $this->assertTrue($DB->record_exists('saylorcode_stepattempts', ['stepid' => $id]));

        $this->editor->delete($id);

        $this->assertFalse($DB->record_exists('saylorcode_stepattempts', ['stepid' => $id]));
    }

    /**
     * A step belonging to another activity is untouchable.
     *
     * The step id arrives in a URL, so ownership is checked here rather than
     * trusted: otherwise an author could reach a lesson in a course they have
     * no rights over.
     */
    public function test_a_step_from_another_activity_is_refused(): void {
        $other = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $this->getDataGenerator()->create_course()->id,
        ]);
        $foreign = (new step_editor($other))->create($this->submission('Not yours'));

        $this->assertNull($this->editor->get_step($foreign));
        $this->assertFalse($this->editor->update($foreign, $this->submission('Hijacked')));
        $this->assertFalse($this->editor->delete($foreign));
        $this->assertFalse($this->editor->move($foreign, 1));

        // Still there, still called what its own author called it.
        $this->assertSame('Not yours', (new step_editor($other))->get_step($foreign)->title);
    }

    /**
     * An empty exercise reference is stored as nothing, not as an empty string.
     */
    public function test_a_blank_exercise_reference_is_stored_as_null(): void {
        $id = $this->editor->create($this->submission('First', ['stableid' => '   ']));

        $this->assertNull($this->editor->get_step($id)->stableid);
    }
}
