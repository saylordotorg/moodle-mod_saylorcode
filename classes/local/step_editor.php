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

use stdClass;

/**
 * Creating, changing and ordering the steps of a guided lesson.
 *
 * Kept apart from step_manager, which answers what a student may do. Authoring
 * and progression share the same table and nothing else: mixing them would put
 * write access to the lesson behind a class every student request loads.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_editor {
    /** @var stdClass The activity the steps belong to. */
    protected stdClass $instance;

    /**
     * Build an editor for one activity.
     *
     * @param stdClass $instance The activity instance.
     */
    public function __construct(stdClass $instance) {
        $this->instance = $instance;
    }

    /**
     * The steps in author order.
     *
     * @return stdClass[] Keyed by id.
     */
    public function get_steps(): array {
        global $DB;

        return $DB->get_records('saylorcode_steps', ['saylorcodeid' => $this->instance->id], 'sortorder ASC, id ASC');
    }

    /**
     * One step, or null when it does not belong to this activity.
     *
     * Ownership is checked here rather than by the caller, so a step id from a
     * URL cannot reach a lesson in another course.
     *
     * @param int $stepid The step.
     * @return stdClass|null
     */
    public function get_step(int $stepid): ?stdClass {
        global $DB;

        $step = $DB->get_record('saylorcode_steps', [
            'id' => $stepid,
            'saylorcodeid' => $this->instance->id,
        ]);

        return $step ?: null;
    }

    /**
     * Add a step to the end of the lesson.
     *
     * @param stdClass $data Step fields.
     * @return int The new step id.
     */
    public function create(stdClass $data): int {
        global $DB;

        $step = $this->shape($data);
        $step->saylorcodeid = $this->instance->id;
        $step->sortorder = $this->next_sortorder();
        $step->timecreated = time();
        $step->timemodified = time();

        return $DB->insert_record('saylorcode_steps', $step);
    }

    /**
     * Change a step.
     *
     * @param int $stepid The step to change.
     * @param stdClass $data Step fields.
     * @return bool Whether the step belonged to this activity and was changed.
     */
    public function update(int $stepid, stdClass $data): bool {
        global $DB;

        $existing = $this->get_step($stepid);

        if ($existing === null) {
            return false;
        }

        $step = $this->shape($data);
        $step->id = $stepid;
        $step->timemodified = time();

        // The form does not offer the version fields, so a submission carries
        // no opinion about them. Taking the default would quietly unpin a
        // pinned step on a title-only edit, changing which exercise students
        // see without anyone asking for it.
        if (!isset($data->versionpolicy)) {
            $step->versionpolicy = $existing->versionpolicy;
            $step->pinnedversion = $existing->pinnedversion;
        }

        $DB->update_record('saylorcode_steps', $step);

        return true;
    }

    /**
     * Remove a step and everything recorded against it.
     *
     * A student's progress on a deleted step has nothing left to describe, so
     * it goes too. Snapshots keep their code but lose the step reference, which
     * leaves the student's work intact and merely unattributed.
     *
     * @param int $stepid The step to remove.
     * @return bool Whether the step belonged to this activity and was removed.
     */
    public function delete(int $stepid): bool {
        global $DB;

        if ($this->get_step($stepid) === null) {
            return false;
        }

        $DB->delete_records('saylorcode_stepattempts', ['stepid' => $stepid]);
        $DB->set_field('saylorcode_snapshots', 'stepid', null, ['stepid' => $stepid]);
        $DB->delete_records('saylorcode_steps', ['id' => $stepid]);

        $this->renumber();

        return true;
    }

    /**
     * Move a step one place earlier or later.
     *
     * @param int $stepid The step to move.
     * @param int $direction -1 to move earlier, 1 to move later.
     * @return bool Whether the move happened.
     */
    public function move(int $stepid, int $direction): bool {
        global $DB;

        $steps = array_values($this->get_steps());
        $at = null;

        foreach ($steps as $index => $step) {
            if ((int) $step->id === $stepid) {
                $at = $index;
                break;
            }
        }

        $to = $at === null ? null : $at + ($direction < 0 ? -1 : 1);

        if ($at === null || $to === null || $to < 0 || $to >= count($steps)) {
            return false;
        }

        // Swap the two positions, then renumber, so ties or gaps left by an
        // earlier edit cannot make the order ambiguous.
        [$steps[$at], $steps[$to]] = [$steps[$to], $steps[$at]];

        $order = 0;
        foreach ($steps as $step) {
            $order++;
            $DB->set_field('saylorcode_steps', 'sortorder', $order, ['id' => $step->id]);
        }

        return true;
    }

    /**
     * Reduce form data to the columns a step has.
     *
     * @param stdClass $data Submitted data.
     * @return stdClass
     */
    protected function shape(stdClass $data): stdClass {
        $instructions = $data->instructions ?? '';
        $format = FORMAT_HTML;

        // The editor element submits an array; a plain field submits a string.
        if (is_array($instructions)) {
            $format = (int) ($instructions['format'] ?? FORMAT_HTML);
            $instructions = (string) ($instructions['text'] ?? '');
        }

        return (object) [
            'sectiontitle' => \core_text::substr(trim((string) ($data->sectiontitle ?? '')), 0, 255),
            'steptype' => (string) ($data->steptype ?? 'checkpoint'),
            'title' => \core_text::substr(trim((string) ($data->title ?? '')), 0, 255),
            'instructions' => $instructions,
            'instructionsformat' => $format,
            'stableid' => trim((string) ($data->stableid ?? '')) ?: null,
            'versionpolicy' => (string) ($data->versionpolicy ?? 'latest'),
            // Without this a pinned step is stored with no version, resolves as
            // a broken pin and quietly falls back to the activity's content,
            // which is the failure the pin exists to prevent.
            'pinnedversion' => (int) ($data->pinnedversion ?? 0) ?: null,
            'carryforward' => empty($data->carryforward) ? 0 : 1,
            'completionrule' => (string) ($data->completionrule ?? step_manager::RULE_PASSTESTS),
            'allowrevisit' => empty($data->allowrevisit) ? 0 : 1,
            'points' => (float) ($data->points ?? 0),
        ];
    }

    /**
     * The position a new step goes into.
     *
     * @return int
     */
    protected function next_sortorder(): int {
        global $DB;

        $highest = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {saylorcode_steps} WHERE saylorcodeid = :id',
            ['id' => $this->instance->id]
        );

        return (int) $highest + 1;
    }

    /**
     * Close the gaps in the ordering.
     */
    protected function renumber(): void {
        global $DB;

        $order = 0;
        foreach ($this->get_steps() as $step) {
            $order++;
            if ((int) $step->sortorder !== $order) {
                $DB->set_field('saylorcode_steps', 'sortorder', $order, ['id' => $step->id]);
            }
        }
    }
}
