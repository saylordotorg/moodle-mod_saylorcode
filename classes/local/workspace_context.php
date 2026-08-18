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

use context_module;
use stdClass;

/**
 * Resolves and authorises the workspace behind a course module id.
 *
 * Every web service in this plugin needs the same four things and the same two
 * checks. Keeping that in one place means a new endpoint cannot accidentally
 * ship without the capability check, which is the failure mode that matters.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workspace_context {
    /** @var stdClass The course. */
    public stdClass $course;

    /** @var stdClass The course module. */
    public $cm;

    /** @var context_module The module context. */
    public context_module $context;

    /** @var stdClass The activity instance. */
    public stdClass $instance;

    /**
     * Build the resolved context.
     *
     * @param stdClass $course The course.
     * @param stdClass $cm The course module.
     * @param context_module $context The module context.
     * @param stdClass $instance The activity instance.
     */
    private function __construct(stdClass $course, $cm, context_module $context, stdClass $instance) {
        $this->course = $course;
        $this->cm = $cm;
        $this->context = $context;
        $this->instance = $instance;
    }

    /**
     * Resolve a course module id and check the caller may attempt the activity.
     *
     * @param int $cmid The course module id.
     * @param string $capability The capability to require.
     * @return self
     */
    public static function resolve(int $cmid, string $capability = 'mod/saylorcode:attempt'): self {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'saylorcode');
        $context = context_module::instance($cm->id);

        require_capability($capability, $context);

        $instance = $DB->get_record('saylorcode', ['id' => $cm->instance], '*', MUST_EXIST);

        return new self($course, $cm, $context, $instance);
    }

    /**
     * Decode and validate a submitted file map.
     *
     * The editor sends a JSON object of path to contents. Paths are validated
     * by execution_request before anything reaches the runner, but a malformed
     * payload should be refused here rather than deeper in the stack.
     *
     * @param string $json The submitted JSON.
     * @return array Relative path => contents.
     * @throws \invalid_parameter_exception If the payload is not a file map.
     */
    public static function decode_files(string $json): array {
        $files = json_decode($json, true);

        if (!is_array($files) || empty($files)) {
            throw new \invalid_parameter_exception('Expected a JSON object of file paths to contents.');
        }

        foreach ($files as $path => $contents) {
            if (!is_string($path) || !is_string($contents)) {
                throw new \invalid_parameter_exception('File paths and contents must both be strings.');
            }
        }

        return $files;
    }
}
