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
 * Publish activity-stored exercises into the shared library.
 *
 * Dry run by default: it prints what it would do and changes nothing. Pass
 * --execute to perform the migration. Safe to run more than once, because an
 * exercise already in the library is skipped rather than republished.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use mod_saylorcode\local\library_migrator;

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'execute' => false,
        'course' => null,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    echo "Publish activity-stored Saylor Code Studio exercises into the shared library.

Dry run by default: prints the plan and changes nothing. Safe to re-run;
exercises already in the library are skipped.

Options:
  -h, --help      Show this help.
      --execute   Perform the migration. Without this it is a dry run.
      --course=N  Restrict to activities in course N.

Examples:
  # See what would be migrated, site wide:
  php mod/saylorcode/cli/migrate_to_library.php

  # Migrate one course's exercises:
  php mod/saylorcode/cli/migrate_to_library.php --course=42 --execute
";
    exit(0);
}

$courseid = $options['course'] !== null ? (int) $options['course'] : null;
$migrator = new library_migrator();

$labels = [
    library_migrator::ACTION_CREATE => 'create',
    library_migrator::ACTION_INLIBRARY => 'already in library, skip',
    library_migrator::ACTION_CONFLICT => 'CONFLICT, skip',
    library_migrator::ACTION_EMPTY => 'no content, skip',
];

if (!$options['execute']) {
    $plan = $migrator->plan($courseid);

    if (!$plan) {
        cli_writeln('No activities carry a stable id here, so there is nothing to migrate.');
        exit(0);
    }

    cli_heading('Dry run: this would migrate the following. Re-run with --execute to apply.');

    $creates = 0;
    foreach ($plan as $entry) {
        $line = sprintf('  %-18s  %s', $entry['stableid'], $labels[$entry['action']] ?? $entry['action']);

        if ($entry['action'] === library_migrator::ACTION_CONFLICT) {
            $line .= ' (courses: ' . implode(', ', $entry['courses']) . ')';
        }

        if ($entry['action'] === library_migrator::ACTION_CREATE) {
            $creates++;
        }

        cli_writeln($line);
    }

    cli_writeln('');
    cli_writeln("Would create {$creates} of " . count($plan) . ' exercise(s).');
    exit(0);
}

$report = $migrator->migrate($courseid);

$migrated = 0;
foreach ($report as $entry) {
    if (($entry['outcome'] ?? '') === 'migrated') {
        $migrated++;
        cli_writeln(sprintf('  %-18s  migrated as version %d', $entry['stableid'], $entry['version']));
    } else {
        cli_writeln(sprintf('  %-18s  skipped (%s)', $entry['stableid'], $labels[$entry['action']] ?? $entry['action']));
    }
}

cli_writeln('');
cli_writeln("Migrated {$migrated} exercise(s) into the library.");
exit(0);
