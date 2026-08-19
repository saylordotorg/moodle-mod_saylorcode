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
 * Progressive hints, and the reference solution behind them.
 *
 * Hints are given one at a time and in the author's order, because the point of
 * a hint is to unstick a student with the least help that works. Handing over
 * all of them at once, or the solution first, answers the exercise instead of
 * teaching it.
 *
 * What a student has taken is recorded rather than hidden. A teacher reading
 * the report needs to know that a passing score came after four hints, and a
 * student is entitled to know the record exists.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hint_manager {
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
     * The hints an author has written, in order.
     *
     * @return array Each with a text key.
     */
    public function get_hints(): array {
        $decoded = content::for_instance($this->instance)->get_hints();

        if (!$decoded) {
            $decoded = json_decode((string) ($this->instance->hints ?? ''), true);
        }

        if (!is_array($decoded)) {
            return [];
        }

        $hints = [];
        foreach ($decoded as $hint) {
            $text = trim((string) ($hint['text'] ?? ''));
            if ($text !== '') {
                $hints[] = ['text' => $text];
            }
        }

        return $hints;
    }

    /**
     * Whether this activity offers hints at all.
     *
     * Both conditions matter: an author can turn hints off, and an author who
     * left them on without writing any has nothing to give.
     *
     * @return bool
     */
    public function has_hints(): bool {
        return !empty($this->instance->allowhints) && $this->get_hints() !== [];
    }

    /**
     * Whether the reference solution may be shown.
     *
     * @return bool
     */
    public function allows_solution(): bool {
        return !empty($this->instance->allowsolution) && trim($this->get_solution()) !== '';
    }

    /**
     * Give the student the next hint they have not seen.
     *
     * @param stdClass $attempt The attempt.
     * @return array With keys text, number, total and remaining. Text is empty
     *               when there is nothing left to give.
     */
    public function reveal_next(stdClass $attempt): array {
        global $DB;

        $hints = $this->get_hints();
        $total = count($hints);
        $used = (int) $attempt->hintsused;

        if (!$this->has_hints() || $used >= $total) {
            return ['text' => '', 'number' => $used, 'total' => $total, 'remaining' => 0];
        }

        $attempt->hintsused = $used + 1;
        $attempt->timemodified = time();
        $DB->update_record('saylorcode_attempts', $attempt);

        return [
            'text' => $hints[$used]['text'],
            'number' => $used + 1,
            'total' => $total,
            'remaining' => $total - ($used + 1),
        ];
    }

    /**
     * Every hint the student has already taken.
     *
     * Returned on page load so a student who reloads does not lose what they
     * have already paid for.
     *
     * @param stdClass $attempt The attempt.
     * @return array Each with number and text.
     */
    public function get_revealed(stdClass $attempt): array {
        $hints = $this->get_hints();
        $used = min((int) $attempt->hintsused, count($hints));

        $revealed = [];
        for ($i = 0; $i < $used; $i++) {
            $revealed[] = ['number' => $i + 1, 'text' => $hints[$i]['text']];
        }

        return $revealed;
    }

    /**
     * Show the reference solution, and record that it was seen.
     *
     * @param stdClass $attempt The attempt.
     * @return string The solution, or an empty string when it is not on offer.
     */
    public function reveal_solution(stdClass $attempt): string {
        global $DB;

        if (!$this->allows_solution()) {
            return '';
        }

        if (empty($attempt->solutionviewed)) {
            $attempt->solutionviewed = 1;
            $attempt->timemodified = time();
            $DB->update_record('saylorcode_attempts', $attempt);
        }

        return $this->get_solution();
    }

    /**
     * The reference solution, from wherever the exercise resolves.
     *
     * @return string
     */
    protected function get_solution(): string {
        $solution = content::for_instance($this->instance)->get_reference_solution();

        return $solution !== '' ? $solution : (string) ($this->instance->referencesolution ?? '');
    }
}
