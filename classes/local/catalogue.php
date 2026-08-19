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
 * Queries behind the exercise catalogue.
 *
 * Exercises live on activities until the central library exists, so the
 * catalogue is a view over every activity on the site rather than over a
 * library table. That is a transitional shape: when exercises move into a
 * library, this class is what changes and the page above it does not.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogue {
    /**
     * Build the SQL behind the catalogue table.
     *
     * Returns the pieces table_sql needs rather than running the query, so the
     * table can sort and page without this class knowing how.
     *
     * @param array $filters Keys: search, courseid, profileid, layout, state.
     * @return array [fields, from, where, params]
     */
    public static function build_query(array $filters): array {
        global $DB;

        $fields = 's.id, s.stableid, s.name, s.course, s.profileid, s.layout, s.activitymode, '
            . 's.testcases, s.referencesolution, s.startercode, s.timemodified, '
            . 'c.shortname AS courseshortname, c.fullname AS coursefullname, cm.id AS cmid';

        $from = '{saylorcode} s
                 JOIN {course} c ON c.id = s.course
                 JOIN {modules} m ON m.name = :modname
                 JOIN {course_modules} cm ON cm.instance = s.id AND cm.module = m.id';

        $where = ['1 = 1'];
        $params = ['modname' => 'saylorcode'];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Matched against the things an author would actually search by.
            $where[] = '(' . $DB->sql_like('s.stableid', ':s1', false) . ' OR '
                . $DB->sql_like('s.name', ':s2', false) . ' OR '
                . $DB->sql_like('c.shortname', ':s3', false) . ')';
            $params['s1'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['s2'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['s3'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        if (!empty($filters['courseid'])) {
            $where[] = 's.course = :courseid';
            $params['courseid'] = (int) $filters['courseid'];
        }

        if (!empty($filters['profileid'])) {
            $where[] = 's.profileid = :profileid';
            $params['profileid'] = $filters['profileid'];
        }

        if (!empty($filters['layout'])) {
            $where[] = 's.layout = :layout';
            $params['layout'] = $filters['layout'];
        }

        // Readiness is derived rather than stored, because there is no
        // publication state yet. It answers the question an author actually
        // has: is this exercise finished enough to put in front of a student?
        $state = $filters['state'] ?? '';
        if ($state === 'notests') {
            $where[] = '(s.testcases IS NULL OR ' . $DB->sql_compare_text('s.testcases') . " = '')";
        } else if ($state === 'nosolution') {
            $where[] = '(s.referencesolution IS NULL OR '
                . $DB->sql_compare_text('s.referencesolution') . " = '')";
        } else if ($state === 'ready') {
            $where[] = '(s.testcases IS NOT NULL AND ' . $DB->sql_compare_text('s.testcases') . " <> ''"
                . ' AND s.referencesolution IS NOT NULL AND '
                . $DB->sql_compare_text('s.referencesolution') . " <> '')";
        }

        return [$fields, $from, implode(' AND ', $where), $params];
    }

    /**
     * Courses that hold at least one exercise, for the course filter.
     *
     * @return array Course id => short name.
     */
    public static function get_courses_with_exercises(): array {
        global $DB;

        $sql = 'SELECT DISTINCT c.id, c.shortname
                  FROM {saylorcode} s
                  JOIN {course} c ON c.id = s.course
              ORDER BY c.shortname ASC';

        $menu = [];
        foreach ($DB->get_records_sql($sql) as $course) {
            $menu[$course->id] = $course->shortname;
        }

        return $menu;
    }

    /**
     * How many test cases an exercise defines, and how many are hidden.
     *
     * @param string|null $testcases The stored JSON.
     * @return array [total, hidden]
     */
    public static function count_cases(?string $testcases): array {
        $decoded = json_decode((string) $testcases, true);

        if (!is_array($decoded)) {
            return [0, 0];
        }

        $hidden = 0;
        foreach ($decoded as $case) {
            if (empty($case['ispublic'])) {
                $hidden++;
            }
        }

        return [count($decoded), $hidden];
    }

    /**
     * How ready an exercise is to be put in front of a student.
     *
     * @param object $record A catalogue row.
     * @return string One of ready, nosolution, notests or empty.
     */
    public static function readiness(object $record): string {
        [$total] = self::count_cases($record->testcases ?? null);
        $hassolution = trim((string) ($record->referencesolution ?? '')) !== '';
        $hasstarter = trim((string) ($record->startercode ?? '')) !== '';

        if ($total > 0 && $hassolution) {
            return 'ready';
        }
        if ($total > 0) {
            return 'nosolution';
        }
        if ($hasstarter) {
            return 'notests';
        }

        return 'empty';
    }
}
