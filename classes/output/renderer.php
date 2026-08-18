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
        $canattempt = has_capability('mod/saylorcode:attempt', $context);

        $data = [
            'cmid' => $cm->id,
            'instanceid' => $moduleinstance->id,
            'activitymode' => $moduleinstance->activitymode,
            'stableid' => $moduleinstance->stableid,
            'profileid' => $moduleinstance->profileid,
            'canattempt' => $canattempt,
            'allowhints' => !empty($moduleinstance->allowhints),
            'allowdownload' => !empty($moduleinstance->allowdownload),
            'nopermission' => $canattempt ? null : get_string('nopermissiontoattempt', 'mod_saylorcode'),
        ];

        return $this->render_from_template('mod_saylorcode/activity_shell', $data);
    }
}
