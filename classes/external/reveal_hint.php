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
use mod_saylorcode\local\hint_manager;
use mod_saylorcode\local\workspace_context;

/**
 * Give a student the next hint, or the reference solution.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reveal_hint extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'what' => new external_value(PARAM_ALPHA, 'hint or solution', VALUE_DEFAULT, 'hint'),
        ]);
    }

    /**
     * Reveal the next hint, or the solution.
     *
     * @param int $cmid Course module id.
     * @param string $what hint or solution.
     * @return array
     */
    public static function execute(int $cmid, string $what = 'hint'): array {
        ['cmid' => $cmid, 'what' => $what] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'what' => $what]
        );

        global $USER;

        $workspace = workspace_context::resolve($cmid);
        self::validate_context($workspace->context);

        $manager = new attempt_manager($workspace->instance);
        $attempt = $manager->get_or_create_attempt((int) $USER->id);

        $hints = new hint_manager($workspace->instance);

        if ($what === 'solution') {
            // Refused rather than quietly returning nothing, so an author who
            // has turned the solution off can trust that it is off.
            if (!$hints->allows_solution()) {
                return self::empty_payload();
            }

            return [
                'text' => $hints->reveal_solution($attempt),
                'issolution' => true,
                'number' => 0,
                'total' => 0,
                'remaining' => 0,
            ];
        }

        $revealed = $hints->reveal_next($attempt);

        return [
            'text' => $revealed['text'],
            'issolution' => false,
            'number' => $revealed['number'],
            'total' => $revealed['total'],
            'remaining' => $revealed['remaining'],
        ];
    }

    /**
     * Nothing to give.
     *
     * @return array
     */
    protected static function empty_payload(): array {
        return ['text' => '', 'issolution' => false, 'number' => 0, 'total' => 0, 'remaining' => 0];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'text' => new external_value(PARAM_RAW, 'The hint or solution, empty when there is nothing to give'),
            'issolution' => new external_value(PARAM_BOOL, 'Whether this is the reference solution'),
            'number' => new external_value(PARAM_INT, 'Which hint this is'),
            'total' => new external_value(PARAM_INT, 'How many hints exist'),
            'remaining' => new external_value(PARAM_INT, 'How many are left'),
        ]);
    }
}
