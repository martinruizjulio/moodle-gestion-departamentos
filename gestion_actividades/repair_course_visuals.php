<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\grade_manager;

require_login();
require_sesskey();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$summary = manager::ensure_all_workshop_course_visuals($courseid);
$selfassessmentresults = grade_manager::repair_configured_selfassessment_availability($courseid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/repair_course_visuals.php'));
$PAGE->set_title(get_string('repaircoursevisuals', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('repaircoursevisuals', 'local_gestion_actividades'));

echo $OUTPUT->notification(get_string('coursevisualsrepaired_detailed', 'local_gestion_actividades', $summary), $summary->failed ? 'warning' : 'success');

if ($selfassessmentresults) {
    foreach ($selfassessmentresults as $cid => $ok) {
        $summary->messages[] = $ok
            ? 'Curso ID ' . (int)$cid . ': sección de Autoevaluación protegida y oculta hasta 54 horas.'
            : 'Curso ID ' . (int)$cid . ': no se pudo verificar la restricción de la sección de Autoevaluación.';
    }
}

if (!empty($summary->messages)) {
    echo html_writer::start_tag('ul');
    foreach ($summary->messages as $message) {
        echo html_writer::tag('li', s($message));
    }
    echo html_writer::end_tag('ul');
}

echo html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php'), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);

echo $OUTPUT->footer();
