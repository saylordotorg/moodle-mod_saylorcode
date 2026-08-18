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

/**
 * Restore steps for mod_saylorcode.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore the structure written by the backup step.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_saylorcode_activity_structure_step extends restore_activity_structure_step {
    /**
     * Declare the paths this step handles.
     *
     * @return restore_path_element[]
     */
    protected function define_structure(): array {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('saylorcode', '/activity/saylorcode');
        $paths[] = new restore_path_element('saylorcode_step', '/activity/saylorcode/steps/step');

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'saylorcode_attempt',
                '/activity/saylorcode/attempts/attempt'
            );
            $paths[] = new restore_path_element(
                'saylorcode_stepattempt',
                '/activity/saylorcode/attempts/attempt/stepattempts/stepattempt'
            );
            $paths[] = new restore_path_element(
                'saylorcode_snapshot',
                '/activity/saylorcode/attempts/attempt/snapshots/snapshot'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity instance.
     *
     * @param array $data The parsed element.
     */
    protected function process_saylorcode(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('saylorcode', $data);
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('saylorcode', $oldid, $newitemid);
    }

    /**
     * Restore one guided step.
     *
     * @param array $data The parsed element.
     */
    protected function process_saylorcode_step(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->saylorcodeid = $this->get_new_parentid('saylorcode');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('saylorcode_steps', $data);
        $this->set_mapping('saylorcode_step', $oldid, $newitemid);
    }

    /**
     * Restore one attempt.
     *
     * @param array $data The parsed element.
     */
    protected function process_saylorcode_attempt(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->saylorcodeid = $this->get_new_parentid('saylorcode');
        $data->userid = $this->get_mappingid('user', $data->userid);

        // The current step points at a step row that has just been remapped.
        if (!empty($data->currentstepid)) {
            $data->currentstepid = $this->get_mappingid('saylorcode_step', $data->currentstepid);
        }

        $data->timestarted = $this->apply_date_offset($data->timestarted);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timesubmitted = $this->apply_date_offset($data->timesubmitted);
        $data->timecompleted = $this->apply_date_offset($data->timecompleted);

        $newitemid = $DB->insert_record('saylorcode_attempts', $data);
        $this->set_mapping('saylorcode_attempt', $oldid, $newitemid);
    }

    /**
     * Restore one step attempt.
     *
     * @param array $data The parsed element.
     */
    protected function process_saylorcode_stepattempt(array $data): void {
        global $DB;

        $data = (object) $data;
        $data->attemptid = $this->get_new_parentid('saylorcode_attempt');
        $data->stepid = $this->get_mappingid('saylorcode_step', $data->stepid);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timefirstpassed = $this->apply_date_offset($data->timefirstpassed);
        $data->timecompleted = $this->apply_date_offset($data->timecompleted);

        // The latest snapshot pointer is resolved after snapshots are restored,
        // because the snapshot row may not exist yet at this point.
        $data->latestsnapshotid = null;

        $DB->insert_record('saylorcode_stepattempts', $data);
    }

    /**
     * Restore one code snapshot.
     *
     * @param array $data The parsed element.
     */
    protected function process_saylorcode_snapshot(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->attemptid = $this->get_new_parentid('saylorcode_attempt');

        if (!empty($data->stepid)) {
            $data->stepid = $this->get_mappingid('saylorcode_step', $data->stepid);
        }

        // The originating browser session has no meaning on a restored site.
        $data->sessionkey = null;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timeexpires = $this->apply_date_offset($data->timeexpires);

        $newitemid = $DB->insert_record('saylorcode_snapshots', $data);
        $this->set_mapping('saylorcode_snapshot', $oldid, $newitemid);
    }

    /**
     * Work that must happen once every row exists.
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_saylorcode', 'intro', null);
    }
}
