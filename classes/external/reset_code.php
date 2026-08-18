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
 * Web service that restores the starter code for an attempt.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reset_code extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'browsersession' => new external_value(
                PARAM_ALPHANUMEXT,
                'Identifier of the requesting browser session',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Restore the starter code, keeping a recoverable snapshot of what was there.
     *
     * @param int $cmid Course module id.
     * @param string $browsersession Requesting browser session.
     * @return array
     */
    public static function execute(int $cmid, string $browsersession = ''): array {
        [
            'cmid' => $cmid,
            'browsersession' => $browsersession,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'browsersession' => $browsersession,
        ]);

        global $USER;

        $workspace = workspace_context::resolve($cmid);
        self::validate_context($workspace->context);

        $manager = new attempt_manager($workspace->instance);
        $attempt = $manager->get_or_create_attempt((int) $USER->id);

        $starter = $manager->reset($attempt, $browsersession !== '' ? $browsersession : null);

        \mod_saylorcode\event\code_reset::create_from_attempt($workspace->context, $attempt)->trigger();

        return [
            'files' => json_encode($starter),
            'message' => get_string('resetdone', 'mod_saylorcode'),
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'files' => new external_value(PARAM_RAW, 'JSON object of the restored starter files'),
            'message' => new external_value(PARAM_TEXT, 'Message for the student'),
        ]);
    }
}
