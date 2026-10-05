<?php
namespace local_gestion_actividades\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

class typeb_upload_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $courseid = (int)($this->_customdata['courseid'] ?? 0);

        $mform->addElement('hidden', 'action', 'upload');
        $mform->setType('action', PARAM_ALPHANUMEXT);
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('text', 'activityname', 'Nombre de la formación', ['size' => 60]);
        $mform->setType('activityname', PARAM_TEXT);
        $mform->addRule('activityname', get_string('required'), 'required', null, 'client');

        $mform->addElement('date_selector', 'activitydate', 'Fecha de realización');
        $mform->addRule('activitydate', get_string('required'), 'required', null, 'client');

        // The 'float' element accepts the user's decimal separator (1,5 in
        // Spanish); PARAM_FLOAT would turn "1,5" into 15.
        $mform->addElement('float', 'hours', 'Horas acreditadas', ['size' => 10]);
        $mform->addRule('hours', get_string('required'), 'required', null, 'client');

        $mform->addElement('textarea', 'activitydescription', 'Descripción o justificación de la formación', ['rows' => 5, 'cols' => 60]);
        $mform->setType('activitydescription', PARAM_TEXT);
        $mform->addRule('activitydescription', get_string('required'), 'required', null, 'client');

        $mform->addElement('filepicker', 'evidencefile', 'Certificado acreditativo', null, [
            'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png'],
            'maxbytes' => 20 * 1024 * 1024,
            'maxfiles' => 1,
        ]);
        $mform->addRule('evidencefile', get_string('required'), 'required', null, 'client');
        $mform->addElement('static', 'evidencehelp', '', 'PDF o imagen (JPG/PNG). Tamaño máximo: 20 MB.');

        $this->add_action_buttons(false, 'Enviar para validación');
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $hours = (float)unformat_float((string)($data['hours'] ?? '0'));
        if ($hours <= 0 || $hours > 500) {
            $errors['hours'] = 'Las horas deben ser superiores a 0 y no superar 500.';
        }
        return $errors;
    }
}
