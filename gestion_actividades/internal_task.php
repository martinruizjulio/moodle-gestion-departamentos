<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$id = required_param('id', PARAM_INT);
require_login();

$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$context = context_course::instance((int)$course->id);

if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
    throw new required_capability_exception($context, 'moodle/course:update', 'nopermissions', '');
}

if (data_submitted() && confirm_sesskey()) {
    $fileitemid = (int)($edition->taskfileitemid ?? 0);
    $fileitemid = manager::store_named_upload($context->id, $fileitemid, 'taskfile', 'taskfile');
    $duedatetext = optional_param('taskduedate_text', '', PARAM_TEXT);
    $duedate = $duedatetext !== '' ? (strtotime(str_replace('T', ' ', $duedatetext)) ?: 0) : 0;
    manager::save_internal_task_config((int)$edition->id, required_param('taskdescription', PARAM_TEXT), optional_param('taskurl', '', PARAM_RAW_TRIMMED), $duedate, $fileitemid);
    redirect(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]), get_string('changessaved'));
}

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/internal_task.php', ['id' => $id]));
$PAGE->set_title('Gestionar tarea');
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al taller', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');
echo $OUTPUT->heading('Gestionar tarea: ' . format_string($workshop->name));

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::label('Descripción de la tarea', 'taskdescription');
echo html_writer::tag('textarea', s($edition->taskdescription ?? ''), ['name' => 'taskdescription', 'id' => 'taskdescription', 'class' => 'form-control mb-3', 'rows' => 5, 'required' => 'required']);

echo html_writer::label('Archivo de la tarea para el alumno', 'taskfile');
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'taskfile', 'id' => 'taskfile', 'class' => 'form-control mb-2']);
$currentfile = manager::get_filearea_url($context, 'taskfile', (int)($edition->taskfileitemid ?? 0));
if ($currentfile !== '') {
    echo html_writer::tag('p', html_writer::link($currentfile, 'Ver archivo actual', ['target' => '_blank']), ['class' => 'text-muted']);
}

echo html_writer::label('Enlace visitable', 'taskurl');
echo html_writer::empty_tag('input', ['type' => 'url', 'name' => 'taskurl', 'id' => 'taskurl', 'class' => 'form-control mb-3', 'value' => s($edition->taskurl ?? ''), 'placeholder' => 'https://...']);

$duedatevalue = !empty($edition->taskduedate) ? date('Y-m-d\TH:i', (int)$edition->taskduedate) : '';
echo html_writer::label('Fecha límite de entrega', 'taskduedate_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'taskduedate_text', 'id' => 'taskduedate_text', 'class' => 'form-control mb-3', 'value' => $duedatevalue]);

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('savechanges'), 'class' => 'btn btn-primary']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]), 'Volver al taller', ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
