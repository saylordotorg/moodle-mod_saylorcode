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

use stdClass;

/**
 * Creates and reads student attempts and the code snapshots inside them.
 *
 * Snapshots are the record of a student's work, so the rules here are
 * deliberately conservative: a snapshot is written before anything destructive,
 * pruning never touches a snapshot tied to a submission, and a save that would
 * overwrite newer work from another tab is reported rather than applied
 * (specification sections 9.5 and 9.6).
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_manager {
    /** @var string An automatic save while the student types. */
    public const SNAPSHOT_AUTOSAVE = 'autosave';

    /** @var string Saved because the student ran their code. */
    public const SNAPSHOT_RUN = 'run';

    /** @var string Saved because the student checked their code. */
    public const SNAPSHOT_CHECK = 'check';

    /** @var string An immutable record of an official submission. */
    public const SNAPSHOT_SUBMIT = 'submit';

    /** @var string Taken immediately before a reset, so the work can be recovered. */
    public const SNAPSHOT_RESET = 'reset';

    /** @var stdClass The activity instance. */
    protected stdClass $instance;

    /**
     * Build a manager for one activity.
     *
     * @param stdClass $instance The saylorcode instance record.
     */
    public function __construct(stdClass $instance) {
        $this->instance = $instance;
    }

    /**
     * Get the user's live attempt, creating one if they have not started.
     *
     * @param int $userid The student.
     * @return stdClass The attempt record.
     */
    public function get_or_create_attempt(int $userid): stdClass {
        global $DB;

        $existing = $DB->get_records(
            'saylorcode_attempts',
            ['saylorcodeid' => $this->instance->id, 'userid' => $userid],
            'attemptnumber DESC',
            '*',
            0,
            1
        );

        $attempt = reset($existing);
        if ($attempt) {
            return $attempt;
        }

        $now = time();
        $attempt = (object) [
            'saylorcodeid' => $this->instance->id,
            'userid' => $userid,
            'attemptnumber' => 1,
            'status' => 'inprogress',
            'timestarted' => $now,
            'timemodified' => $now,
        ];
        $id = $DB->insert_record('saylorcode_attempts', $attempt);

        // Read the row back rather than returning the object that was inserted.
        // That object carries only the fields set above, so a caller acting on
        // a brand new attempt would find score and the other defaulted columns
        // missing. It also keeps column types consistent with the read path,
        // which returns ids as strings on some drivers.
        return $DB->get_record('saylorcode_attempts', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * The files the student should currently see.
     *
     * Falls back to the starter code when nothing has been saved yet, so a
     * student always opens onto something valid rather than an empty editor.
     *
     * @param stdClass $attempt The attempt.
     * @return array Relative path => contents.
     */
    public function get_current_files(stdClass $attempt): array {
        $latest = $this->get_latest_snapshot($attempt->id);

        if ($latest !== null) {
            $files = json_decode($latest->files, true);
            if (is_array($files) && !empty($files)) {
                return $files;
            }
        }

        return $this->get_starter_files();
    }

    /**
     * The starter files for this activity.
     *
     * Resolved rather than read off the instance, so an activity pointed at a
     * published library exercise gets that exercise's code. Anything the
     * library cannot answer falls back to the activity's own fields, which is
     * what everything authored before the library does.
     *
     * @return array Relative path => contents.
     */
    public function get_starter_files(): array {
        $content = content::for_instance($this->instance);

        return [
            $content->get_entry_filename() => $content->get_starter_code(),
        ];
    }

    /**
     * The most recent snapshot for an attempt.
     *
     * @param int $attemptid The attempt.
     * @return stdClass|null
     */
    public function get_latest_snapshot(int $attemptid): ?stdClass {
        global $DB;

        $records = $DB->get_records(
            'saylorcode_snapshots',
            ['attemptid' => $attemptid],
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );

        $latest = reset($records);

        return $latest ?: null;
    }

    /**
     * Write a snapshot.
     *
     * @param stdClass $attempt The attempt.
     * @param array $files Relative path => contents.
     * @param string $type One of the SNAPSHOT_* constants.
     * @param string|null $sessionkey Browser session that wrote it, for conflict detection.
     * @param string|null $label Optional human readable label.
     * @return int The new snapshot id.
     */
    public function save_snapshot(
        stdClass $attempt,
        array $files,
        string $type = self::SNAPSHOT_AUTOSAVE,
        ?string $sessionkey = null,
        ?string $label = null
    ): int {
        global $DB;

        $now = time();
        $snapshot = (object) [
            'attemptid' => $attempt->id,
            'stepid' => null,
            'snapshottype' => $type,
            'files' => json_encode($files),
            'label' => $label,
            'sessionkey' => $sessionkey,
            'timecreated' => $now,
        ];

        $snapshotid = $DB->insert_record('saylorcode_snapshots', $snapshot);

        $DB->set_field('saylorcode_attempts', 'timemodified', $now, ['id' => $attempt->id]);

        $this->prune_autosaves($attempt->id);

        return $snapshotid;
    }

    /**
     * Whether another browser session has saved since the one we know about.
     *
     * Two tabs open on the same exercise is a common and legitimate situation.
     * The rule is that neither may silently discard the other's work, so a save
     * arriving behind newer work is refused and the student is asked
     * (specification section 9.5).
     *
     * @param stdClass $attempt The attempt.
     * @param string|null $sessionkey The saving session.
     * @param int $knownsnapshotid The snapshot the client believes is current.
     * @return bool True when a conflicting newer save exists.
     */
    public function has_conflict(stdClass $attempt, ?string $sessionkey, int $knownsnapshotid): bool {
        $latest = $this->get_latest_snapshot($attempt->id);

        if ($latest === null || $knownsnapshotid === 0) {
            return false;
        }

        // Same session catching up with itself is not a conflict.
        if ($sessionkey !== null && $latest->sessionkey === $sessionkey) {
            return false;
        }

        return (int) $latest->id > $knownsnapshotid;
    }

    /**
     * Reset the attempt back to the starter code.
     *
     * @param stdClass $attempt The attempt.
     * @param string|null $sessionkey The requesting session.
     * @return array The starter files now in place.
     */
    public function reset(stdClass $attempt, ?string $sessionkey = null): array {
        $current = $this->get_current_files($attempt);

        // Always preserve what is being discarded before discarding it.
        $this->save_snapshot($attempt, $current, self::SNAPSHOT_RESET, $sessionkey);

        $starter = $this->get_starter_files();
        $this->save_snapshot($attempt, $starter, self::SNAPSHOT_AUTOSAVE, $sessionkey);

        return $starter;
    }

    /**
     * Discard the oldest automatic snapshots beyond the configured limit.
     *
     * Only automatic saves are pruned. A snapshot taken for a submission or a
     * reset is a record of something the student did deliberately, and is kept.
     *
     * @param int $attemptid The attempt.
     */
    protected function prune_autosaves(int $attemptid): void {
        global $DB;

        $keep = (int) get_config('local_saylorcode', 'snapshotsperattempt');
        if ($keep <= 0) {
            $keep = 20;
        }

        $autosaves = $DB->get_records(
            'saylorcode_snapshots',
            ['attemptid' => $attemptid, 'snapshottype' => self::SNAPSHOT_AUTOSAVE],
            'timecreated DESC, id DESC',
            'id'
        );

        if (count($autosaves) <= $keep) {
            return;
        }

        $surplus = array_slice(array_keys($autosaves), $keep);
        [$insql, $params] = $DB->get_in_or_equal($surplus);
        $DB->delete_records_select('saylorcode_snapshots', "id $insql", $params);
    }

    /**
     * Record the outcome of an assessed submission on the attempt.
     *
     * @param stdClass $attempt The attempt.
     * @param float $fraction Score between 0 and 1.
     */
    public function record_submission(stdClass $attempt, float $fraction): void {
        global $DB;

        $now = time();

        // Keep the best score across submissions, which is the default policy.
        $previous = $attempt->score ?? null;
        $best = $previous === null ? $fraction : max((float) $previous, $fraction);

        $DB->update_record('saylorcode_attempts', (object) [
            'id' => $attempt->id,
            'status' => 'submitted',
            'score' => $best,
            'timesubmitted' => $now,
            'timemodified' => $now,
        ]);
    }
}
