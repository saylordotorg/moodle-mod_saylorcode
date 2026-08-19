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

/**
 * Guards the backup field list against the database schema.
 *
 * The backup step enumerates its fields by hand, so a column added later is
 * silently dropped from every backup until someone remembers to edit that file.
 * That already happened once: startercode, testcases, entryfilename and layout
 * were all missing, which would have restored courses with the exercise content
 * emptied out and no error anywhere to say so.
 *
 * A test is the only thing that notices, because a backup that quietly loses a
 * column still succeeds.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class backup_fields_test extends \advanced_testcase {
    /**
     * Columns that are deliberately absent from the backup.
     *
     * @return string[]
     */
    private function excluded(): array {
        return [
            // Supplied by the restore, not carried from the source site.
            'id',
            'course',
        ];
    }

    /**
     * Every column on the activity table is backed up.
     */
    public function test_backup_covers_every_column(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $columns = array_keys($DB->get_columns('saylorcode'));
        $expected = array_values(array_diff($columns, $this->excluded()));

        $source = file_get_contents($CFG->dirroot . '/mod/saylorcode/backup/moodle2/backup_saylorcode_stepslib.php');
        $this->assertNotEmpty($source);

        // Pull the field list out of the activity element declaration.
        $matched = preg_match(
            '~backup_nested_element\(\s*\'saylorcode\'\s*,\s*\[\'id\'\]\s*,\s*\[(.*?)\]\s*\)~s',
            $source,
            $m
        );
        $this->assertSame(1, $matched, 'Could not find the saylorcode backup element declaration.');

        preg_match_all('~\'([a-z]+)\'~', $m[1], $found);
        $backedup = $found[1];

        $missing = array_values(array_diff($expected, $backedup));

        $this->assertSame(
            [],
            $missing,
            'These columns exist on the saylorcode table but are not backed up, so a restore would lose them: '
                . implode(', ', $missing)
        );
    }

    /**
     * The backup does not name columns that no longer exist.
     */
    public function test_backup_names_no_unknown_columns(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $columns = array_keys($DB->get_columns('saylorcode'));

        $source = file_get_contents($CFG->dirroot . '/mod/saylorcode/backup/moodle2/backup_saylorcode_stepslib.php');
        preg_match(
            '~backup_nested_element\(\s*\'saylorcode\'\s*,\s*\[\'id\'\]\s*,\s*\[(.*?)\]\s*\)~s',
            $source,
            $m
        );
        preg_match_all('~\'([a-z]+)\'~', $m[1], $found);

        $unknown = array_values(array_diff($found[1], $columns));

        $this->assertSame([], $unknown, 'Backed up fields that are not columns: ' . implode(', ', $unknown));
    }
}
