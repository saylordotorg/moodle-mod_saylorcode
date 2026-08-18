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
 * Backup task for mod_saylorcode.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/saylorcode/backup/moodle2/backup_saylorcode_stepslib.php');

/**
 * Backup task definition.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_saylorcode_activity_task extends backup_activity_task {
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
        $this->add_step(new backup_saylorcode_activity_structure_step('saylorcode_structure', 'saylorcode.xml'));
    }

    /**
     * Encode links to this activity so they survive a restore elsewhere.
     *
     * @param string $content The content to encode.
     * @return string
     */
    public static function encode_content_links($content): string {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the index of activities in a course.
        $content = preg_replace(
            '/(' . $base . '\/mod\/saylorcode\/index.php\?id\=)([0-9]+)/',
            '$@SAYLORCODEINDEX*$2@$',
            $content
        );

        // Link to one activity.
        $content = preg_replace(
            '/(' . $base . '\/mod\/saylorcode\/view.php\?id\=)([0-9]+)/',
            '$@SAYLORCODEVIEWBYID*$2@$',
            $content
        );

        return $content;
    }
}
