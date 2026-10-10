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
 * upload.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\form\upload_form;
use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$activity = manager::get_activity($id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/upload.php', ['id' => $id]));
$PAGE->set_title(get_string('uploadcsv', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

$form = new upload_form(null, ['activity' => $activity]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/gestion_actividades/view.php', ['id' => $id]));
} else if ($data = $form->get_data()) {
    $filepath = $form->save_temp_file('csvfile');
    if (!$filepath) {
        throw new moodle_exception('No se ha podido guardar temporalmente el CSV.');
    }
    $filename = '';
    $draftid = file_get_submitted_draft_itemid('csvfile');
    $fs = get_file_storage();
    $files = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $draftid, 'id', false);
    if ($files) {
        $file = reset($files);
        $filename = $file->get_filename();
    }
    $summary = manager::process_csv($activity, $filepath, $filename, $data->gradecolumn, !empty($data->creategroup), !empty($data->createmissingusers), trim((string)$data->academicyear), !empty($data->savegradehistory), !empty($data->updategradebook), trim((string)($data->gradeitemname ?? '')));
    $message = get_string('importsummary', 'local_gestion_actividades', $summary);
    if (!empty($summary->groupid)) {
        $message .= ' ' . get_string('groupcreated', 'local_gestion_actividades', $summary->groupid);
    } else {
        $message .= ' ' . get_string('groupnotcreated', 'local_gestion_actividades');
    }
    redirect(new moodle_url('/local/gestion_actividades/view.php', ['id' => $id]), $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($activity->name) . ': ' . get_string('uploadcsv', 'local_gestion_actividades'));
echo html_writer::div('CSV esperado: columna identificadora (' . s($activity->idfield) . ') y columna de nota (nota o grade). Separador aceptado: coma o punto y coma.', 'alert alert-info');
$form->display();
echo $OUTPUT->footer();
