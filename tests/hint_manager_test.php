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
use mod_saylorcode\local\hint_manager;

/**
 * Tests for progressive hints and the reference solution.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\hint_manager
 */
final class hint_manager_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private $course;

    /**
     * A course to hang activities off.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * An activity with hints, and an attempt against it.
     *
     * @param array $fields Field overrides.
     * @return array [hint_manager, attempt]
     */
    private function make(array $fields = []): array {
        $instance = $this->getDataGenerator()->create_module('saylorcode', [
            'course' => $this->course->id,
        ] + $fields + [
            'allowhints' => 1,
            'hints' => json_encode([
                ['text' => 'Look at the first line.'],
                ['text' => 'You need a semicolon.'],
            ]),
        ]);

        $user = $this->getDataGenerator()->create_user();
        $attempt = (new attempt_manager($instance))->get_or_create_attempt((int) $user->id);

        return [new hint_manager($instance), $attempt];
    }

    /**
     * Hints come one at a time, in the author's order.
     */
    public function test_hints_come_one_at_a_time_in_order(): void {
        [$hints, $attempt] = $this->make();

        $first = $hints->reveal_next($attempt);
        $this->assertSame('Look at the first line.', $first['text']);
        $this->assertSame(1, $first['number']);
        $this->assertSame(2, $first['total']);
        $this->assertSame(1, $first['remaining']);

        $second = $hints->reveal_next($attempt);
        $this->assertSame('You need a semicolon.', $second['text']);
        $this->assertSame(0, $second['remaining']);
    }

    /**
     * Taking a hint is recorded.
     */
    public function test_taking_a_hint_is_recorded(): void {
        global $DB;

        [$hints, $attempt] = $this->make();

        $hints->reveal_next($attempt);

        $stored = $DB->get_record('saylorcode_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $stored->hintsused);
    }

    /**
     * Running out of hints is not an error.
     */
    public function test_running_out_of_hints_gives_nothing(): void {
        [$hints, $attempt] = $this->make();

        $hints->reveal_next($attempt);
        $hints->reveal_next($attempt);
        $third = $hints->reveal_next($attempt);

        $this->assertSame('', $third['text']);
        $this->assertSame(0, $third['remaining']);
    }

    /**
     * A student who reloads keeps the hints they already took.
     */
    public function test_revealed_hints_survive_a_reload(): void {
        [$hints, $attempt] = $this->make();

        $hints->reveal_next($attempt);
        $hints->reveal_next($attempt);

        $revealed = $hints->get_revealed($attempt);

        $this->assertCount(2, $revealed);
        $this->assertSame('Look at the first line.', $revealed[0]['text']);
        $this->assertSame(2, $revealed[1]['number']);
    }

    /**
     * An author who turns hints off gives none, even having written some.
     */
    public function test_hints_turned_off_give_nothing(): void {
        [$hints, $attempt] = $this->make(['allowhints' => 0]);

        $this->assertFalse($hints->has_hints());
        $this->assertSame('', $hints->reveal_next($attempt)['text']);
    }

    /**
     * Hints left on with none written are still no hints.
     */
    public function test_no_hints_written_is_no_hints(): void {
        [$hints] = $this->make(['hints' => '']);

        $this->assertFalse($hints->has_hints());
        $this->assertSame([], $hints->get_hints());
    }

    /**
     * Blank rows are not hints.
     */
    public function test_blank_hints_are_discarded(): void {
        [$hints] = $this->make([
            'hints' => json_encode([
                ['text' => 'Real hint.'],
                ['text' => '   '],
                ['text' => ''],
            ]),
        ]);

        $this->assertCount(1, $hints->get_hints());
    }

    /**
     * The solution is only given where the author allows it.
     */
    public function test_the_solution_is_only_given_when_allowed(): void {
        [$hints, $attempt] = $this->make([
            'allowsolution' => 0,
            'referencesolution' => 'public class Main {}',
        ]);

        $this->assertFalse($hints->allows_solution());
        $this->assertSame('', $hints->reveal_solution($attempt));
    }

    /**
     * Allowing the solution without writing one gives nothing.
     */
    public function test_allowing_a_solution_that_does_not_exist_gives_nothing(): void {
        [$hints, $attempt] = $this->make([
            'allowsolution' => 1,
            'referencesolution' => '',
        ]);

        $this->assertFalse($hints->allows_solution());
        $this->assertSame('', $hints->reveal_solution($attempt));
    }

    /**
     * Looking at the solution is recorded.
     */
    public function test_viewing_the_solution_is_recorded(): void {
        global $DB;

        [$hints, $attempt] = $this->make([
            'allowsolution' => 1,
            'referencesolution' => 'public class Main {}',
        ]);

        $this->assertSame('public class Main {}', $hints->reveal_solution($attempt));

        $stored = $DB->get_record('saylorcode_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $stored->solutionviewed);
    }
}
