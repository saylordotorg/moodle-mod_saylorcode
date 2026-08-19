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
use mod_saylorcode\local\content;
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

        // Read the step before recording the arrival, because arriving is what
        // finishes an instruction step: recording first would advance past the
        // very instructions this page exists to show.
        $current = $steps->get_current_step($attemptid);

        if ($current !== null) {
            $steps->get_or_create_step_attempt($attemptid, (int) $current->id);
        }

        // Read after, so a step just finished by arriving shows as finished and
        // the next one is already unlocked.
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
     * Render the workspace as an author preview.
     *
     * The same template as the real activity, because a preview that renders
     * through a different path stops being evidence of anything. What differs
     * is that nothing is persisted and the controls are inert: staff do not
     * hold mod/saylorcode:attempt, so wiring them up would only produce
     * permission errors. Whether the exercise actually passes its own tests is
     * what Validate answers; this answers what the student will see.
     *
     * @param stdClass $moduleinstance The activity instance.
     * @param cm_info $cm The course module.
     * @param context_module $context The module context.
     * @return string HTML.
     */
    public function render_preview(stdClass $moduleinstance, cm_info $cm, context_module $context): string {
        return $this->render_shell($moduleinstance, $cm, $context, true);
    }

    /**
     * Build and render the workspace shell.
     *
     * @param stdClass $moduleinstance The activity instance.
     * @param cm_info $cm The course module.
     * @param context_module $context The module context.
     * @param bool $preview Whether this is an author preview.
     * @return string HTML.
     */
    protected function render_shell(
        stdClass $moduleinstance,
        cm_info $cm,
        context_module $context,
        bool $preview
    ): string {
        global $USER;

        // In a preview the viewer is staff, who have no attempt of their own
        // and must not be given one. Treating them as able to attempt is what
        // makes the editor and the action bar render at all.
        $canattempt = $preview || has_capability('mod/saylorcode:attempt', $context);

        // Seed the editor server side. The student sees their own code in the
        // initial HTML rather than after a round trip, so a slow connection
        // shows work-in-progress rather than an empty box.
        $entryfilename = $moduleinstance->entryfilename ?? 'Main.java';
        $initialcode = '';

        if ($preview) {
            // The starter code is what a student meets on first opening, which
            // is the state a preview is for.
            $initialcode = (string) ($moduleinstance->startercode ?? '');
        } else if ($canattempt) {
            $manager = new \mod_saylorcode\local\attempt_manager($moduleinstance);
            $attempt = $manager->get_or_create_attempt((int) $USER->id);
            $files = $manager->get_current_files($attempt);
            $initialcode = (string) ($files[$entryfilename] ?? reset($files));
        }

        // Show the language by its student facing name rather than the profile
        // id, which is an internal handle and means nothing to a learner.
        $profile = (new \local_saylorcode\local\runtime\profile_manager())
            ->get_profile($moduleinstance->profileid);
        $runtimename = $profile ? $profile->get_display_name() : $moduleinstance->profileid;

        $hints = new \mod_saylorcode\local\hint_manager($moduleinstance);

        $layout = $moduleinstance->layout ?? 'split';

        $data = [
            'cmid' => $cm->id,
            'instanceid' => $moduleinstance->id,
            'activitymode' => $moduleinstance->activitymode,
            'stableid' => $moduleinstance->stableid,
            'profileid' => $moduleinstance->profileid,
            'runtimename' => $runtimename,
            'entryfilename' => $entryfilename,
            'initialcode' => $initialcode,
            'hastests' => self::has_tests($moduleinstance),
            'layout' => $layout,
            'isdrawer' => $layout === 'drawer',
            'istabs' => $layout === 'tabs',
            'expected' => $this->expected_lines($moduleinstance),
            // A playground is deliberately ungraded, so it has nothing to
            // submit. Every other mode records an official attempt, which
            // drives completion and grading whether or not tests exist.
            'cansubmit' => ($moduleinstance->activitymode ?? '') !== 'playground',
            'canattempt' => $canattempt,
            'allowhints' => $hints->has_hints(),
            // Solutions have their own capability, deliberately granted to no
            // archetype by default, so it is checked here as well as in the
            // service that hands the solution over.
            'allowsolution' => $hints->allows_solution()
                && has_capability('mod/saylorcode:viewsolutions', $context),
            'showhelp' => $hints->has_hints()
                || ($hints->allows_solution() && has_capability('mod/saylorcode:viewsolutions', $context)),
            'revealedhints' => $canattempt && isset($attempt) ? $hints->get_revealed($attempt) : [],
            'allowdownload' => !empty($moduleinstance->allowdownload),
            'nopermission' => $canattempt ? null : get_string('nopermissiontoattempt', 'mod_saylorcode'),
            'preview' => $preview,
        ];

        return $this->render_from_template('mod_saylorcode/activity_shell', $data);
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
        return content::for_instance($moduleinstance)->get_test_cases() !== [];
    }
}
