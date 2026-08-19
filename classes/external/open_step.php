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
use mod_saylorcode\local\step_manager;
use mod_saylorcode\local\workspace_context;
use moodle_exception;

/**
 * Move a student to a step of a guided lesson.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class open_step extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'stepid' => new external_value(PARAM_INT, 'The step to open'),
        ]);
    }

    /**
     * Open a step and return what the interface needs to show it.
     *
     * @param int $cmid Course module id.
     * @param int $stepid The step to open.
     * @return array
     */
    public static function execute(int $cmid, int $stepid): array {
        ['cmid' => $cmid, 'stepid' => $stepid] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'stepid' => $stepid]
        );

        global $USER;

        // The resolver requires mod/saylorcode:attempt itself, which is the
        // capability a student needs to work through a lesson.
        $workspace = workspace_context::resolve($cmid);
        self::validate_context($workspace->context);

        $steps = new step_manager($workspace->instance);

        if (!$steps->is_guided()) {
            throw new moodle_exception('notaguidedlesson', 'mod_saylorcode');
        }

        $manager = new attempt_manager($workspace->instance);
        $attempt = $manager->get_or_create_attempt((int) $USER->id);

        // Refused rather than silently redirected. A student who has reached a
        // locked step has either been sent a link or is exploring, and telling
        // them plainly is better than moving them somewhere they did not ask
        // for.
        if (!$steps->can_open_step((int) $attempt->id, $stepid)) {
            throw new moodle_exception('steplocked', 'mod_saylorcode');
        }

        $all = $steps->get_steps();
        $step = $all[$stepid];

        $stepattempt = $steps->get_or_create_step_attempt((int) $attempt->id, $stepid);

        // The code the student should see here: their own work on this step if
        // they have any, otherwise whatever the step starts from.
        $files = $manager->get_current_files($attempt);
        $starting = $steps->starting_files_for($step, $files);
        $entry = $workspace->instance->entryfilename ?? 'Main.java';

        $progress = $steps->get_progress((int) $attempt->id);

        return [
            'stepid' => (int) $step->id,
            'title' => format_string($step->title),
            'instructions' => format_text(
                (string) $step->instructions,
                (int) $step->instructionsformat,
                ['context' => $workspace->context]
            ),
            'steptype' => $step->steptype,
            'completionrule' => $step->completionrule,
            'completionhint' => step_manager::completion_hint($step),
            'status' => $stepattempt->status,
            'code' => (string) ($starting[$entry] ?? reset($starting)),
            'stepscomplete' => $progress['complete'],
            'stepstotal' => $progress['total'],
            'percent' => $progress['percent'],
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'stepid' => new external_value(PARAM_INT, 'The step opened'),
            'title' => new external_value(PARAM_TEXT, 'Step title'),
            'instructions' => new external_value(PARAM_RAW, 'Formatted instructions'),
            'steptype' => new external_value(PARAM_ALPHA, 'Step type'),
            'completionrule' => new external_value(PARAM_ALPHA, 'What finishes this step'),
            'completionhint' => new external_value(PARAM_TEXT, 'What the student must do to finish it'),
            'status' => new external_value(PARAM_ALPHA, 'Status of the student on this step'),
            'code' => new external_value(PARAM_RAW, 'Code the editor should show'),
            'stepscomplete' => new external_value(PARAM_INT, 'Steps completed'),
            'stepstotal' => new external_value(PARAM_INT, 'Steps in the lesson'),
            'percent' => new external_value(PARAM_INT, 'Percentage complete'),
        ]);
    }
}
