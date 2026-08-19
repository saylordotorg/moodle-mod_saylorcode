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

namespace mod_saylorcode\output;

use context_module;
use mod_saylorcode\local\attempt_manager;
use mod_saylorcode\local\step_manager;
use cm_info;
use plugin_renderer_base;
use stdClass;

/**
 * Renderer for the Saylor Code Studio activity.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Render the activity shell for a student or a member of staff.
     *
     * The shell is rendered server side and progressively enhanced. If
     * JavaScript is unavailable the student still sees the instructions and a
     * readable copy of the starter code rather than an empty panel
     * (specification section 8.5).
     *
     * @param stdClass $moduleinstance The activity instance.
     * @param cm_info $cm The course module.
     * @param context_module $context The module context.
     * @return string HTML.
     */
    public function render_activity(stdClass $moduleinstance, cm_info $cm, context_module $context): string {
        $steps = new step_manager($moduleinstance);

        if (!$steps->is_guided()) {
            return $this->render_shell($moduleinstance, $cm, $context, false);
        }

        return $this->render_guided($moduleinstance, $cm, $context, $steps);
    }

    /**
     * Render a guided lesson: the step panel above the workspace.
     *
     * The workspace itself is unchanged, because a guided lesson is the same
     * editor with a smaller question in front of it. Sharing it means a fix to
     * one is a fix to both.
     *
     * @param stdClass $moduleinstance The activity instance.
     * @param cm_info $cm The course module.
     * @param context_module $context The module context.
     * @param step_manager $steps The sequencer.
     * @return string HTML.
     */
    protected function render_guided(
        stdClass $moduleinstance,
        cm_info $cm,
        context_module $context,
        step_manager $steps
    ): string {
        global $USER;

        if (!has_capability('mod/saylorcode:attempt', $context)) {
            // Staff see the workspace and its permission notice, the same
            // bargain the ordinary activity strikes. A step panel driven by an
            // attempt they do not have would say nothing true.
            return $this->render_shell($moduleinstance, $cm, $context, false);
        }

        $manager = new attempt_manager($moduleinstance);
        $attempt = $manager->get_or_create_attempt((int) $USER->id);
        $attemptid = (int) $attempt->id;

        $current = $steps->get_current_step($attemptid);
        $progress = $steps->get_progress($attemptid);
        $stepattempts = $steps->get_step_attempts($attemptid);

        $list = [];
        $number = 0;
        foreach ($steps->get_steps() as $step) {
            $number++;
            $stepattempt = $stepattempts[(int) $step->id] ?? null;

            $list[] = [
                'id' => (int) $step->id,
                'number' => $number,
                'title' => format_string($step->title),
                'iscurrent' => $current !== null && (int) $step->id === (int) $current->id,
                'iscomplete' => $stepattempt !== null
                    && $stepattempt->status === step_manager::STATUS_COMPLETE,
                'islocked' => !$steps->can_open_step($attemptid, (int) $step->id),
            ];
        }

        $panel = $this->render_from_template('mod_saylorcode/guided_panel', [
            'cmid' => $cm->id,
            'currentstepid' => $current ? (int) $current->id : 0,
            'title' => $current ? format_string($current->title) : '',
            'instructions' => $current
                ? format_text(
                    (string) $current->instructions,
                    (int) $current->instructionsformat,
                    ['context' => $context]
                )
                : '',
            'completionhint' => $current ? step_manager::completion_hint($current) : '',
            'stepscomplete' => $progress['complete'],
            'stepstotal' => $progress['total'],
            'percent' => $progress['percent'],
            'steps' => $list,
        ]);

        return $panel . $this->render_shell($moduleinstance, $cm, $context, false);
    }

    /**
     * The expected output shown on the feedback tab.
     *
     * Taken from the first public test case, because that is the one the tab is
     * inviting the student to compare their own output against. A hidden case
     * is never used here, since its expected value must not be disclosed.
     *
     * @param stdClass $moduleinstance The activity instance.
     * @return array Lines, each as an array with a line key.
     */
    protected function expected_lines(stdClass $moduleinstance): array {
        $cases = json_decode((string) ($moduleinstance->testcases ?? ''), true);
        if (!is_array($cases)) {
            return [];
        }

        foreach ($cases as $case) {
            if (empty($case['ispublic']) || !isset($case['expected'])) {
                continue;
            }

            $lines = preg_split('~\R~', rtrim((string) $case['expected'], "\n"));

            return array_map(static function (string $line): array {
                return ['line' => $line];
            }, $lines ?: []);
        }

        return [];
    }
    /**
     * Whether the activity actually defines any test cases.
     *
     * Decides from the decoded array rather than from whether the stored string
     * is non-empty, because "[]" is a non-empty string describing no tests.
     *
     * @param stdClass $moduleinstance The activity instance.
     * @return bool
     */
    protected static function has_tests(stdClass $moduleinstance): bool {
        $decoded = json_decode((string) ($moduleinstance->testcases ?? ''), true);

        return is_array($decoded) && !empty($decoded);
    }
}
