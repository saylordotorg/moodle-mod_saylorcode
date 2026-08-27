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

namespace mod_saylorcode\external;

use mod_saylorcode\local\exercise_validator;
use mod_saylorcode\tests\fixtures\scripted_provider;

/**
 * Tests for the activity form's Validate web service.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\external\validate_exercise
 */
final class validate_exercise_test extends \advanced_testcase {
    /**
     * Load the scripted provider fixture.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../fixtures/scripted_provider.php');
    }

    /**
     * Clear the injected provider between tests.
     */
    protected function tearDown(): void {
        exercise_validator::set_test_provider(null);
        parent::tearDown();
    }

    /**
     * A code-like case name survives the response cleaning intact.
     *
     * A name like "Returns List<String>" is stored raw by the form. Declaring
     * the return as PARAM_TEXT stripped the angle-bracket portion during
     * response cleaning, so the Validate button could not report the case; the
     * client renders the name with textContent, so it is returned raw.
     */
    public function test_a_code_like_case_name_is_not_stripped(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        exercise_validator::set_test_provider(new scripted_provider(['Hello']));

        $cases = json_encode([
            ['id' => 'T1', 'name' => 'Returns List<String>', 'expected' => 'Hello', 'ispublic' => true, 'weight' => 1],
        ]);

        $result = validate_exercise::execute($course->id, 'java17-console', 'Main.java', 'public class Main {}', $cases);
        $result = \core_external\external_api::clean_returnvalue(
            validate_exercise::execute_returns(),
            $result
        );

        $this->assertSame('Returns List<String>', $result['results'][0]['name']);
    }

    /**
     * A user who cannot author these activities may not run the check.
     */
    public function test_it_requires_the_add_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        exercise_validator::set_test_provider(new scripted_provider(['Hello']));

        $cases = json_encode([
            ['id' => 'T1', 'name' => 'greets', 'expected' => 'Hello', 'ispublic' => true, 'weight' => 1],
        ]);

        $this->expectException(\required_capability_exception::class);
        validate_exercise::execute($course->id, 'java17-console', 'Main.java', 'public class Main {}', $cases);
    }
}
