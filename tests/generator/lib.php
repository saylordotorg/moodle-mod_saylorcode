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
 * Test generator for mod_saylorcode.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates Saylor Code Studio activities for tests.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_saylorcode_generator extends testing_module_generator {
    /**
     * Create an activity instance with workable defaults.
     *
     * @param array|stdClass|null $record Instance data.
     * @param array|null $options Generator options.
     * @return stdClass The created instance.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'activitymode' => 'challenge',
            'stableid' => 'CS101-U01-E01',
            'versionpolicy' => 'latest',
            'profileid' => 'java17-console',
            'layout' => 'split',
            'entryfilename' => 'Main.java',
            'startercode' => "public class Main {\n}\n",
            'testcases' => '',
            'referencesolution' => '',
            'maxattempts' => 0,
            'gradingmode' => 'tests',
            'allowhints' => 1,
            'allowsolution' => 0,
            'allowdownload' => 1,
            'grade' => 100,
            'completionpasstests' => 0,
            'completionminscore' => 0,
        ];

        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }

        return parent::create_instance($record, (array) $options);
    }
}
