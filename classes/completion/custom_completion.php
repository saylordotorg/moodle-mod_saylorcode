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

namespace mod_saylorcode\completion;

use core_completion\activity_custom_completion;

/**
 * Evaluates this activity's own completion rules.
 *
 * This class was missing, and its absence was silent. The activity declares
 * FEATURE_COMPLETION_HAS_RULES and offers two rules on its form, so a teacher
 * could tick "student must pass the tests", save it, and see it stored. Moodle
 * resolves the rules through mod_{name}\completion\custom_completion, and
 * get_cm_completion_class() simply returns null when that class does not exist,
 * at which point completion_info skips the custom rules block without comment.
 *
 * The result: both rules were configurable and neither did anything. A student
 * who passed every test was never marked complete, and nothing anywhere said
 * why.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Whether one rule is met for this user.
     *
     * @param string $rule The rule name.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $instance = $DB->get_record('saylorcode', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // A guided lesson is judged on the lesson, not on one step of it. Every
        // step submission writes the attempt's score, so the best score across
        // attempts is the best score on whichever single step went well: pass
        // the first of ten and it reads as one, and the whole activity would
        // complete with nine steps never opened.
        if (($instance->activitymode ?? '') === 'guided') {
            return $this->guided_state($rule, $instance);
        }

        // The best score across the user's attempts, as a fraction. Null means
        // they have not submitted, which is never complete: a rule about how
        // well they did cannot be satisfied by not having done it.
        $best = $DB->get_field_sql(
            "SELECT MAX(score)
               FROM {saylorcode_attempts}
              WHERE saylorcodeid = :saylorcodeid
                AND userid = :userid
                AND score IS NOT NULL",
            ['saylorcodeid' => $instance->id, 'userid' => $this->userid]
        );

        if ($best === null || $best === false) {
            return COMPLETION_INCOMPLETE;
        }

        $fraction = (float) $best;

        if ($rule === 'completionpasstests') {
            // Everything that counts, not most of it. The column is
            // number(10,5), so a genuine full pass is stored as exactly
            // 1.00000 and no tolerance is needed. A tolerance is actively
            // wrong here: with a case of weight 9999 passing and one of weight
            // 1 failing, the score is 0.99990, which any nearly-one comparison
            // would accept while a required case had failed.
            return $fraction >= 1.0 ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }

        // The minimum score rule, whose setting is held as a percentage.
        $required = (int) ($instance->completionminscore ?? 0);

        if ($required <= 0) {
            // Not configured. Returning complete would let an unset rule mark
            // the activity done for anyone who submitted anything at all.
            return COMPLETION_INCOMPLETE;
        }

        return ($fraction * 100) >= $required ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Judge a guided lesson on the lesson as a whole.
     *
     * Step progress lives in saylorcode_stepattempts, and step_manager already
     * decides what a completed step is. This defers to it rather than inventing
     * a second definition that could drift from the one students see on the
     * progress bar.
     *
     * @param string $rule The rule being judged.
     * @param \stdClass $instance The activity.
     * @return int
     */
    protected function guided_state(string $rule, \stdClass $instance): int {
        global $DB;

        $attempts = $DB->get_fieldset_select(
            'saylorcode_attempts',
            'id',
            'saylorcodeid = ? AND userid = ?',
            [$instance->id, $this->userid]
        );

        if (empty($attempts)) {
            return COMPLETION_INCOMPLETE;
        }

        $manager = new \mod_saylorcode\local\step_manager($instance);

        // The best any single attempt reached. A student with more than one
        // attempt should not be punished for the weaker one.
        $bestcomplete = 0;
        $total = 0;

        foreach ($attempts as $attemptid) {
            $progress = $manager->get_progress((int) $attemptid);
            $total = max($total, (int) $progress['total']);
            $bestcomplete = max($bestcomplete, (int) $progress['complete']);
        }

        // A lesson with no steps has nothing to finish. Reporting it complete
        // would mark every guided activity done the moment it was created.
        if ($total === 0) {
            return COMPLETION_INCOMPLETE;
        }

        if ($rule === 'completionpasstests') {
            return $bestcomplete >= $total ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }

        $required = (int) ($instance->completionminscore ?? 0);

        if ($required <= 0) {
            return COMPLETION_INCOMPLETE;
        }

        return (($bestcomplete / $total) * 100) >= $required ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The rules this activity defines.
     *
     * Must agree with saylorcode_get_completion_rule_descriptions() in lib.php
     * and with what mod_form offers, or a rule becomes unreachable again.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionpasstests',
            'completionminscore',
        ];
    }

    /**
     * How each rule is described to a student and on the report.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $required = (int) ($this->cm->customdata['customcompletionrules']['completionminscore'] ?? 0);

        return [
            'completionpasstests' => get_string('completiondetail:passtests', 'mod_saylorcode'),
            'completionminscore' => get_string('completiondetail:minscore', 'mod_saylorcode', $required),
        ];
    }

    /**
     * The order the rules are shown in.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionpasstests',
            'completionminscore',
            'completionusegrade',
        ];
    }
}
