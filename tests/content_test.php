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

use local_saylorcode\local\library\exercise_repository;
use mod_saylorcode\local\attempt_manager;
use mod_saylorcode\local\content;
use mod_saylorcode\local\hint_manager;

/**
 * Tests for the activity sourcing its content from the library.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\content
 */
final class content_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private $course;

    /**
     * A course to build activities in.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * An activity carrying its own content.
     *
     * @param array $fields Field overrides.
     * @return \stdClass
     */
    private function activity(array $fields = []): \stdClass {
        return $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $this->course->id,
        ] + $fields + [
            'stableid' => 'CS101-U01-E01',
            'startercode' => "activity starter\n",
            'referencesolution' => 'activity solution',
            'testcases' => json_encode([
                ['name' => 'Activity case', 'expected' => 'a', 'ispublic' => 1, 'weight' => 1],
            ]),
            'hints' => json_encode([['text' => 'activity hint']]),
        ]);
    }

    /**
     * Publish an exercise with the given content.
     *
     * @param string $stableid The reference.
     * @return \stdClass The exercise.
     */
    private function publish(string $stableid = 'CS101-U01-E01'): \stdClass {
        $repository = new exercise_repository();

        $exercise = $repository->create($stableid, 'Library exercise', [
            'startercode' => "library starter\n",
            'referencesolution' => 'library solution',
            'testcases' => json_encode([
                ['name' => 'Library case', 'expected' => 'l', 'ispublic' => 1, 'weight' => 1],
            ]),
            'hints' => json_encode([['text' => 'library hint']]),
        ]);

        $repository->publish($exercise, 'First');

        return $exercise;
    }

    /**
     * An activity with no library entry behaves exactly as it always did.
     *
     * This is the case that covers everything authored before the library, and
     * the one that must not change.
     */
    public function test_an_activity_outside_the_library_is_untouched(): void {
        $instance = $this->activity();

        $starter = (new attempt_manager($instance))->get_starter_files();
        $this->assertSame("activity starter\n", reset($starter));

        // The resolver hands back the holder's own content when the library
        // cannot answer, so these are the activity's cases.
        $cases = content::for_instance($instance)->get_test_cases();
        $this->assertSame('Activity case', $cases[0]['name']);

        $hints = new hint_manager($instance);
        $this->assertSame('activity hint', $hints->get_hints()[0]['text']);
    }

    /**
     * A published exercise supplies the starter code.
     */
    public function test_a_published_exercise_supplies_the_starter(): void {
        $this->publish();
        $instance = $this->activity();

        $starter = (new attempt_manager($instance))->get_starter_files();

        $this->assertSame("library starter\n", reset($starter));
    }

    /**
     * The tests come from the same place the starter code did.
     *
     * Grading a student against the activity's tests while their editor was
     * seeded from the library would be the worst outcome here, because it would
     * look like the student's fault.
     */
    public function test_the_tests_come_from_the_same_place_as_the_code(): void {
        $this->publish();
        $instance = $this->activity();

        $starter = (new attempt_manager($instance))->get_starter_files();
        $cases = content::for_instance($instance)->get_test_cases();

        $this->assertSame("library starter\n", reset($starter));
        $this->assertSame('Library case', $cases[0]['name']);
    }

    /**
     * Hints and the solution follow the exercise too.
     */
    public function test_help_follows_the_exercise(): void {
        $this->publish();
        $instance = $this->activity(['allowsolution' => 1]);

        $hints = new hint_manager($instance);
        $attempt = (new attempt_manager($instance))->get_or_create_attempt(
            (int) $this->getDataGenerator()->create_user()->id
        );

        $this->assertSame('library hint', $hints->get_hints()[0]['text']);
        $this->assertSame('library solution', $hints->reveal_solution($attempt));
    }

    /**
     * A step with its own reference resolves on that exercise.
     *
     * Judging it on the activity's tests instead would unlock the step for
     * passing a suite it says nothing about, and would lock a step that has
     * tests for ever on an activity that has none.
     */
    public function test_a_step_resolves_on_its_own_reference(): void {
        global $DB;

        $this->publish('CS102-U01-E01');
        $instance = $this->activity();

        $step = (object) [
            'stableid' => 'CS102-U01-E01',
            'versionpolicy' => 'latest',
            'pinnedversion' => 0,
            'carryforward' => 0,
        ];

        $resolved = content::for_step($instance, $step);

        $this->assertSame("library starter
", $resolved->get_starter_code());
        $this->assertSame('Library case', $resolved->get_test_cases()[0]['name']);
    }

    /**
     * A step with no reference of its own follows the activity.
     */
    public function test_a_step_without_a_reference_follows_the_activity(): void {
        $instance = $this->activity();
        $step = (object) ['stableid' => '', 'carryforward' => 1];

        $this->assertSame(
            "activity starter
",
            content::for_step($instance, $step)->get_starter_code()
        );
    }

    /**
     * The entry filename comes from wherever the code did.
     *
     * The browser is told this name and sends work back under it. A stale name
     * means the runner receives a file the code does not match, which for Java
     * is a class that will not compile.
     */
    public function test_the_entry_filename_follows_the_exercise(): void {
        $repository = new exercise_repository();
        $exercise = $repository->create('CS101-U01-E01', 'Renamed', [
            'entryfilename' => 'Solution.java',
            'startercode' => "public class Solution {}
",
            'testcases' => json_encode([['name' => 'One', 'expected' => 'x', 'ispublic' => 1, 'weight' => 1]]),
        ]);
        $repository->publish($exercise, 'First');

        $instance = $this->activity(['entryfilename' => 'Main.java']);
        $resolved = content::for_instance($instance);

        $this->assertSame('Solution.java', $resolved->get_entry_filename());

        $files = (new attempt_manager($instance))->get_starter_files();
        $this->assertArrayHasKey('Solution.java', $files);
    }

    /**
     * Two steps of one lesson can use two different exercises.
     *
     * This is the constraint the library was meant to lift: before it, every
     * checkpoint in a lesson shared the activity's single set of tests, so a
     * multi step lesson could only ever build towards one final output.
     */
    public function test_two_steps_can_use_two_different_exercises(): void {
        $repository = new exercise_repository();

        foreach (['CS101-U01-E01' => 'first', 'CS101-U01-E02' => 'second'] as $reference => $marker) {
            $exercise = $repository->create($reference, 'Exercise ' . $marker, [
                'startercode' => $marker . " starter
",
                'testcases' => json_encode([
                    ['name' => $marker . ' case', 'expected' => $marker, 'ispublic' => 1, 'weight' => 1],
                ]),
            ]);
            $repository->publish($exercise, 'First');
        }

        $instance = $this->activity();

        $one = (object) ['stableid' => 'CS101-U01-E01', 'versionpolicy' => 'latest', 'pinnedversion' => 0];
        $two = (object) ['stableid' => 'CS101-U01-E02', 'versionpolicy' => 'latest', 'pinnedversion' => 0];

        $this->assertSame("first starter
", content::for_step($instance, $one)->get_starter_code());
        $this->assertSame("second starter
", content::for_step($instance, $two)->get_starter_code());

        $this->assertSame('first case', content::for_step($instance, $one)->get_test_cases()[0]['name']);
        $this->assertSame('second case', content::for_step($instance, $two)->get_test_cases()[0]['name']);
    }

    /**
     * A step pinned to a version stays there while the exercise moves on.
     */
    public function test_a_pinned_step_holds_its_version(): void {
        $repository = new exercise_repository();

        $exercise = $repository->create('CS101-U01-E01', 'Doubling', [
            'startercode' => "version one
",
            'testcases' => json_encode([['name' => 'One', 'expected' => 'x', 'ispublic' => 1, 'weight' => 1]]),
        ]);
        $repository->publish($exercise, 'First');

        $exercise = $repository->find('CS101-U01-E01');
        $repository->write_draft($exercise, ['startercode' => "version two
"]);
        $repository->publish($exercise, 'Second');

        $instance = $this->activity();

        $pinned = (object) ['stableid' => 'CS101-U01-E01', 'versionpolicy' => 'pinned', 'pinnedversion' => 1];
        $latest = (object) ['stableid' => 'CS101-U01-E01', 'versionpolicy' => 'latest', 'pinnedversion' => 0];

        $this->assertSame("version one
", content::for_step($instance, $pinned)->get_starter_code());
        $this->assertSame("version two
", content::for_step($instance, $latest)->get_starter_code());
    }

    /**
     * A draft that has never been published does not reach a student.
     */
    public function test_an_unpublished_draft_does_not_reach_a_student(): void {
        (new exercise_repository())->create('CS101-U01-E01', 'Draft only', [
            'startercode' => "library starter\n",
        ]);

        $instance = $this->activity();
        $starter = (new attempt_manager($instance))->get_starter_files();

        $this->assertSame("activity starter\n", reset($starter));
    }

    /**
     * An activity pointing at a different exercise is unaffected.
     */
    public function test_a_different_reference_does_not_pick_up_the_library(): void {
        $this->publish('CS102-U01-E01');

        $instance = $this->activity(['stableid' => 'CS101-U01-E01']);
        $starter = (new attempt_manager($instance))->get_starter_files();

        $this->assertSame("activity starter\n", reset($starter));
    }
}
