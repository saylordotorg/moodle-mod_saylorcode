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

/**
 * Tests for attempts and snapshots.
 *
 * These cover the rules that protect a student's work, which is the part of
 * this plugin where a regression is least acceptable.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_saylorcode\local\attempt_manager
 */
final class attempt_manager_test extends \advanced_testcase {
    /**
     * Build an activity, a student and a manager.
     *
     * @param array $overrides Instance overrides.
     * @return array The instance, student and manager.
     */
    private function build_fixture(array $overrides = []): array {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module(
            'saylorcode',
            array_merge(['course' => $course->id], $overrides)
        );

        return [$instance, $student, new attempt_manager($instance)];
    }

    /**
     * A student opening the activity twice keeps the same attempt.
     */
    public function test_attempt_is_reused(): void {
        [, $student, $manager] = $this->build_fixture();

        $first = $manager->get_or_create_attempt((int) $student->id);
        $second = $manager->get_or_create_attempt((int) $student->id);

        $this->assertSame($first->id, $second->id);
    }

    /**
     * A student who has saved nothing sees the starter code.
     */
    public function test_starter_code_is_the_fallback(): void {
        [$instance, $student, $manager] = $this->build_fixture([
            'startercode' => "public class Main {\n    // start here\n}\n",
        ]);

        $attempt = $manager->get_or_create_attempt((int) $student->id);
        $files = $manager->get_current_files($attempt);

        $this->assertArrayHasKey($instance->entryfilename, $files);
        $this->assertStringContainsString('start here', $files[$instance->entryfilename]);
    }

    /**
     * Saved work replaces the starter code.
     */
    public function test_saved_work_is_returned(): void {
        [$instance, $student, $manager] = $this->build_fixture();

        $attempt = $manager->get_or_create_attempt((int) $student->id);
        $manager->save_snapshot($attempt, [$instance->entryfilename => '// my work'], attempt_manager::SNAPSHOT_AUTOSAVE);

        $files = $manager->get_current_files($attempt);

        $this->assertSame('// my work', $files[$instance->entryfilename]);
    }

    /**
     * A save from another session, behind newer work, is a conflict.
     */
    public function test_stale_save_from_another_session_conflicts(): void {
        [$instance, $student, $manager] = $this->build_fixture();

        $attempt = $manager->get_or_create_attempt((int) $student->id);

        $autosave = attempt_manager::SNAPSHOT_AUTOSAVE;
        $first = $manager->save_snapshot($attempt, [$instance->entryfilename => 'tab A'], $autosave, 'tabA');
        $manager->save_snapshot($attempt, [$instance->entryfilename => 'tab B'], attempt_manager::SNAPSHOT_AUTOSAVE, 'tabB');

        $this->assertTrue($manager->has_conflict($attempt, 'tabA', $first));
    }

    /**
     * A session catching up with its own newer save is not a conflict.
     */
    public function test_same_session_does_not_conflict_with_itself(): void {
        [$instance, $student, $manager] = $this->build_fixture();

        $attempt = $manager->get_or_create_attempt((int) $student->id);

        $first = $manager->save_snapshot($attempt, [$instance->entryfilename => 'one'], attempt_manager::SNAPSHOT_AUTOSAVE, 'tabA');
        $manager->save_snapshot($attempt, [$instance->entryfilename => 'two'], attempt_manager::SNAPSHOT_AUTOSAVE, 'tabA');

        $this->assertFalse($manager->has_conflict($attempt, 'tabA', $first));
    }

    /**
     * A first save, with nothing known, is never a conflict.
     */
    public function test_first_save_is_not_a_conflict(): void {
        [, $student, $manager] = $this->build_fixture();

        $attempt = $manager->get_or_create_attempt((int) $student->id);

        $this->assertFalse($manager->has_conflict($attempt, 'tabA', 0));
    }

    /**
     * A reset restores the starter code and leaves the old work recoverable.
     */
    public function test_reset_preserves_the_discarded_work(): void {
        global $DB;

        [$instance, $student, $manager] = $this->build_fixture([
            'startercode' => '// starter',
        ]);

        $attempt = $manager->get_or_create_attempt((int) $student->id);
        $manager->save_snapshot($attempt, [$instance->entryfilename => '// hours of work'], attempt_manager::SNAPSHOT_AUTOSAVE);

        $restored = $manager->reset($attempt);

        $this->assertSame('// starter', $restored[$instance->entryfilename]);

        $recovery = $DB->get_records('saylorcode_snapshots', [
            'attemptid' => $attempt->id,
            'snapshottype' => attempt_manager::SNAPSHOT_RESET,
        ]);
        $this->assertCount(1, $recovery);

        $preserved = json_decode(reset($recovery)->files, true);
        $this->assertSame('// hours of work', $preserved[$instance->entryfilename]);
    }

    /**
     * Pruning discards old automatic saves but never a submission.
     */
    public function test_pruning_spares_submissions(): void {
        global $DB;

        set_config('snapshotsperattempt', 5, 'local_saylorcode');

        [$instance, $student, $manager] = $this->build_fixture();
        $attempt = $manager->get_or_create_attempt((int) $student->id);

        $manager->save_snapshot($attempt, [$instance->entryfilename => '// submitted'], attempt_manager::SNAPSHOT_SUBMIT);

        for ($i = 0; $i < 12; $i++) {
            $manager->save_snapshot($attempt, [$instance->entryfilename => "// autosave $i"], attempt_manager::SNAPSHOT_AUTOSAVE);
        }

        $autosaves = $DB->count_records('saylorcode_snapshots', [
            'attemptid' => $attempt->id,
            'snapshottype' => attempt_manager::SNAPSHOT_AUTOSAVE,
        ]);
        $submissions = $DB->count_records('saylorcode_snapshots', [
            'attemptid' => $attempt->id,
            'snapshottype' => attempt_manager::SNAPSHOT_SUBMIT,
        ]);

        $this->assertLessThanOrEqual(5, $autosaves);
        $this->assertSame(1, $submissions);
    }

    /**
     * Recording a submission keeps the better of the two scores.
     */
    public function test_submission_keeps_the_best_score(): void {
        global $DB;

        [$instance, $student, $manager] = $this->build_fixture();
        $attempt = $manager->get_or_create_attempt((int) $student->id);

        $manager->record_submission($attempt, 0.8);
        $attempt = $DB->get_record('saylorcode_attempts', ['id' => $attempt->id]);
        $this->assertEqualsWithDelta(0.8, (float) $attempt->score, 0.001);

        $manager->record_submission($attempt, 0.4);
        $attempt = $DB->get_record('saylorcode_attempts', ['id' => $attempt->id]);
        $this->assertEqualsWithDelta(0.8, (float) $attempt->score, 0.001);

        $manager->record_submission($attempt, 0.95);
        $attempt = $DB->get_record('saylorcode_attempts', ['id' => $attempt->id]);
        $this->assertEqualsWithDelta(0.95, (float) $attempt->score, 0.001);
    }
}
