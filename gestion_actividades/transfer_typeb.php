<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/transfer_typeb.php'));
$PAGE->set_title('Traspasar horas Tipo A a Tipo B');
$PAGE->set_heading('Gestión HEE');

function local_ga_transfer_return_button(): string {
    global $DB;
    $courseid = optional_param('courseid', 0, PARAM_INT);
    if ($courseid > 1 && $DB->record_exists('course', ['id' => $courseid])) {
        return html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), 'Volver al curso', ['class' => 'btn btn-outline-secondary']);
    }
    return html_writer::link(new moodle_url('/local/gestion_actividades/portfolio.php'), 'Volver al portafolio', ['class' => 'btn btn-outline-secondary']);
}

$userid = (int)$USER->id;
$message = '';
$messagetype = 'info';

if (optional_param('confirmtransfer', 0, PARAM_BOOL)) {
    require_sesskey();
    $certid = required_param('certificateid', PARAM_INT);
    $reflection = required_param('reflectiontext', PARAM_TEXT);
    $id = manager::transfer_typea_certificate_to_typeb($userid, $certid, $reflection);
    if ($id > 0) {
        redirect(new moodle_url('/local/gestion_actividades/transfer_typeb.php'), 'Traspaso registrado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $message = 'No se ha podido registrar el traspaso. Revisa que tengas horas Tipo A por encima de 32, horas Tipo B pendientes y que el texto obligatorio esté cumplimentado.';
    $messagetype = 'warning';
}

$window = manager::get_user_transfer_window($userid);
$options = manager::list_user_transferable_typea_certificates($userid);
$transfers = manager::list_user_typeb_transfers($userid);

echo $OUTPUT->header();
echo html_writer::div(local_ga_transfer_return_button(), 'mb-3');
echo html_writer::tag('h2', 'Traspasar horas Tipo A a Tipo B');
echo html_writer::tag('p', 'Puedes traspasar talleres Tipo A ya certificados a Tipo B solo cuando superas las 32 horas Tipo A y todavía no has completado las 22 horas Tipo B. El traspaso no duplica horas: el taller completo deja de contar como Tipo A y pasa a contar como Tipo B. No se permiten traspasos parciales y su nota deja de formar parte de la media de Seminarios A.', ['class' => 'text-muted']);

if ($message !== '') {
    echo $OUTPUT->notification($message, $messagetype);
}

$table = new html_table();
$table->attributes['class'] = 'generaltable table-sm';
$table->head = ['Tipo A actual', 'Tipo B actual', 'Exceso A sobre 32', 'Pendiente B hasta 22', 'Máximo traspasable ahora'];
$table->data[] = [
    format_float((float)$window->typeahours, 2, true) . ' h',
    format_float((float)$window->typebhours, 2, true) . ' h',
    format_float((float)$window->excessa, 2, true) . ' h',
    format_float((float)$window->remainingb, 2, true) . ' h',
    format_float((float)$window->maxtransfer, 2, true) . ' h',
];
echo html_writer::table($table);

if (empty($window->cantransfer)) {
    echo $OUTPUT->notification('Ahora mismo no hay horas disponibles para traspasar. Necesitas tener más de 32 horas Tipo A y horas Tipo B pendientes hasta 22.', 'info');
} else if (!$options) {
    echo $OUTPUT->notification('No hay talleres Tipo A completos que quepan en el máximo traspasable, o todos los disponibles ya se han traspasado.', 'info');
} else {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', 'Nuevo traspaso', ['class' => 'h4']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/gestion_actividades/transfer_typeb.php')]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmtransfer', 'value' => 1]);

    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', 'Taller Tipo A que quieres traspasar', ['for' => 'certificateid']);
    $select = html_writer::start_tag('select', ['name' => 'certificateid', 'id' => 'certificateid', 'class' => 'form-select', 'required' => 'required']);
    foreach ($options as $cert) {
        $label = trim((string)$cert->workshopcode . ' - ' . (string)$cert->workshopname);
        $label .= ' · ' . format_float((float)$cert->hours, 2, true) . ' h';
        $select .= html_writer::tag('option', s($label), ['value' => (int)$cert->id]);
    }
    $select .= html_writer::end_tag('select');
    echo $select;
    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', 'Texto obligatorio: explica en qué consistió el taller y cuál es la principal utilidad del mismo.', ['for' => 'reflectiontext']);
    echo html_writer::tag('textarea', '', ['name' => 'reflectiontext', 'id' => 'reflectiontext', 'class' => 'form-control', 'rows' => 6, 'required' => 'required', 'maxlength' => 5000]);
    echo html_writer::end_div();

    echo html_writer::tag('button', 'Confirmar traspaso', ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::tag('h3', 'Traspasos realizados', ['class' => 'h4 mt-4']);
if ($transfers) {
    $t = new html_table();
    $t->attributes['class'] = 'generaltable table-sm';
    $t->head = ['Taller A traspasado', 'Horas traspasadas', 'Texto obligatorio', 'Fecha'];
    foreach ($transfers as $row) {
        $name = trim((string)($row->workshopcode ?? '') . ' - ' . (string)($row->workshopname ?? ''));
        $t->data[] = [
            s($name),
            format_float((float)$row->hours, 2, true) . ' h',
            format_text((string)$row->reflectiontext, FORMAT_PLAIN),
            !empty($row->timecreated) ? userdate((int)$row->timecreated) : '-',
        ];
    }
    echo html_writer::table($t);
} else {
    echo $OUTPUT->notification('Todavía no has realizado ningún traspaso de Tipo A a Tipo B.', 'info');
}

echo $OUTPUT->footer();
