<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$id = optional_param('id', 0, PARAM_INT);
$workshopid = required_param('workshopid', PARAM_INT);
$editionid = optional_param('editionid', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_BOOL);

$workshop = manager::get_workshop($workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($course->id);

if (!manager::can_manage_workshop_instance((int)$workshop->id, (int)$USER->id)) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}

$material = $id ? manager::get_material($id) : (object)[
    'id' => 0,
    'workshopid' => $workshopid,
    'editionid' => $editionid,
    'name' => '',
    'description' => '',
    'url' => '',
    'visible' => 1,
    'fileitemid' => 0,
];

// Never trust a material id together with a caller-supplied workshop id.
if ($id > 0 && (int)$material->workshopid !== $workshopid) {
    throw new invalid_parameter_exception('El material no pertenece al taller indicado.');
}

// Existing records keep their canonical edition. New records may use the requested edition.
if ($id > 0) {
    $editionid = (int)($material->editionid ?? 0);
}
if ($editionid > 0) {
    $edition = manager::get_workshop_edition($editionid);
    if ((int)$edition->workshopid !== $workshopid) {
        throw new invalid_parameter_exception('La edición del material no pertenece a este taller.');
    }
    if (!manager::can_manage_edition($editionid, (int)$USER->id)) {
        throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
    }
}

if ($id && $delete && confirm_sesskey()) {
    manager::delete_material($id);
    redirect(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshopid, 'editionid' => $editionid]), get_string('materialdeleted', 'local_gestion_actividades'));
}

if (data_submitted() && confirm_sesskey() && !$delete) {
    $postededitionid = optional_param('editionid', $editionid, PARAM_INT);
    if ($id > 0 && $postededitionid !== $editionid) {
        throw new invalid_parameter_exception('No se puede cambiar la edición de un material mediante este formulario.');
    }
    if ($postededitionid > 0) {
        $postededition = manager::get_workshop_edition($postededitionid);
        if ((int)$postededition->workshopid !== $workshopid
                || !manager::can_manage_edition($postededitionid, (int)$USER->id)) {
            throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
        }
    }

    $fileitemid = (int)($material->fileitemid ?? 0);
    try {
        $fileitemid = manager::store_material_upload($coursecontext->id, $fileitemid, 'materialfileupload');
    } catch (\Throwable $e) {
        redirect(new moodle_url('/local/gestion_actividades/material_edit.php', ['workshopid' => $workshopid, 'editionid' => $editionid, 'id' => $id]), get_string('materialuploaderror', 'local_gestion_actividades') . ': ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    $data = [
        'id' => $id,
        'workshopid' => $workshopid,
        'editionid' => $postededitionid,
        'name' => required_param('name', PARAM_TEXT),
        'description' => optional_param('description', '', PARAM_TEXT),
        'url' => optional_param('url', '', PARAM_RAW_TRIMMED),
        'visible' => optional_param('visible', 0, PARAM_BOOL),
        'fileitemid' => $fileitemid,
    ];
    manager::save_material((object)$data);
    redirect(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshopid, 'editionid' => $postededitionid]), get_string('changessaved'));
}

$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/material_edit.php', ['workshopid' => $workshopid, 'editionid' => $editionid, 'id' => $id]));
$PAGE->set_title(get_string('editmaterial', 'local_gestion_actividades'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshopid, 'editionid' => $editionid]), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall mr-1']) . ' Volver al taller', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('editmaterial', 'local_gestion_actividades') . ': ' . format_string($workshop->name));
echo html_writer::tag('p', get_string('materialupload_simple_help', 'local_gestion_actividades'), ['class' => 'alert alert-info']);

$currentfileurl = '';
try {
    $currentfileurl = manager::get_material_file_url($material, $coursecontext);
} catch (\Throwable $e) {
    $currentfileurl = '';
}
if ($currentfileurl !== '') {
    echo html_writer::tag('p', html_writer::link($currentfileurl, get_string('currentfile', 'local_gestion_actividades'), ['target' => '_blank']), ['class' => 'alert alert-secondary']);
}

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $material->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'workshopid', 'value' => $workshopid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'editionid', 'value' => $editionid]);

echo html_writer::label(get_string('name'), 'name');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'id' => 'name', 'value' => s($material->name), 'class' => 'form-control mb-2', 'required' => 'required']);

echo html_writer::label(get_string('description'), 'description');
echo html_writer::tag('textarea', s($material->description), ['name' => 'description', 'id' => 'description', 'class' => 'form-control mb-2', 'rows' => 4]);

echo html_writer::label(get_string('materialurl', 'local_gestion_actividades'), 'url');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'url', 'id' => 'url', 'value' => s($material->url), 'class' => 'form-control mb-2', 'placeholder' => 'https://...']);

echo html_writer::label(get_string('uploadfile', 'local_gestion_actividades'), 'materialfileupload');
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'materialfileupload', 'id' => 'materialfileupload', 'class' => 'form-control mb-2']);

echo html_writer::label(html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'visible', 'value' => 1, 'checked' => !empty($material->visible) ? 'checked' : null]) . ' ' . get_string('visible'), 'visible');
echo html_writer::empty_tag('br');

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('savechanges'), 'class' => 'btn btn-primary mt-3']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshopid, 'editionid' => $editionid]), 'Volver al taller', ['class' => 'btn btn-secondary mt-3']);
echo html_writer::end_tag('form');

if ($id) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/material_edit.php', ['id' => $id, 'workshopid' => $workshopid, 'editionid' => $editionid, 'delete' => 1, 'sesskey' => sesskey()]), get_string('delete'), ['class' => 'btn btn-danger mt-3']), 'mt-2');
}

echo $OUTPUT->footer();
