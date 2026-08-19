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
 * The exercise catalogue.
 *
 * One place to see every exercise on the site. Without it an author has to
 * open each course to find out what exists, which is how the same exercise
 * ends up written twice.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_saylorcode\form\catalogue_filter_form;
use mod_saylorcode\output\catalogue_table;

$search = optional_param('search', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$profileid = optional_param('profileid', '', PARAM_ALPHANUMEXT);
$state = optional_param('state', '', PARAM_ALPHA);
$layout = optional_param('layout', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);

require_login();

$context = context_system::instance();

// The catalogue reaches across every course, so it is gated at the site level
// rather than on any one course a viewer happens to be able to edit.
require_capability('local/saylorcode:viewlibrary', $context);

$filters = [
    'search' => $search,
    'courseid' => $courseid,
    'profileid' => $profileid,
    'state' => $state,
    'layout' => $layout,
];

$baseurl = new moodle_url('/mod/saylorcode/library.php', array_filter($filters, static function ($value): bool {
    return $value !== '' && $value !== 0;
}));

$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('catalogue', 'mod_saylorcode'));
$PAGE->set_heading(get_string('catalogue', 'mod_saylorcode'));

$table = new catalogue_table('saylorcode-catalogue', $baseurl, $filters);
$table->is_downloading($download, 'saylorcode-exercises', get_string('catalogue', 'mod_saylorcode'));

if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('catalogue', 'mod_saylorcode'));
    echo html_writer::tag('p', get_string('catalogueintro', 'mod_saylorcode'), ['class' => 'text-muted']);

    $form = new catalogue_filter_form($baseurl, null, 'get');
    $form->set_data($filters);
    $form->display();
}

$table->out(50, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
