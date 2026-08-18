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

namespace mod_saylorcode\event;

use context_module;
use core\event\base;
use stdClass;

/**
 * Student code was run, checked or submitted.
 *
 * The mode and the resulting state travel in other, so a report can tell a
 * submission apart from an experiment, and a compile error apart from a runner
 * outage, without reading the execution table (specification section 18.1).
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class code_executed extends base {
    /**
     * Set the basic event properties.
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'saylorcode_attempts';
    }

    /**
     * Build the event for one execution.
     *
     * @param context_module $context The module context.
     * @param stdClass $attempt The attempt.
     * @param string $mode run, check or submit.
     * @param string $state The resulting execution state.
     * @return self
     */
    public static function create_from_attempt(
        context_module $context,
        stdClass $attempt,
        string $mode,
        string $state
    ): self {
        return self::create([
            'context' => $context,
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'other' => [
                'mode' => $mode,
                'state' => $state,
            ],
        ]);
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventcode_executed', 'mod_saylorcode');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description(): string {
        $mode = $this->other['mode'] ?? 'run';
        $state = $this->other['state'] ?? 'unknown';

        return "The user with id '{$this->userid}' performed a '{$mode}' execution on the attempt with id " .
            "'{$this->objectid}', which finished in state '{$state}'.";
    }
}
