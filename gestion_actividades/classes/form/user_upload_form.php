<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Class user_upload_form for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gestion_actividades\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class user_upload_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'csvfile', get_string('userscsvfile', 'local_gestion_actividades'), null, [
            'accepted_types' => ['.csv', '.txt'],
            'maxbytes' => 20 * 1024 * 1024,
        ]);
        $mform->addHelpButton('csvfile', 'userscsvfile', 'local_gestion_actividades');
        $mform->addRule('csvfile', null, 'required', null, 'client');

        $mform->addElement('advcheckbox', 'updateexisting', get_string('updateexistingusers', 'local_gestion_actividades'));
        $mform->setDefault('updateexisting', 0);
        $mform->addHelpButton('updateexisting', 'updateexistingusers', 'local_gestion_actividades');

        $this->add_action_buttons(true, get_string('processuserscsv', 'local_gestion_actividades'));
    }
}
