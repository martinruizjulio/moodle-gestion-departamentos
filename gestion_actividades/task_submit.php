<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$id = required_param('id', PARAM_INT);
require_login();

$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$context = context_course::instance((int)$course->id);
require_login($course);

if (!manager::user_can_access_workshop_resources((int)$edition->id, (int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:view', 'nopermissions', '');
}

$submission = manager::get_internal_task_submission((int)$edition->id, (int)$USER->id);

if (data_submitted() && confirm_sesskey()) {
    $fileitemid = (int)($submission->fileitemid ?? 0);
    $fileitemid = manager::store_named_upload($context->id, $fileitemid, 'submissionfile', 'tasksubmission');
    if ($fileitemid > 0) {
        manager::save_internal_task_submission((int)$edition->id, (int)$USER->id, $fileitemid);
    }
    redirect(new moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $workshop->id]), 'Entrega registrada.');
}

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/task_submit.php', ['id' => $id]));
$PAGE->set_title('Entregar tarea');
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $workshop->id]), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al taller', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');
echo $OUTPUT->heading('Entregar tarea: ' . format_string($workshop->name));

if (!empty($edition->taskdescription)) {
    echo html_writer::tag('div', format_text($edition->taskdescription, FORMAT_PLAIN), ['class' => 'card card-body mb-3']);
}
$taskfile = manager::get_filearea_url($context, 'taskfile', (int)($edition->taskfileitemid ?? 0));
if ($taskfile !== '') {
    echo html_writer::tag('p', html_writer::link($taskfile, 'Descargar archivo de la tarea', ['class' => 'btn btn-secondary', 'target' => '_blank']));
}
if (!empty($edition->taskurl)) {
    echo html_writer::tag('p', html_writer::link($edition->taskurl, 'Abrir enlace de la tarea', ['class' => 'btn btn-secondary', 'target' => '_blank']));
}
if (!empty($edition->taskduedate)) {
    echo html_writer::tag('p', 'Fecha límite: ' . userdate((int)$edition->taskduedate), ['class' => 'text-muted']);
}

$current = $submission ? manager::get_filearea_url($context, 'tasksubmission', (int)$submission->fileitemid) : '';
if ($current !== '') {
    echo html_writer::div('Estado: entregado', 'alert alert-success');
    echo html_writer::tag('p', html_writer::link($current, 'Ver archivo entregado', ['target' => '_blank']));
}

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::label('Subir entrega (Word, Excel o PDF)', 'submissionfile');
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'submissionfile', 'id' => 'submissionfile', 'class' => 'form-control mb-3', 'accept' => '.doc,.docx,.xls,.xlsx,.pdf', 'required' => 'required']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Guardar entrega', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
