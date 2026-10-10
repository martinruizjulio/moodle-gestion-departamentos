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
 * Class upload_form for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gestion_actividades\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class upload_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $activity = $this->_customdata['activity'];

        $mform->addElement('hidden', 'id', $activity->id);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', 'local_gestion_actividades'), null, [
            'accepted_types' => ['.csv', '.txt'],
            'maxbytes' => 10485760,
        ]);
        $mform->addHelpButton('csvfile', 'csvfile', 'local_gestion_actividades');
        $mform->addRule('csvfile', null, 'required', null, 'client');

        $mform->addElement('text', 'gradecolumn', get_string('gradecolumn', 'local_gestion_actividades'), ['size' => 20]);
        $mform->setType('gradecolumn', PARAM_ALPHANUMEXT);
        $mform->setDefault('gradecolumn', 'nota');

        $currentyear = (int)date('Y');
        $defaultyear = $currentyear . '/' . ($currentyear + 1);
        $mform->addElement('text', 'academicyear', get_string('academicyear', 'local_gestion_actividades'), ['size' => 12]);
        $mform->setType('academicyear', PARAM_TEXT);
        $mform->setDefault('academicyear', $defaultyear);
        $mform->addHelpButton('academicyear', 'academicyear', 'local_gestion_actividades');

        $mform->addElement('advcheckbox', 'savegradehistory', get_string('savegradehistory', 'local_gestion_actividades'));
        $mform->setDefault('savegradehistory', 1);
        $mform->addHelpButton('savegradehistory', 'savegradehistory', 'local_gestion_actividades');

        $mform->addElement('advcheckbox', 'updategradebook', get_string('updategradebook', 'local_gestion_actividades'));
        $mform->setDefault('updategradebook', 0);
        $mform->addHelpButton('updategradebook', 'updategradebook', 'local_gestion_actividades');

        $mform->addElement('text', 'gradeitemname', get_string('gradeitemname', 'local_gestion_actividades'));
        $mform->setType('gradeitemname', PARAM_TEXT);
        $mform->setDefault('gradeitemname', get_string('defaultgradeitemname', 'local_gestion_actividades'));
        $mform->hideIf('gradeitemname', 'updategradebook', 'notchecked');

        // Missing users are intentionally never created by Gestión HEE. They must
        // already exist through the institutional Moodle provisioning process.
        $mform->addElement('advcheckbox', 'creategroup', get_string('creategroup', 'local_gestion_actividades'));
        $mform->setDefault('creategroup', 1);

        $this->add_action_buttons(true, get_string('processcsv', 'local_gestion_actividades'));
    }
}
