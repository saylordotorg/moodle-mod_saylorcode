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

/**
 * Upgrade steps for mod_saylorcode.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply the upgrade steps needed to reach the current version.
 *
 * @param int $oldversion The version currently installed.
 * @return bool
 */
function xmldb_saylorcode_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081801) {
        $table = new xmldb_table('saylorcode');

        $field = new xmldb_field('entryfilename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'Main.java', 'profileid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('startercode', XMLDB_TYPE_TEXT, null, null, null, null, null, 'entryfilename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('testcases', XMLDB_TYPE_TEXT, null, null, null, null, null, 'startercode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026081801, 'saylorcode');
    }

    if ($oldversion < 2026081802) {
        $table = new xmldb_table('saylorcode');

        $field = new xmldb_field('layout', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'split', 'profileid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026081802, 'saylorcode');
    }

    if ($oldversion < 2026081803) {
        $table = new xmldb_table('saylorcode');

        $field = new xmldb_field('referencesolution', XMLDB_TYPE_TEXT, null, null, null, null, null, 'startercode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026081803, 'saylorcode');
    }

    if ($oldversion < 2026081903) {
        $table = new xmldb_table('saylorcode');
        $field = new xmldb_field('hints', XMLDB_TYPE_TEXT, null, null, null, null, null, 'allowdownload');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('saylorcode_attempts');
        $field = new xmldb_field('hintsused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'currentstepid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('solutionviewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'hintsused');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026081903, 'saylorcode');
    }

    return true;
}
