<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\portfolio_pdf;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

if (data_submitted() && confirm_sesskey()) {
    $html = required_param('coverhtml', PARAM_RAW);
    portfolio_pdf::save_cover_template($html);
    redirect(new moodle_url('/local/gestion_actividades/portfolio_cover_template.php'), 'Portada guardada correctamente.');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/portfolio_cover_template.php'));
$PAGE->set_title('Portada del portafolio');
$PAGE->set_heading('Portada del portafolio');

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading('Portada editable del portafolio');
echo html_writer::tag('p', 'Esta portada se usará en todos los PDF de portafolio y se imprimirá sobre la misma plantilla visual UCV usada para los certificados. Puedes editar el texto y usar las variables automáticas.', ['class' => 'alert alert-info']);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('textarea', s(portfolio_pdf::get_cover_template()), ['name' => 'coverhtml', 'class' => 'form-control', 'rows' => 14]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Guardar portada', 'class' => 'btn btn-primary mt-3']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_admin.php'), 'Volver al portafolio gestor', ['class' => 'btn btn-secondary mt-3']);
echo html_writer::end_tag('form');
echo html_writer::tag('h3', 'Variables disponibles', ['class' => 'mt-4']);
echo html_writer::tag('pre', "{alumno}\n{curso}\n{horas_tipo_a}\n{horas_tipo_b}\n{horas_total}\n{fecha_emision}");
echo $OUTPUT->footer();
