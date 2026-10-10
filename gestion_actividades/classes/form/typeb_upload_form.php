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
 * Class typeb_upload_form for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gestion_actividades\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * External Type B request: the student chooses the taller from the catalogue
 * (name and hours are fixed there), the date and the certificate.
 * customdata: courseid, options (catalogid => label), requestid (editing).
 */
class typeb_upload_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $courseid = (int)($this->_customdata['courseid'] ?? 0);
        $options = (array)($this->_customdata['options'] ?? []);
        $requestid = (int)($this->_customdata['requestid'] ?? 0);

        $mform->addElement('hidden', 'action', $requestid > 0 ? 'update' : 'upload');
        $mform->setType('action', PARAM_ALPHANUMEXT);
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'requestid', $requestid);
        $mform->setType('requestid', PARAM_INT);

        $mform->addElement('select', 'catalogid', 'Taller Tipo B', ['' => '— Elige el taller —'] + $options);
        $mform->setType('catalogid', PARAM_INT);
        $mform->addRule('catalogid', get_string('required'), 'required', null, 'client');
        $mform->addElement('static', 'cataloghelp', '', 'El título y las horas del taller son los del catálogo de Gestión HEE.');

        $mform->addElement('date_selector', 'activitydate', 'Fecha de realización');
        $mform->addRule('activitydate', get_string('required'), 'required', null, 'client');

        $mform->addElement('filepicker', 'evidencefile', 'Certificado acreditativo', null, [
            'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png'],
            'maxbytes' => 20 * 1024 * 1024,
            'maxfiles' => 1,
        ]);
        if ($requestid > 0) {
            $mform->addElement(
                'static',
                'evidencehelp',
                '',
                'PDF o imagen (JPG/PNG), máximo 20 MB. Déjalo vacío para conservar el certificado que ya enviaste.'
            );
        } else {
            $mform->addRule('evidencefile', get_string('required'), 'required', null, 'client');
            $mform->addElement('static', 'evidencehelp', '', 'PDF o imagen (JPG/PNG). Tamaño máximo: 20 MB.');
        }

        if ($requestid > 0) {
            $this->add_action_buttons(true, 'Guardar cambios y reenviar');
        } else {
            $this->add_action_buttons(false, 'Enviar para validación');
        }
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $options = (array)($this->_customdata['options'] ?? []);
        if (empty($data['catalogid']) || !isset($options[(int)$data['catalogid']])) {
            $errors['catalogid'] = 'Elige un taller del desplegable.';
        }
        return $errors;
    }
}
