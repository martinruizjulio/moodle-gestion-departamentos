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
 * Class edit_activity_form for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gestion_actividades\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class edit_activity_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('activityname', 'local_gestion_actividades'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('text', 'activitykey', get_string('activitykey', 'local_gestion_actividades'), ['size' => 40]);
        $mform->setType('activitykey', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('activitykey', 'activitykey', 'local_gestion_actividades');
        $mform->addRule('activitykey', null, 'required', null, 'client');

        $mform->addElement('text', 'courseid', get_string('courseid', 'local_gestion_actividades'), ['size' => 10]);
        $mform->setType('courseid', PARAM_INT);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $mform->addElement('text', 'teacherid', get_string('teacherid', 'local_gestion_actividades'), ['size' => 10]);
        $mform->setType('teacherid', PARAM_INT);
        $mform->addHelpButton('teacherid', 'teacherid', 'local_gestion_actividades');

        $mform->addElement('text', 'places', get_string('places', 'local_gestion_actividades'), ['size' => 10]);
        $mform->setType('places', PARAM_INT);
        $mform->setDefault('places', 20);
        $mform->addRule('places', null, 'required', null, 'client');

        $mform->addElement('select', 'idfield', get_string('idfield', 'local_gestion_actividades'), [
            'email' => 'email',
            'username' => 'username',
            'idnumber' => 'idnumber',
        ]);
        $mform->setDefault('idfield', 'email');

        $mform->addElement('textarea', 'description', get_string('description', 'local_gestion_actividades'), 'wrap="virtual" rows="5" cols="60"');
        $mform->setType('description', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('saveactivity', 'local_gestion_actividades'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (!empty($data['courseid']) && !$DB->record_exists('course', ['id' => $data['courseid']])) {
            $errors['courseid'] = 'No existe un curso Moodle con ese ID.';
        }
        if (!empty($data['teacherid']) && !$DB->record_exists('user', ['id' => $data['teacherid'], 'deleted' => 0])) {
            $errors['teacherid'] = 'No existe un usuario Moodle con ese ID.';
        }
        if (!empty($data['places']) && (int)$data['places'] < 1) {
            $errors['places'] = 'Debe indicar al menos una plaza.';
        }
        return $errors;
    }
}
