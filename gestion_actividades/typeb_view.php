<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\portfolio_typeb;

$id = required_param('id', PARAM_INT);
require_login();

$record = portfolio_typeb::get($id);
$context = context_system::instance();
$canmanage = \local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id);

if ((int)$record->userid !== (int)$USER->id && !$canmanage) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$fs = get_file_storage();
$file = $fs->get_file($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$record->id, '/', $record->filename);
if (!$file || $file->is_directory()) {
    $files = $fs->get_area_files($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$record->id, 'filename', false);
    foreach ($files as $candidate) {
        if (!$candidate->is_directory()) {
            $file = $candidate;
            break;
        }
    }
}
if (!$file || $file->is_directory()) {
    throw new moodle_exception('filenotfound');
}

// Student-supplied evidence: only PDF and raster images are shown inline.
// Anything else (e.g. HTML/SVG uploaded before the filepicker restriction)
// is forced to download so it can never run in the Moodle origin.
$inline = in_array($file->get_mimetype(), ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'], true);
send_stored_file($file, 0, 0, !$inline, ['filename' => $record->filename ?: ('certificado_tipo_b_' . $record->id . '.pdf')]);
