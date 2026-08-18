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
        global $USER;

        $canattempt = has_capability('mod/saylorcode:attempt', $context);

        // Seed the editor server side. The student sees their own code in the
        // initial HTML rather than after a round trip, so a slow connection
        // shows work-in-progress rather than an empty box.
        $initialcode = '';
        $entryfilename = $moduleinstance->entryfilename ?? 'Main.java';

        if ($canattempt) {
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

        $data = [
            'cmid' => $cm->id,
            'instanceid' => $moduleinstance->id,
            'activitymode' => $moduleinstance->activitymode,
            'stableid' => $moduleinstance->stableid,
            'profileid' => $moduleinstance->profileid,
            'runtimename' => $runtimename,
            'entryfilename' => $entryfilename,
            'initialcode' => $initialcode,
            'hastests' => trim((string) ($moduleinstance->testcases ?? '')) !== '',
            // A playground is deliberately ungraded, so it has nothing to
            // submit. Every other mode records an official attempt, which
            // drives completion and grading whether or not tests exist.
            'cansubmit' => ($moduleinstance->activitymode ?? '') !== 'playground',
            'canattempt' => $canattempt,
            'allowhints' => !empty($moduleinstance->allowhints),
            'allowdownload' => !empty($moduleinstance->allowdownload),
            'nopermission' => $canattempt ? null : get_string('nopermissiontoattempt', 'mod_saylorcode'),
        ];

        return $this->render_from_template('mod_saylorcode/activity_shell', $data);
    }
}
