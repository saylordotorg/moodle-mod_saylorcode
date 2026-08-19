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
 * Web service definitions for mod_saylorcode.
 *
 * These are declared ajax => true so the editor can call them from the page,
 * and are deliberately not added to any published service. Nothing here is
 * meant to be reachable by an external token; every function assumes a logged
 * in user in a course context.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'mod_saylorcode_save_code' => [
        'classname' => 'mod_saylorcode\external\save_code',
        'description' => 'Save the code a student is working on.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/saylorcode:attempt',
    ],

    'mod_saylorcode_run_code' => [
        'classname' => 'mod_saylorcode\external\run_code',
        'description' => 'Run, check or submit student code.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/saylorcode:attempt',
    ],

    'mod_saylorcode_reset_code' => [
        'classname' => 'mod_saylorcode\external\reset_code',
        'description' => 'Restore the starter code, keeping a recoverable snapshot.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/saylorcode:attempt',
    ],

    'mod_saylorcode_open_step' => [
        'classname' => 'mod_saylorcode\external\open_step',
        'description' => 'Open a step of a guided lesson and return what the interface needs to show it.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/saylorcode:attempt',
    ],

    'mod_saylorcode_reveal_hint' => [
        'classname' => 'mod_saylorcode\external\reveal_hint',
        'description' => 'Give the student the next hint, or the reference solution where the author allows it.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/saylorcode:attempt',
    ],

    'mod_saylorcode_validate_exercise' => [
        'classname' => 'mod_saylorcode\external\validate_exercise',
        'description' => 'Run an author\'s reference solution against their test cases.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/saylorcode:addinstance',
    ],
];
