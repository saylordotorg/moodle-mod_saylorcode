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
 * Version details for the Saylor Code Studio activity.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_saylorcode';

$plugin->version   = 2026100500;

$plugin->requires  = 2024100700; // Moodle 4.5.
$plugin->supported = [405, 405];
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0 (Phase 1 vertical slice)';
$plugin->dependencies = [
    // 2026082506 is where execution_response gained ran_out_of_input(), which
    // describe_outcome() calls on every run. The earlier floor was 2026081904,
    // for the execution gate's get_denial(); this supersedes it. Against
    // anything older this plugin installs happily and then fatals on the first
    // execution, which is worse than refusing to install.
    'local_saylorcode' => 2026100500,
];
