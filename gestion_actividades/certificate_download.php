<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$id = required_param('id', PARAM_INT);
require_login();

$cert = $DB->get_record('local_ga_certificates', ['id' => $id], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => (int)$cert->courseid], '*', MUST_EXIST);
$context = context_course::instance((int)$course->id);
$PAGE->set_context($context);

// Certificates linked to an edition inherit that exact edition permission.
// Legacy certificates without edition cannot be safely attributed to one
// Profesor HEE assignment, so only the owner or a global HEE manager may read them.
$canmanage = !empty($cert->editionid)
    ? manager::can_manage_edition((int)$cert->editionid, (int)$USER->id)
    : manager::can_manage_globally((int)$USER->id);
if ((int)$cert->userid !== (int)$USER->id && !$canmanage) {
    throw new required_capability_exception($context, 'moodle/course:update', 'nopermissions', '');
}

$fs = get_file_storage();
$file = $fs->get_file($context->id, 'local_gestion_actividades', 'certificate', (int)$cert->id, '/', $cert->filename);

// Fallback: if filename changed or was stored differently, get the first PDF in the certificate area.
if (!$file || $file->is_directory()) {
    $files = $fs->get_area_files($context->id, 'local_gestion_actividades', 'certificate', (int)$cert->id, 'filename', false);
    foreach ($files as $candidate) {
        if (!$candidate->is_directory() && strtolower($candidate->get_filename()) !== '.') {
            $file = $candidate;
            break;
        }
    }
}

if (!$file || $file->is_directory()) {
    throw new moodle_exception('filenotfound');
}

$filename = $cert->filename;
if (empty($filename) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
    $filename = 'certificado_taller_' . (int)$cert->id . '.pdf';
}

send_stored_file($file, 0, 0, true, [
    'filename' => $filename,
    'dontdie' => false,
]);
