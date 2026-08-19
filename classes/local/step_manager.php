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
 * Progression through a guided lesson.
 *
 * A guided lesson is a sequence of small steps, each of which the student must
 * satisfy before the next opens (specification section 8.1). This class owns
 * what "satisfied" means, which step a student is on, and what code they see
 * when they arrive at one.
 *
 * Step progress is recorded separately from activity progress, because a
 * student who has completed four of six steps has done something real and a
 * single activity-level flag cannot say so.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_manager {
    /** @var string Opening the step is enough. */
    public const RULE_VIEW = 'view';

    /** @var string The student must have run their code at least once. */
    public const RULE_RUN = 'run';

    /** @var string The step's tests must pass. */
    public const RULE_PASSTESTS = 'passtests';

    /** @var string The student must submit, whatever the outcome. */
    public const RULE_SUBMIT = 'submit';

    /** @var string Not yet reached. */
    public const STATUS_NOTSTARTED = 'notstarted';

    /** @var string Reached, not yet satisfied. */
    public const STATUS_INPROGRESS = 'inprogress';

    /** @var string Satisfied. */
    public const STATUS_COMPLETE = 'complete';

    /** @var stdClass The activity instance. */
    protected stdClass $instance;

    /**
     * Build a manager for one activity.
     *
     * @param stdClass $instance The activity instance.
     */
    public function __construct(stdClass $instance) {
        $this->instance = $instance;
    }

    /**
     * The steps of this activity, in author order.
     *
     * @return stdClass[] Keyed by step id.
     */
    public function get_steps(): array {
        global $DB;

        return $DB->get_records('saylorcode_steps', ['saylorcodeid' => $this->instance->id], 'sortorder ASC, id ASC');
    }

    /**
     * Whether this activity is a guided lesson at all.
     *
     * Decided by whether steps exist rather than by the activity mode, so an
     * author who has chosen the mode but written no steps yet gets the ordinary
     * workspace instead of an empty sequencer.
     *
     * @return bool
     */
    public function is_guided(): bool {
        global $DB;

        return $DB->record_exists('saylorcode_steps', ['saylorcodeid' => $this->instance->id]);
    }

    /**
     * The step attempt row for one step, created if this is the first visit.
     *
     * @param int $attemptid The attempt.
     * @param int $stepid The step.
     * @return stdClass
     */
    public function get_or_create_step_attempt(int $attemptid, int $stepid): stdClass {
        global $DB;

        $existing = $DB->get_record('saylorcode_stepattempts', ['attemptid' => $attemptid, 'stepid' => $stepid]);

        if ($existing) {
            return $this->apply_arrival($existing, $stepid);
        }

        $record = (object) [
            'attemptid' => $attemptid,
            'stepid' => $stepid,
            'status' => self::STATUS_INPROGRESS,
            'runcount' => 0,
            'checkcount' => 0,
            'submitcount' => 0,
            'hintsused' => 0,
            'solutionviewed' => 0,
            'timemodified' => time(),
        ];

        $record->id = $DB->insert_record('saylorcode_stepattempts', $record);

        // Re-read rather than trusting the object we built. Defaults declared
        // in install.xml are applied by the database, and code downstream that
        // reads a column we did not set would otherwise see null.
        $record = $DB->get_record('saylorcode_stepattempts', ['id' => $record->id], '*', MUST_EXIST);

        return $this->apply_arrival($record, $stepid);
    }

    /**
     * Complete a step that its own rule satisfies on arrival.
     *
     * An instruction step asks the student to read something, so opening it is
     * the whole requirement. Without this the step is stored as in progress and
     * nothing ever moves it on: record_action() only fires for run, check and
     * submit, and a lesson that opens with an instruction step could not be
     * finished at all.
     *
     * Applied on every read rather than only on creation, so it is idempotent
     * and no caller can skip it by fetching an existing row.
     *
     * @param stdClass $stepattempt The step attempt.
     * @param int $stepid The step it belongs to.
     * @return stdClass The step attempt, completed where the rule allows.
     */
    protected function apply_arrival(stdClass $stepattempt, int $stepid): stdClass {
        global $DB;

        if ($stepattempt->status === self::STATUS_COMPLETE) {
            return $stepattempt;
        }

        $steps = $this->get_steps();
        $step = $steps[$stepid] ?? null;

        if ($step === null || !$this->rule_satisfied($step, $stepattempt)) {
            return $stepattempt;
        }

        $stepattempt->status = self::STATUS_COMPLETE;
        $stepattempt->timecompleted = time();
        $stepattempt->timemodified = time();
        $DB->update_record('saylorcode_stepattempts', $stepattempt);

        return $stepattempt;
    }

    /**
     * Every step attempt for an attempt, keyed by step id.
     *
     * @param int $attemptid The attempt.
     * @return stdClass[]
     */
    public function get_step_attempts(int $attemptid): array {
        global $DB;

        $records = $DB->get_records('saylorcode_stepattempts', ['attemptid' => $attemptid]);

        $bystep = [];
        foreach ($records as $record) {
            $bystep[(int) $record->stepid] = $record;
        }

        return $bystep;
    }

    /**
     * Whether a step's completion rule is now satisfied.
     *
     * The outcome of the action is only consulted for the rule that asks about
     * it. A step whose rule is "run" is satisfied by running failing code,
     * because its point was to get the student to press the button.
     *
     * @param stdClass $step The step.
     * @param stdClass $stepattempt The student's progress on it.
     * @return bool
     */
    public function rule_satisfied(stdClass $step, stdClass $stepattempt): bool {
        switch ($step->completionrule) {
            case self::RULE_VIEW:
                return true;

            case self::RULE_RUN:
                return $stepattempt->runcount > 0 || $stepattempt->checkcount > 0
                    || $stepattempt->submitcount > 0;

            case self::RULE_SUBMIT:
                return $stepattempt->submitcount > 0;

            case self::RULE_PASSTESTS:
            default:
                // A step with no tests cannot be passed by testing, so it falls
                // back to having run something. Otherwise an author who forgot
                // to write tests would lock every student out of the lesson.
                if (!$this->step_has_tests($step)) {
                    return $stepattempt->runcount > 0 || $stepattempt->checkcount > 0
                        || $stepattempt->submitcount > 0;
                }

                return $stepattempt->timefirstpassed !== null && (int) $stepattempt->timefirstpassed > 0;
        }
    }

    /**
     * Record the result of an action against a step.
     *
     * @param stdClass $step The step.
     * @param stdClass $stepattempt The step attempt to update.
     * @param string $action run, check or submit.
     * @param bool|null $passed Whether every test passed, where that is known.
     * @param float|null $score The fraction scored, where that is known.
     * @return stdClass The updated step attempt.
     */
    public function record_action(
        stdClass $step,
        stdClass $stepattempt,
        string $action,
        ?bool $passed = null,
        ?float $score = null
    ): stdClass {
        global $DB;

        $field = [
            'run' => 'runcount',
            'check' => 'checkcount',
            'submit' => 'submitcount',
        ][$action] ?? null;

        if ($field !== null) {
            $stepattempt->{$field} = (int) $stepattempt->{$field} + 1;
        }

        if ($score !== null) {
            $stepattempt->latestscore = $score;
            if ($stepattempt->bestscore === null || $score > (float) $stepattempt->bestscore) {
                $stepattempt->bestscore = $score;
            }
        }

        // The first pass is kept rather than the most recent, because it is the
        // moment the student earned the step. Later edits that break their own
        // work must not take a completed step away from them.
        if ($passed === true && empty($stepattempt->timefirstpassed)) {
            $stepattempt->timefirstpassed = time();
        }

        if ($stepattempt->status !== self::STATUS_COMPLETE && $this->rule_satisfied($step, $stepattempt)) {
            $stepattempt->status = self::STATUS_COMPLETE;
            $stepattempt->timecompleted = time();
        }

        $stepattempt->timemodified = time();
        $DB->update_record('saylorcode_stepattempts', $stepattempt);

        return $stepattempt;
    }

    /**
     * The step the student should be shown.
     *
     * The first step that is not complete, or the last step once they all are,
     * so a finished lesson opens on its summary rather than bouncing back to
     * the beginning.
     *
     * @param int $attemptid The attempt.
     * @return stdClass|null Null when the activity has no steps.
     */
    public function get_current_step(int $attemptid): ?stdClass {
        $steps = $this->get_steps();

        if (!$steps) {
            return null;
        }

        $progress = $this->get_step_attempts($attemptid);

        foreach ($steps as $step) {
            $stepattempt = $progress[(int) $step->id] ?? null;
            if ($stepattempt === null || $stepattempt->status !== self::STATUS_COMPLETE) {
                return $step;
            }
        }

        return end($steps) ?: null;
    }

    /**
     * Whether the student may open a given step.
     *
     * Steps unlock in order: a student may open any step up to and including
     * the first incomplete one. Going back is allowed only where the author
     * permits revisiting, which is what allowrevisit is for.
     *
     * @param int $attemptid The attempt.
     * @param int $stepid The step they want to open.
     * @return bool
     */
    public function can_open_step(int $attemptid, int $stepid): bool {
        $steps = $this->get_steps();

        if (!isset($steps[$stepid])) {
            return false;
        }

        $current = $this->get_current_step($attemptid);

        if ($current === null) {
            return false;
        }

        // Position in author order, not sortorder, which authors are free to
        // leave with ties.
        $order = array_keys($steps);
        $wantedat = array_search($stepid, $order, true);
        $currentat = array_search((int) $current->id, $order, true);

        if ($wantedat > $currentat) {
            // Beyond the furthest step reached. This is the only thing the
            // sequence genuinely locks.
            return false;
        }

        if ($wantedat === $currentat) {
            return true;
        }

        // An earlier, completed step: the author decides whether the student
        // may go back to it.
        return !empty($steps[$stepid]->allowrevisit);
    }

    /**
     * The code a student should see on arriving at a step.
     *
     * Carrying forward is the default because a guided lesson builds one
     * program up in small changes, and throwing the student's work away between
     * steps would defeat that. A step that opens a new idea can turn it off and
     * supply its own starting point.
     *
     * @param stdClass $step The step being opened.
     * @param array $carried The files from the previous step.
     * @return array Relative path => contents.
     */
    public function starting_files_for(stdClass $step, array $carried): array {
        if (!empty($step->carryforward) && $carried) {
            return $carried;
        }

        return [
            $this->instance->entryfilename => (string) ($this->instance->startercode ?? ''),
        ];
    }

    /**
     * How far through the lesson the student is.
     *
     * @param int $attemptid The attempt.
     * @return array With keys total, complete and percent.
     */
    public function get_progress(int $attemptid): array {
        $steps = $this->get_steps();
        $progress = $this->get_step_attempts($attemptid);

        $complete = 0;
        foreach ($steps as $step) {
            $stepattempt = $progress[(int) $step->id] ?? null;
            if ($stepattempt !== null && $stepattempt->status === self::STATUS_COMPLETE) {
                $complete++;
            }
        }

        $total = count($steps);

        return [
            'total' => $total,
            'complete' => $complete,
            'percent' => $total > 0 ? (int) round($complete / $total * 100) : 0,
        ];
    }

    /**
     * What the student has to do to finish a step.
     *
     * Stated up front rather than discovered by trying, because a student who
     * does not know what is being asked of them cannot tell a finished step
     * from a stuck one. It lives here so the server rendered panel and the
     * payload sent when a step is opened cannot describe the same rule
     * differently.
     *
     * @param stdClass $step The step.
     * @return string
     */
    public static function completion_hint(stdClass $step): string {
        $rules = [
            self::RULE_VIEW => 'stephintview',
            self::RULE_RUN => 'stephintrun',
            self::RULE_SUBMIT => 'stephintsubmit',
            self::RULE_PASSTESTS => 'stephintpasstests',
        ];

        return get_string($rules[$step->completionrule] ?? 'stephintpasstests', 'mod_saylorcode');
    }

    /**
     * Whether a step defines any tests of its own.
     *
     * Steps reference an exercise by stable id. Until the exercise library
     * exists, a checkpoint step borrows the tests of the activity it lives on,
     * which is the only place tests are currently stored.
     *
     * @param stdClass $step The step.
     * @return bool
     */
    protected function step_has_tests(stdClass $step): bool {
        $decoded = json_decode((string) ($this->instance->testcases ?? ''), true);

        return is_array($decoded) && !empty($decoded);
    }
}
