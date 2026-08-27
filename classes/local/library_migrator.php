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

use local_saylorcode\local\library\exercise_repository;
use local_saylorcode\local\stable_id;
use stdClass;

/**
 * Moves exercise content off activities and into the shared library.
 *
 * Every exercise authored before the library stored its starter code and tests
 * on the activity itself. The resolver already falls back to those fields, so
 * nothing is broken; but that content cannot be shared, versioned or reused
 * until it lives in the library. This publishes it there.
 *
 * The move is transparent by design. An activity carries a stable id and, by
 * default, a policy of "latest", so the moment a library exercise with that id
 * is published the activity resolves to it instead of its own fields. Because
 * the published content is copied verbatim from the activity, what a student
 * meets does not change -- the same exercise, now served from the library. The
 * activity is left untouched, so the move is one directional and a mistake
 * costs nothing but a library row.
 *
 * This lives in the activity module rather than the library because the module
 * owns the activities being read and already depends on the library it writes
 * to; the dependency must not run the other way.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library_migrator {
    /** @var string Will create and publish a library exercise from this activity. */
    public const ACTION_CREATE = 'create';

    /** @var string A library exercise with this reference already exists. */
    public const ACTION_INLIBRARY = 'inlibrary';

    /** @var string Several activities share this reference with differing content. */
    public const ACTION_CONFLICT = 'conflict';

    /** @var string The activity has no content that could be published. */
    public const ACTION_EMPTY = 'empty';

    /** @var exercise_repository The library. */
    protected exercise_repository $repository;

    /**
     * Build the migrator.
     *
     * @param exercise_repository|null $repository The library, defaulting to the real one.
     */
    public function __construct(?exercise_repository $repository = null) {
        $this->repository = $repository ?? new exercise_repository();
    }

    /**
     * The content fields that define an exercise, in the order the library holds them.
     *
     * Name is not among them: two activities may reasonably differ in name while
     * carrying the same exercise, and a cosmetic difference is not a conflict.
     *
     * @var string[]
     */
    protected const CONTENT_FIELDS = [
        'profileid', 'entryfilename', 'startercode', 'referencesolution', 'testcases', 'hints',
    ];

    /**
     * Work out what migrating would do, without changing anything.
     *
     * @param int|null $courseid Restrict to one course, or null for the whole site.
     * @return array One entry per stable id: stableid, action, name, count, and
     *               for a conflict the differing courses.
     */
    public function plan(?int $courseid = null): array {
        $plan = [];

        foreach ($this->grouped_sources($courseid) as $stableid => $sources) {
            $plan[] = $this->assess($stableid, $sources);
        }

        return $plan;
    }

    /**
     * Migrate, creating and publishing a library exercise for every activity
     * that can have one and does not already.
     *
     * @param int|null $courseid Restrict to one course, or null for the whole site.
     * @return array The plan, each entry with an outcome: 'migrated' with a
     *               version number, or 'skipped'.
     */
    public function migrate(?int $courseid = null): array {
        $report = [];

        foreach ($this->grouped_sources($courseid) as $stableid => $sources) {
            $entry = $this->assess($stableid, $sources);

            if ($entry['action'] === self::ACTION_CREATE) {
                $source = reset($sources);
                $exercise = $this->repository->create(
                    $stableid,
                    $source->name,
                    $this->content_fields($source)
                );
                $version = $this->repository->publish($exercise, get_string('migratechangenote', 'mod_saylorcode'));

                $entry['outcome'] = 'migrated';
                $entry['version'] = (int) $version->version;
            } else {
                $entry['outcome'] = 'skipped';
            }

            $report[] = $entry;
        }

        return $report;
    }

    /**
     * Decide what should happen for one stable id.
     *
     * @param string $stableid The canonical reference.
     * @param stdClass[] $sources The activities carrying it.
     * @return array The plan entry.
     */
    protected function assess(string $stableid, array $sources): array {
        $source = reset($sources);

        $entry = [
            'stableid' => $stableid,
            'name' => $source->name,
            'count' => count($sources),
            'action' => self::ACTION_CREATE,
        ];

        if ($this->repository->find($stableid) !== null) {
            $entry['action'] = self::ACTION_INLIBRARY;
            return $entry;
        }

        if (count($sources) > 1 && !$this->content_agrees($sources)) {
            // The library holds one exercise per reference, so divergent copies
            // cannot all be it. Refusing rather than guessing which is canonical
            // keeps the migration from silently picking the wrong content.
            $entry['action'] = self::ACTION_CONFLICT;
            $entry['courses'] = array_values(array_unique(array_map(static function (stdClass $s): int {
                return (int) $s->course;
            }, $sources)));
            return $entry;
        }

        if (!$this->has_content($source)) {
            $entry['action'] = self::ACTION_EMPTY;
        }

        return $entry;
    }

    /**
     * Activities that carry a stable id, grouped by its canonical form.
     *
     * The grouping is always site-wide, even when a course is named. A course
     * only restricts which references are migrated; conflict detection still has
     * to see every activity carrying a reference, because the resolver resolves
     * a library exercise globally. Grouping within one course would let a
     * divergent copy in another course go unseen, and publishing the selected
     * course's copy would then silently switch that other activity to it.
     *
     * @param int|null $courseid Restrict which references are returned to those
     *                          appearing in this course, or null for all. Each
     *                          returned group still lists its activities site-wide.
     * @return array<string, stdClass[]> Canonical stable id => activities, site-wide.
     */
    protected function grouped_sources(?int $courseid): array {
        global $DB;

        $conditions = $DB->sql_like('stableid', ':pattern', false) . ' AND stableid IS NOT NULL';
        $records = $DB->get_records_select('saylorcode', $conditions, ['pattern' => '%_%'], 'id ASC');

        $grouped = [];
        $incourse = [];
        foreach ($records as $record) {
            $stableid = trim((string) $record->stableid);

            // Only well-formed references can become library exercises; a
            // playground or a malformed id is left on its activity.
            if ($stableid === '' || !stable_id::is_valid($stableid)) {
                continue;
            }

            $canonical = (string) stable_id::parse($stableid);
            $grouped[$canonical][] = $record;

            if ($courseid !== null && (int) $record->course === $courseid) {
                $incourse[$canonical] = true;
            }
        }

        if ($courseid === null) {
            return $grouped;
        }

        // Keep only references that appear in the named course, but carry each
        // one's full site-wide activity list forward, so a divergent copy
        // elsewhere is still seen and reported as a conflict.
        return array_intersect_key($grouped, $incourse);
    }

    /**
     * Whether every activity in a group carries the same exercise content.
     *
     * @param stdClass[] $sources The activities.
     * @return bool
     */
    protected function content_agrees(array $sources): bool {
        $first = null;

        foreach ($sources as $source) {
            $signature = [];
            foreach (self::CONTENT_FIELDS as $field) {
                $signature[$field] = (string) ($source->{$field} ?? '');
            }

            if ($first === null) {
                $first = $signature;
            } else if ($signature !== $first) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an activity has anything worth publishing.
     *
     * Mirrors what the library will accept: an exercise needs starter code or
     * test cases, or publishing refuses it.
     *
     * @param stdClass $source The activity.
     * @return bool
     */
    protected function has_content(stdClass $source): bool {
        return trim((string) ($source->startercode ?? '')) !== ''
            || trim((string) ($source->testcases ?? '')) !== '';
    }

    /**
     * The content fields to write into the library draft.
     *
     * @param stdClass $source The activity.
     * @return array
     */
    protected function content_fields(stdClass $source): array {
        $fields = [];
        foreach (self::CONTENT_FIELDS as $field) {
            $fields[$field] = (string) ($source->{$field} ?? '');
        }

        return $fields;
    }
}
