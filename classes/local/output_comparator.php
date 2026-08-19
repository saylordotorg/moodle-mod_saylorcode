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

/**
 * Decides whether produced output matches what a test case expects.
 *
 * Extracted so the student path and the author's Validate button cannot drift
 * apart. If they compared differently, an author could prove their tests pass
 * and a student could still fail them, which is the worst possible outcome for
 * a tool whose whole purpose is telling a learner where they stand.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class output_comparator {
    /**
     * Whether produced output satisfies a case.
     *
     * Normalised rather than exact: trailing whitespace on a line, and trailing
     * blank lines, are not what an introductory exercise is teaching, and
     * failing a student for them teaches the wrong lesson (specification
     * section 10.5).
     *
     * @param string $actual Output the program produced.
     * @param string $expected Output the case expects.
     * @return bool
     */
    public static function matches(string $actual, string $expected): bool {
        return self::normalise($actual) === self::normalise($expected);
    }

    /**
     * Reduce output to the form used for comparison.
     *
     * @param string $text The text to normalise.
     * @return string
     */
    public static function normalise(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = array_map(static function (string $line): string {
            return rtrim($line);
        }, explode("\n", $text));

        return rtrim(implode("\n", $lines), "\n");
    }
}
