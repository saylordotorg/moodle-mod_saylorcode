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

namespace mod_saylorcode\local;

use local_saylorcode\local\runner\execution_state;
use mod_saylorcode\tests\fixtures\scripted_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/scripted_provider.php');

/**
 * Tests for validating an author's reference solution.
 *
 * This is the check that stops a broken exercise reaching students, so the
 * cases below are mostly about it refusing to say yes.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\exercise_validator
 */
final class exercise_validator_test extends \advanced_testcase {
    /** @var array Two cases the reference is expected to satisfy. */
    private const CASES = [
        ['id' => 'T1', 'name' => 'Doubles four', 'stdin' => "4\n", 'expected' => "8\n", 'ispublic' => true],
        ['id' => 'T2', 'name' => 'Doubles zero', 'stdin' => "0\n", 'expected' => "0\n", 'ispublic' => true],
    ];

    /**
     * A correct reference solution validates.
     */
    public function test_correct_solution_validates(): void {
        $this->resetAfterTest();

        $provider = new scripted_provider(["4\n" => "8\n", "0\n" => "0\n"]);
        $report = (new exercise_validator($provider))->validate('java17-console', 'Main.java', 'x', self::CASES);

        $this->assertTrue($report['valid']);
        $this->assertCount(2, $report['results']);
    }

    /**
     * A wrong expected value is caught, which is the whole point.
     *
     * The author cannot see this by reading: the case looks perfectly ordinary
     * and would fail every student who met it.
     */
    public function test_wrong_expected_value_is_caught(): void {
        $this->resetAfterTest();

        $cases = self::CASES;
        $cases[1]['expected'] = "1\n";

        $provider = new scripted_provider(["4\n" => "8\n", "0\n" => "0\n"]);
        $report = (new exercise_validator($provider))->validate('java17-console', 'Main.java', 'x', $cases);

        $this->assertFalse($report['valid']);
        $this->assertTrue($report['results'][0]['passed']);
        $this->assertFalse($report['results'][1]['passed']);
        $this->assertSame("0\n", $report['results'][1]['actual']);
    }

    /**
     * A reference solution that does not compile is reported as such.
     */
    public function test_compile_error_is_reported(): void {
        $this->resetAfterTest();

        $provider = new scripted_provider([], execution_state::COMPILE_ERROR);
        $report = (new exercise_validator($provider))->validate('java17-console', 'Main.java', 'broken', self::CASES);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('did not compile', $report['summary']);
        // It stops rather than repeating the same message for every case.
        $this->assertCount(1, $provider->requests);
    }

    /**
     * A runner outage is not a validation failure.
     *
     * Telling an author their tests are wrong when the sandbox is simply down
     * would send them editing correct cases.
     */
    public function test_runner_outage_is_not_a_verdict(): void {
        $this->resetAfterTest();

        $provider = new scripted_provider([], execution_state::RUNNER_UNAVAILABLE);
        $report = (new exercise_validator($provider))->validate('java17-console', 'Main.java', 'x', self::CASES);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('could not be reached', $report['summary']);
        $this->assertEmpty($report['results']);
    }

    /**
     * There is nothing to validate without a reference solution.
     */
    public function test_missing_solution_is_refused(): void {
        $this->resetAfterTest();

        $report = (new exercise_validator(new scripted_provider()))
            ->validate('java17-console', 'Main.java', '   ', self::CASES);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('reference solution first', $report['summary']);
    }

    /**
     * Nor without any cases.
     */
    public function test_missing_cases_is_refused(): void {
        $this->resetAfterTest();

        $report = (new exercise_validator(new scripted_provider()))
            ->validate('java17-console', 'Main.java', 'x', []);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('test case first', $report['summary']);
    }

    /**
     * Validation compares exactly as the student path does.
     *
     * If these disagreed an author could prove their tests pass and a student
     * could still fail them.
     */
    public function test_comparison_matches_the_student_path(): void {
        $this->resetAfterTest();

        $cases = [['id' => 'T1', 'name' => 'Trailing space', 'stdin' => '', 'expected' => "ok\n"]];

        $provider = new scripted_provider(['' => "ok   \n\n"]);
        $report = (new exercise_validator($provider))->validate('java17-console', 'Main.java', 'x', $cases);

        $this->assertTrue($report['valid']);
        $this->assertTrue(output_comparator::matches("ok   \n\n", "ok\n"));
    }
}
