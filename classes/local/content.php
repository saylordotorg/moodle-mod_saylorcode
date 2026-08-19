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

use local_saylorcode\local\library\exercise_resolver;
use local_saylorcode\local\library\resolved_exercise;
use stdClass;

/**
 * Where an activity's exercise content comes from.
 *
 * One place decides this, rather than each of the several classes that need
 * starter code or tests asking separately. They would otherwise be free to
 * disagree, and an activity showing library starter code while grading against
 * its own tests would be very hard to notice and worse than either alone.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content {
    /**
     * The content this activity should be using.
     *
     * Deliberately not memoised. A static cache keyed by activity id outlives
     * the data it describes: between tests the database is reset while the
     * cache is not, so one activity's content was served for another's id. The
     * lookup is a single indexed read and a handful of calls per request, which
     * is not worth a cache that can be wrong.
     *
     * @param stdClass $instance The activity instance.
     * @return resolved_exercise
     */
    public static function for_instance(stdClass $instance): resolved_exercise {
        return (new exercise_resolver())->resolve($instance);
    }

    /**
     * The content a step should be using.
     *
     * A step carrying its own reference resolves on that. One without falls
     * back to the activity, which is what every step written before the library
     * existed does.
     *
     * @param stdClass $instance The activity instance.
     * @param stdClass $step The step.
     * @return resolved_exercise
     */
    public static function for_step(stdClass $instance, stdClass $step): resolved_exercise {
        if (trim((string) ($step->stableid ?? '')) === '') {
            return self::for_instance($instance);
        }

        // A step names an exercise but holds no content of its own, so the
        // activity's fields are what it falls back to when the library cannot
        // answer. Anything else would leave the student with an empty editor.
        $holder = (object) [
            'stableid' => $step->stableid,
            'versionpolicy' => $step->versionpolicy ?? exercise_resolver::POLICY_LATEST,
            'pinnedversion' => $step->pinnedversion ?? 0,
            'entryfilename' => $instance->entryfilename ?? 'Main.java',
            'startercode' => $instance->startercode ?? '',
            'referencesolution' => $instance->referencesolution ?? '',
            'testcases' => $instance->testcases ?? '',
            'hints' => $instance->hints ?? '',
        ];

        return (new exercise_resolver())->resolve($holder);
    }
}
