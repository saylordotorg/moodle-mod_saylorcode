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
use local_saylorcode\local\library\exercise_resolver;
use mod_saylorcode\local\content;
use mod_saylorcode\local\library_migrator;

/**
 * Tests for migrating activity-stored exercises into the library.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\library_migrator
 */
final class library_migrator_test extends \advanced_testcase {
    /**
     * Create a saylorcode activity carrying an exercise.
     *
     * @param array $fields Activity fields to override.
     * @return \stdClass The activity instance.
     */
    private function activity(array $fields): \stdClass {
        $course = $fields['course'] ?? $this->getDataGenerator()->create_course();
        unset($fields['course']);

        return $this->getDataGenerator()->create_module('saylorcode', array_merge([
            'course' => $course->id,
            'startercode' => 'public class Main {}',
            'testcases' => json_encode([
                ['id' => 'T1', 'name' => 'greets', 'expected' => 'Hello', 'ispublic' => true, 'weight' => 1],
            ]),
        ], $fields));
    }

    /**
     * The entry for one stable id in a plan.
     *
     * @param array $plan The plan.
     * @param string $stableid The reference.
     * @return array|null
     */
    private function entry(array $plan, string $stableid): ?array {
        foreach ($plan as $entry) {
            if ($entry['stableid'] === $stableid) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * A new exercise is marked for creation and then published from the activity.
     */
    public function test_migrate_publishes_the_activity_content(): void {
        $this->resetAfterTest();

        $this->activity([
            'stableid' => 'CS101-U01-E01',
            'name' => 'Hello world',
            'startercode' => 'class Main { }',
            'referencesolution' => 'class Main { /* solved */ }',
        ]);

        $migrator = new library_migrator();

        $plan = $migrator->plan();
        $this->assertSame(library_migrator::ACTION_CREATE, $this->entry($plan, 'CS101-U01-E01')['action']);

        $report = $migrator->migrate();
        $entry = $this->entry($report, 'CS101-U01-E01');
        $this->assertSame('migrated', $entry['outcome']);
        $this->assertSame(1, $entry['version']);

        // The published version carries the activity's content verbatim.
        $exercise = (new exercise_repository())->find('CS101-U01-E01');
        $this->assertNotNull($exercise);
        $version = (new exercise_repository())->get_latest($exercise);
        $this->assertSame('class Main { }', $version->startercode);
        $this->assertSame('class Main { /* solved */ }', $version->referencesolution);
    }

    /**
     * After migration the activity resolves to the library, transparently.
     *
     * The point of the whole exercise: a student meets the same content, now
     * served from the library rather than the activity's own fields.
     */
    public function test_migrated_content_resolves_back_to_the_activity(): void {
        $this->resetAfterTest();

        $instance = $this->activity([
            'stableid' => 'CS101-U01-E02',
            'startercode' => 'class Solution {}',
        ]);

        (new library_migrator())->migrate();

        $resolved = content::for_instance($instance);
        $this->assertTrue($resolved->is_from_library());
        $this->assertSame('latest', $resolved->get_source());
        $this->assertSame('class Solution {}', $resolved->get_starter_code());
    }

    /**
     * An exercise already in the library is skipped, not republished.
     */
    public function test_an_exercise_already_in_the_library_is_skipped(): void {
        $this->resetAfterTest();

        $this->activity(['stableid' => 'CS101-U02-E01']);

        $migrator = new library_migrator();
        $migrator->migrate();

        // A second run finds it already there.
        $report = $migrator->migrate();
        $entry = $this->entry($report, 'CS101-U02-E01');

        $this->assertSame(library_migrator::ACTION_INLIBRARY, $entry['action']);
        $this->assertSame('skipped', $entry['outcome']);

        // Still only the one published version.
        $exercise = (new exercise_repository())->find('CS101-U02-E01');
        $this->assertCount(1, (new exercise_repository())->get_history($exercise));
    }

    /**
     * Two activities with the same reference and content migrate once.
     */
    public function test_identical_duplicates_migrate_once(): void {
        $this->resetAfterTest();

        $shared = [
            'stableid' => 'CS101-U03-E01',
            'startercode' => 'class Same {}',
            'testcases' => json_encode([
                ['id' => 'T1', 'name' => 'a', 'expected' => 'x', 'ispublic' => true, 'weight' => 1],
            ]),
        ];
        $this->activity($shared);
        $this->activity($shared);

        $report = (new library_migrator())->migrate();
        $entry = $this->entry($report, 'CS101-U03-E01');

        $this->assertSame(2, $entry['count']);
        $this->assertSame('migrated', $entry['outcome']);
    }

    /**
     * Two activities with the same reference but different content conflict.
     */
    public function test_divergent_duplicates_are_a_conflict(): void {
        $this->resetAfterTest();

        $this->activity(['stableid' => 'CS101-U04-E01', 'startercode' => 'class One {}']);
        $this->activity(['stableid' => 'CS101-U04-E01', 'startercode' => 'class Two {}']);

        $report = (new library_migrator())->migrate();
        $entry = $this->entry($report, 'CS101-U04-E01');

        $this->assertSame(library_migrator::ACTION_CONFLICT, $entry['action']);
        $this->assertSame('skipped', $entry['outcome']);
        $this->assertArrayHasKey('courses', $entry);

        // Nothing was written for a conflicted reference.
        $this->assertNull((new exercise_repository())->find('CS101-U04-E01'));
    }

    /**
     * An activity with neither starter code nor tests cannot be published.
     */
    public function test_an_activity_without_content_is_skipped(): void {
        $this->resetAfterTest();

        $this->activity([
            'stableid' => 'CS101-U05-E01',
            'startercode' => '',
            'testcases' => '',
        ]);

        $report = (new library_migrator())->migrate();
        $entry = $this->entry($report, 'CS101-U05-E01');

        $this->assertSame(library_migrator::ACTION_EMPTY, $entry['action']);
        $this->assertSame('skipped', $entry['outcome']);
        $this->assertNull((new exercise_repository())->find('CS101-U05-E01'));
    }

    /**
     * A playground with no stable id is left alone.
     */
    public function test_an_activity_without_a_reference_is_ignored(): void {
        $this->resetAfterTest();

        $this->activity(['stableid' => '', 'activitymode' => 'playground']);

        $this->assertSame([], (new library_migrator())->plan());
    }

    /**
     * The course filter restricts what is migrated.
     */
    public function test_the_course_filter_restricts_scope(): void {
        $this->resetAfterTest();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $this->activity(['course' => $coursea, 'stableid' => 'CS101-U06-E01']);
        $this->activity(['course' => $courseb, 'stableid' => 'CS101-U06-E02']);

        $plan = (new library_migrator())->plan((int) $coursea->id);

        $this->assertCount(1, $plan);
        $this->assertSame('CS101-U06-E01', $plan[0]['stableid']);
    }

    /**
     * A course-scoped run still sees a divergent copy in another course.
     *
     * The resolver resolves a library exercise globally, so migrating the
     * selected course's copy would switch the other course's activity to it.
     * Conflict detection must therefore look site-wide even when the migration
     * is restricted to one course.
     */
    public function test_a_course_run_detects_a_conflict_in_another_course(): void {
        $this->resetAfterTest();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $this->activity(['course' => $coursea, 'stableid' => 'CS101-U07-E01', 'startercode' => 'class A {}']);
        $this->activity(['course' => $courseb, 'stableid' => 'CS101-U07-E01', 'startercode' => 'class B {}']);

        // Migrating only course A must still refuse: course B holds a divergent
        // copy under the same reference.
        $report = (new library_migrator())->migrate((int) $coursea->id);
        $entry = $this->entry($report, 'CS101-U07-E01');

        $this->assertSame(library_migrator::ACTION_CONFLICT, $entry['action']);
        $this->assertSame('skipped', $entry['outcome']);
        $this->assertNull((new exercise_repository())->find('CS101-U07-E01'));
    }
}
