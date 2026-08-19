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

use mod_saylorcode\local\catalogue;

/**
 * Tests for the exercise catalogue.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\catalogue
 */
final class catalogue_test extends \advanced_testcase {
    /**
     * Run a catalogue query and return the stable ids it found.
     *
     * @param array $filters Filters to apply.
     * @return string[] Stable ids, sorted so the assertions do not depend on order.
     */
    private function ids_matching(array $filters): array {
        global $DB;

        [$fields, $from, $where, $params] = catalogue::build_query($filters);
        $records = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);

        $ids = array_map(static function (object $record): string {
            return $record->stableid;
        }, $records);

        sort($ids);

        return array_values($ids);
    }

    /**
     * Create an exercise.
     *
     * @param object $course The course to put it in.
     * @param array $fields Field overrides.
     * @return object The activity instance.
     */
    private function make_exercise(object $course, array $fields): object {
        return $this->getDataGenerator()->create_module('saylorcode', ['course' => $course->id] + $fields);
    }

    /**
     * Two cases, one of them hidden.
     *
     * @return string
     */
    private function two_cases(): string {
        return json_encode([
            ['name' => 'Shown', 'expected' => '4', 'ispublic' => 1, 'weight' => 1],
            ['name' => 'Hidden', 'expected' => '6', 'ispublic' => 0, 'weight' => 1],
        ]);
    }

    /**
     * The catalogue finds every exercise on the site, across courses.
     */
    public function test_it_spans_courses(): void {
        $this->resetAfterTest();

        $one = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $two = $this->getDataGenerator()->create_course(['shortname' => 'CS102']);

        $this->make_exercise($one, ['stableid' => 'CS101-U01-E01']);
        $this->make_exercise($two, ['stableid' => 'CS102-U01-E01']);

        $this->assertSame(['CS101-U01-E01', 'CS102-U01-E01'], $this->ids_matching([]));
    }

    /**
     * The course filter narrows to one course.
     */
    public function test_the_course_filter_narrows(): void {
        $this->resetAfterTest();

        $one = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $two = $this->getDataGenerator()->create_course(['shortname' => 'CS102']);

        $this->make_exercise($one, ['stableid' => 'CS101-U01-E01']);
        $this->make_exercise($two, ['stableid' => 'CS102-U01-E01']);

        $this->assertSame(['CS101-U01-E01'], $this->ids_matching(['courseid' => $one->id]));
    }

    /**
     * Search matches the stable id, the name and the course short name.
     */
    public function test_search_matches_id_name_and_course(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $other = $this->getDataGenerator()->create_course(['shortname' => 'PRDV401']);

        $this->make_exercise($course, ['stableid' => 'CS101-U01-E01', 'name' => 'Double a number']);
        $this->make_exercise($other, ['stableid' => 'PRDV401-U01-E01', 'name' => 'Greet the user']);

        $this->assertSame(['CS101-U01-E01'], $this->ids_matching(['search' => 'U01-E01', 'courseid' => $course->id]));
        $this->assertSame(['CS101-U01-E01'], $this->ids_matching(['search' => 'Double']));
        $this->assertSame(['PRDV401-U01-E01'], $this->ids_matching(['search' => 'PRDV']));
    }

    /**
     * Search is not case sensitive, because an author does not type an id the
     * same way twice.
     */
    public function test_search_ignores_case(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $this->make_exercise($course, ['stableid' => 'CS101-U01-E01', 'name' => 'Double a number']);

        $this->assertSame(['CS101-U01-E01'], $this->ids_matching(['search' => 'double A NUMBER']));
    }

    /**
     * A search term containing wildcards is matched literally.
     */
    public function test_search_does_not_treat_input_as_a_pattern(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $this->make_exercise($course, ['stableid' => 'CS101-U01-E01', 'name' => 'Double a number']);

        // Were the term used as a pattern this would match everything.
        $this->assertSame([], $this->ids_matching(['search' => '%']));
    }

    /**
     * The readiness filter separates finished exercises from unfinished ones.
     */
    public function test_the_readiness_filter_separates_finished_work(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);

        $this->make_exercise($course, [
            'stableid' => 'CS101-U01-E01',
            'testcases' => $this->two_cases(),
            'referencesolution' => 'public class Main {}',
        ]);
        $this->make_exercise($course, [
            'stableid' => 'CS101-U01-E02',
            'testcases' => $this->two_cases(),
            'referencesolution' => '',
        ]);
        $this->make_exercise($course, [
            'stableid' => 'CS101-U01-E03',
            'testcases' => '',
            'referencesolution' => '',
        ]);

        $this->assertSame(['CS101-U01-E01'], $this->ids_matching(['state' => 'ready']));
        $this->assertSame(['CS101-U01-E02', 'CS101-U01-E03'], $this->ids_matching(['state' => 'nosolution']));
        $this->assertSame(['CS101-U01-E03'], $this->ids_matching(['state' => 'notests']));
    }

    /**
     * Case counting reports the total and how many are hidden.
     */
    public function test_case_counting(): void {
        $this->assertSame([2, 1], catalogue::count_cases($this->two_cases()));
        $this->assertSame([0, 0], catalogue::count_cases(''));
        $this->assertSame([0, 0], catalogue::count_cases(null));

        // A stored "[]" describes no cases, and must not read as one.
        $this->assertSame([0, 0], catalogue::count_cases('[]'));

        // Anything unparseable is reported as no cases rather than crashing a
        // page whose whole job is to survey work in progress.
        $this->assertSame([0, 0], catalogue::count_cases('not json'));
    }

    /**
     * Readiness distinguishes the states an author cares about.
     */
    public function test_readiness_states(): void {
        $ready = (object) ['testcases' => $this->two_cases(), 'referencesolution' => 'x', 'startercode' => 'y'];
        $nosolution = (object) ['testcases' => $this->two_cases(), 'referencesolution' => '', 'startercode' => 'y'];
        $notests = (object) ['testcases' => '', 'referencesolution' => '', 'startercode' => 'y'];
        $empty = (object) ['testcases' => '', 'referencesolution' => '', 'startercode' => ''];

        $this->assertSame('ready', catalogue::readiness($ready));
        $this->assertSame('nosolution', catalogue::readiness($nosolution));
        $this->assertSame('notests', catalogue::readiness($notests));
        $this->assertSame('empty', catalogue::readiness($empty));

        // Whitespace is not a reference solution.
        $blank = (object) ['testcases' => $this->two_cases(), 'referencesolution' => "  \n ", 'startercode' => 'y'];
        $this->assertSame('nosolution', catalogue::readiness($blank));
    }

    /**
     * The readiness filter and the readiness badge agree.
     *
     * They are two implementations of one idea, one in SQL and one in PHP, so
     * nothing but a test stops them drifting. When they disagree the catalogue
     * lists a row under Ready and then labels it "No tests" in the same table,
     * which is worse than either answer alone.
     */
    public function test_the_filter_and_the_badge_agree(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);

        // Every shape the column can hold, including the ones that read as
        // present but describe nothing.
        $shapes = [
            'both' => ['testcases' => $this->two_cases(), 'referencesolution' => 'public class Main {}'],
            'emptyarray' => ['testcases' => '[]', 'referencesolution' => 'public class Main {}'],
            'notests' => ['testcases' => '', 'referencesolution' => 'public class Main {}'],
            'nosolution' => ['testcases' => $this->two_cases(), 'referencesolution' => ''],
            'neither' => ['testcases' => '', 'referencesolution' => ''],
        ];

        $expectedready = [];
        foreach ($shapes as $name => $fields) {
            $instance = $this->make_exercise($course, ['stableid' => 'CS101-U01-E01'] + $fields);
            $record = $DB->get_record('saylorcode', ['id' => $instance->id], '*', MUST_EXIST);

            if (catalogue::readiness($record) === 'ready') {
                $expectedready[] = $name;
            }
        }

        // What the SQL filter returns, named back to the shapes above.
        [$fields, $from, $where, $params] = catalogue::build_query(['state' => 'ready']);
        $found = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);

        $actualready = [];
        foreach ($found as $record) {
            foreach ($shapes as $name => $shape) {
                if ($record->testcases === $shape['testcases']
                        && $record->referencesolution === $shape['referencesolution']) {
                    $actualready[] = $name;
                    break;
                }
            }
        }

        sort($expectedready);
        sort($actualready);

        $this->assertSame(['both'], $expectedready, 'Only a tested exercise with a solution is ready.');
        $this->assertSame($expectedready, $actualready, 'The Ready filter disagrees with the Ready badge.');
    }

    /**
     * The course menu lists only courses that hold an exercise.
     */
    public function test_the_course_menu_skips_empty_courses(): void {
        $this->resetAfterTest();

        $withexercise = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $this->getDataGenerator()->create_course(['shortname' => 'EMPTY']);

        $this->make_exercise($withexercise, ['stableid' => 'CS101-U01-E01']);

        $this->assertSame([$withexercise->id => 'CS101'], catalogue::get_courses_with_exercises());
    }
}
