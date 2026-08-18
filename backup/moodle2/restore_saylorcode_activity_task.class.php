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
 * Restore task for mod_saylorcode.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/saylorcode/backup/moodle2/restore_saylorcode_stepslib.php');

/**
 * Restore task definition.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_saylorcode_activity_task extends restore_activity_task {
    /**
     * This activity has no additional settings.
     */
    protected function define_my_settings(): void {
        return;
    }

    /**
     * Add the activity structure step.
     */
    protected function define_my_steps(): void {
        $this->add_step(new restore_saylorcode_activity_structure_step('saylorcode_structure', 'saylorcode.xml'));
    }

    /**
     * File areas belonging to this activity.
     *
     * @return array
     */
    public static function define_decode_contents(): array {
        return [
            new restore_decode_content('saylorcode', ['intro'], 'saylorcode'),
        ];
    }

    /**
     * Rules for decoding links encoded during backup.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules(): array {
        return [
            new restore_decode_rule('SAYLORCODEVIEWBYID', '/mod/saylorcode/view.php?id=$1', 'course_module'),
            new restore_decode_rule('SAYLORCODEINDEX', '/mod/saylorcode/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Restore log rules for this activity.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules(): array {
        return [];
    }

    /**
     * Restore log rules for course level logs.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course(): array {
        return [];
    }
}
