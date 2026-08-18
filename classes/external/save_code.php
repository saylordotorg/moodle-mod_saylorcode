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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_saylorcode\local\attempt_manager;
use mod_saylorcode\local\workspace_context;

/**
 * Web service that persists the code a student is working on.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_code extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'files' => new external_value(PARAM_RAW, 'JSON object of relative path to file contents'),
            'browsersession' => new external_value(PARAM_ALPHANUMEXT, 'Identifier of the writing browser session', VALUE_DEFAULT, ''),
            'knownsnapshotid' => new external_value(PARAM_INT, 'Snapshot the client believes is current', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Save the current code.
     *
     * @param int $cmid Course module id.
     * @param string $files JSON file map.
     * @param string $browsersession Writing browser session.
     * @param int $knownsnapshotid Snapshot the client believes is current.
     * @return array
     */
    public static function execute(int $cmid, string $files, string $browsersession = '', int $knownsnapshotid = 0): array {
        [
            'cmid' => $cmid,
            'files' => $files,
            'browsersession' => $browsersession,
            'knownsnapshotid' => $knownsnapshotid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'files' => $files,
            'browsersession' => $browsersession,
            'knownsnapshotid' => $knownsnapshotid,
        ]);

        global $USER;

        $workspace = workspace_context::resolve($cmid);
        self::validate_context($workspace->context);

        $decoded = workspace_context::decode_files($files);

        $manager = new attempt_manager($workspace->instance);
        $attempt = $manager->get_or_create_attempt((int) $USER->id);

        $session = $browsersession !== '' ? $browsersession : null;

        // Refuse rather than overwrite. Losing a student's work silently is the
        // one failure this activity must not have.
        if ($manager->has_conflict($attempt, $session, $knownsnapshotid)) {
            return [
                'saved' => false,
                'conflict' => true,
                'snapshotid' => 0,
                'message' => get_string('saveconflict', 'mod_saylorcode'),
            ];
        }

        $snapshotid = $manager->save_snapshot($attempt, $decoded, attempt_manager::SNAPSHOT_AUTOSAVE, $session);

        \mod_saylorcode\event\code_saved::create_from_attempt($workspace->context, $attempt)->trigger();

        return [
            'saved' => true,
            'conflict' => false,
            'snapshotid' => $snapshotid,
            'message' => get_string('saved', 'mod_saylorcode'),
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_BOOL, 'Whether the code was stored'),
            'conflict' => new external_value(PARAM_BOOL, 'Whether newer work from another session blocked the save'),
            'snapshotid' => new external_value(PARAM_INT, 'The stored snapshot id, or 0'),
            'message' => new external_value(PARAM_TEXT, 'Message for the student'),
        ]);
    }
}
