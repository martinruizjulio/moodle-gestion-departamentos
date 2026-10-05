<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\exception_access;
use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}
exception_access::ensure_tables();

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($action === 'create') {
        $newid = exception_access::create_user(
            required_param('firstname', PARAM_TEXT),
            required_param('lastname', PARAM_TEXT),
            required_param('email', PARAM_EMAIL),
            required_param('username', PARAM_ALPHANUMEXT),
            required_param('password', PARAM_RAW),
            (int)$USER->id
        );
        exception_access::log($newid, 'account_created', 'exception_user', $newid, 'Cuenta excepcional creada por ' . fullname($USER) . '.');
        $message = 'Usuario excepcional creado correctamente.';
    } else if ($action === 'toggle' && $id > 0) {
        $active = required_param('active', PARAM_BOOL);
        exception_access::set_active($id, (bool)$active);
        exception_access::log($id, $active ? 'account_enabled' : 'account_disabled', 'exception_user', $id, 'Cambio realizado por ' . fullname($USER) . '.');
        $message = $active ? 'Usuario reactivado.' : 'Usuario desactivado y sesiones cerradas.';
    } else if ($action === 'password' && $id > 0) {
        exception_access::reset_password($id, required_param('password', PARAM_RAW));
        exception_access::log($id, 'password_reset', 'exception_user', $id, 'Contraseña restablecida por ' . fullname($USER) . '.');
        $message = 'Contraseña actualizada y sesiones anteriores cerradas.';
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/exception_users.php'));
$PAGE->set_title('Excepcionalidad');
$PAGE->set_heading('Gestión HEE - Excepcionalidad');

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), '← Volver al panel', ['class' => 'btn btn-outline-secondary mb-3']));
echo $OUTPUT->heading('Excepcionalidad');
echo html_writer::tag('p', 'Gestiona accesos externos limitados a Gestión HEE para personal que no puede autenticarse mediante el acceso institucional. Estas cuentas no conceden acceso general al Campus Virtual.', ['class' => 'alert alert-info']);
if ($message !== '') {
    echo $OUTPUT->notification($message, 'success');
}

$externalurl = new moodle_url('/local/gestion_actividades/external_access.php');
echo html_writer::tag('div', '<strong>Enlace de acceso para el usuario excepcional:</strong><br><code>' . s($externalurl->out(false)) . '</code>', ['class' => 'alert alert-secondary']);

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', 'Crear usuario excepcional', ['class' => 'h5']);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
echo html_writer::start_div('row');
$fields = [
    ['Nombre', 'firstname', 'text'],
    ['Apellidos', 'lastname', 'text'],
    ['Correo', 'email', 'email'],
    ['Usuario', 'username', 'text'],
    ['Contraseña inicial', 'password', 'password'],
];
foreach ($fields as [$label, $name, $type]) {
    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::tag('label', $label, ['for' => $name]);
    echo html_writer::empty_tag('input', ['type' => $type, 'name' => $name, 'id' => $name, 'class' => 'form-control', 'required' => 'required', 'autocomplete' => $type === 'password' ? 'new-password' : 'off']);
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::tag('p', 'La contraseña debe tener al menos 12 caracteres e incluir letras y números. No se almacena en texto plano.', ['class' => 'text-muted small']);
echo html_writer::tag('button', 'Crear usuario excepcional', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

$users = exception_access::list_users();
echo html_writer::tag('h3', 'Usuarios excepcionales', ['class' => 'h4']);
if (!$users) {
    echo $OUTPUT->notification('Todavía no hay usuarios excepcionales.', 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Nombre', 'Correo', 'Usuario', 'Estado', 'Último acceso', 'Creado', 'Acciones'];
    foreach ($users as $u) {
        $toggle = html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-block;margin-right:6px;']);
        $toggle .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $toggle .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'toggle']);
        $toggle .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $u->id]);
        $toggle .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'active', 'value' => !empty($u->active) ? 0 : 1]);
        $toggle .= html_writer::tag('button', !empty($u->active) ? 'Desactivar' : 'Reactivar', ['type' => 'submit', 'class' => !empty($u->active) ? 'btn btn-outline-danger btn-sm' : 'btn btn-success btn-sm']);
        $toggle .= html_writer::end_tag('form');

        $password = html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-flex;gap:4px;vertical-align:middle;']);
        $password .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $password .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'password']);
        $password .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $u->id]);
        $password .= html_writer::empty_tag('input', ['type' => 'password', 'name' => 'password', 'class' => 'form-control form-control-sm', 'placeholder' => 'Nueva contraseña', 'required' => 'required', 'minlength' => 12]);
        $password .= html_writer::tag('button', 'Cambiar', ['type' => 'submit', 'class' => 'btn btn-secondary btn-sm']);
        $password .= html_writer::end_tag('form');

        $table->data[] = [
            s(trim($u->firstname . ' ' . $u->lastname)),
            s($u->email),
            s($u->username),
            !empty($u->active) ? html_writer::span('Activo', 'badge badge-success') : html_writer::span('Desactivado', 'badge badge-secondary'),
            !empty($u->timelastlogin) ? userdate((int)$u->timelastlogin) : 'Nunca',
            !empty($u->timecreated) ? userdate((int)$u->timecreated) : '-',
            $toggle . $password,
        ];
    }
    echo html_writer::table($table);
}

$logs = exception_access::recent_logs(40);
echo html_writer::tag('h3', 'Auditoría reciente', ['class' => 'h4 mt-4']);
if ($logs) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Fecha', 'Usuario', 'Acción', 'Detalle', 'IP'];
    foreach ($logs as $log) {
        $table->data[] = [
            userdate((int)$log->timecreated),
            s(trim($log->firstname . ' ' . $log->lastname)),
            s($log->action),
            s($log->details ?? ''),
            s($log->ip ?? ''),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
