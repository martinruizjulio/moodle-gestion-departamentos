<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\form\user_upload_form;
use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/users.php'));
$PAGE->set_title(get_string('bulkcreateusers', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

$form = new user_upload_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/gestion_actividades/index.php'));
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('bulkcreateusers', 'local_gestion_actividades'));
echo html_writer::link(new moodle_url('/local/gestion_actividades/index.php'), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary mb-3']);
echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/template.php', ['type' => 'users']), get_string('downloadusertemplate', 'local_gestion_actividades'), ['class' => 'btn btn-outline-secondary mb-3']);

echo html_writer::div(get_string('bulkcreateusersinfo', 'local_gestion_actividades'), 'alert alert-info');

echo $OUTPUT->notification('Gestión HEE no crea cuentas Moodle. Este CSV solo trabaja con cuentas institucionales ya existentes; los usuarios no encontrados deben provisionarse primero mediante la administración institucional.', 'info');

if ($data = $form->get_data()) {
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
