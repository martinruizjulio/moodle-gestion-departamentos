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
 * certificate_download.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
