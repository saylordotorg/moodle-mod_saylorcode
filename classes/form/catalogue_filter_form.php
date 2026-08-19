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

namespace mod_saylorcode\form;

use local_saylorcode\local\runtime\profile_manager;
use mod_saylorcode\local\catalogue;
use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * The filter bar above the exercise catalogue.
 *
 * Submitted by GET so a filtered catalogue can be linked to and bookmarked,
 * which is how an author actually shares "these are the ones still missing
 * tests" with a colleague.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogue_filter_form extends moodleform {
    /**
     * Build the filter bar.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'text',
            'search',
            get_string('cataloguesearch', 'mod_saylorcode'),
            ['size' => 30, 'placeholder' => get_string('cataloguesearchplaceholder', 'mod_saylorcode')]
        );
        $mform->setType('search', PARAM_TEXT);

        $courses = [0 => get_string('catalogueallcourses', 'mod_saylorcode')] + catalogue::get_courses_with_exercises();
        $mform->addElement('select', 'courseid', get_string('cataloguecourse', 'mod_saylorcode'), $courses);
        $mform->setType('courseid', PARAM_INT);

        $profiles = ['' => get_string('catalogueallprofiles', 'mod_saylorcode')] + (new profile_manager())->get_menu();
        $mform->addElement('select', 'profileid', get_string('catalogueprofile', 'mod_saylorcode'), $profiles);
        $mform->setType('profileid', PARAM_ALPHANUMEXT);

        $mform->addElement('select', 'state', get_string('cataloguereadiness', 'mod_saylorcode'), [
            '' => get_string('catalogueanystate', 'mod_saylorcode'),
            'ready' => get_string('cataloguestateready', 'mod_saylorcode'),
            'notests' => get_string('cataloguefilternotests', 'mod_saylorcode'),
            'nosolution' => get_string('cataloguefilternosolution', 'mod_saylorcode'),
        ]);
        $mform->setType('state', PARAM_ALPHA);

        $mform->addElement('select', 'layout', get_string('cataloguelayout', 'mod_saylorcode'), [
            '' => get_string('catalogueanylayout', 'mod_saylorcode'),
            'split' => get_string('layoutsplit', 'mod_saylorcode'),
            'drawer' => get_string('layoutdrawer', 'mod_saylorcode'),
            'tabs' => get_string('layouttabs', 'mod_saylorcode'),
        ]);
        $mform->setType('layout', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('cataloguefilter', 'mod_saylorcode'));
    }
}
