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
 * users.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\form\user_upload_form;
use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$title = 'Comprobar / actualizar usuarios institucionales';
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/users.php'));
$PAGE->set_title($title);
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

$form = new user_upload_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/gestion_actividades/index.php'));
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading($title);
echo html_writer::link(new moodle_url('/local/gestion_actividades/index.php'), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary mb-3']);
echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/template.php', ['type' => 'users']), get_string('downloadusertemplate', 'local_gestion_actividades'), ['class' => 'btn btn-outline-secondary mb-3']);

echo html_writer::div('El CSV se coteja únicamente con cuentas Moodle institucionales ya existentes. Gestión HEE no crea cuentas nuevas. Los usuarios no encontrados deben provisionarse primero mediante la administración institucional de Moodle.', 'alert alert-info');

$canupdateusers = has_capability('moodle/user:update', context_system::instance());
if (!$canupdateusers) {
    echo $OUTPUT->notification('Puedes cotejar el CSV con las cuentas existentes. Actualizar datos de usuarios requiere el permiso institucional «moodle/user:update».', 'info');
}

if ($data = $form->get_data()) {
    if (!$canupdateusers) {
        $data->updateexisting = 0;
    }
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

    $summary = manager::process_users_csv($filepath, $filename, !empty($data->updateexisting));
    echo $OUTPUT->notification(get_string('usersimportsummary', 'local_gestion_actividades', $summary), 'success');

    $table = new html_table();
    $table->head = ['Fila', 'ID Moodle', 'Email', 'Username', get_string('fullname', 'local_gestion_actividades'), get_string('status', 'local_gestion_actividades'), get_string('reason', 'local_gestion_actividades')];
    $table->data = [];
    foreach ($summary->rows as $row) {
        $table->data[] = [
            $row->row,
            $row->userid ?: '-',
            s($row->email),
            s($row->username),
            s(trim($row->firstname . ' ' . $row->lastname)),
            s($row->status),
            s($row->message),
        ];
    }
    echo html_writer::table($table);
} else {
    $form->display();
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
