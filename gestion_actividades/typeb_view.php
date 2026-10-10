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
 * typeb_view.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
