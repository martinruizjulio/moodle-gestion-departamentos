<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\portfolio_typeb;

$id = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$comment = optional_param('comment', '', PARAM_TEXT);

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}
require_sesskey();

$status = $action === 'validate' ? 'validated' : ($action === 'reject' ? 'rejected' : 'pending');
portfolio_typeb::set_status($id, $status, $comment, (int)$USER->id);

// Back to the page the manager validated from (the requests list keeps its
// filter); the old Tipo B listing in «Listados y descargas» also works.
$referer = get_local_referer(false);
if ($referer && strpos($referer, '/local/gestion_actividades/portfolio_admin.php') !== false) {
    $returnurl = new moodle_url($referer);
} else if ($referer && strpos($referer, '/local/gestion_actividades/manager_downloads.php') !== false) {
    $returnurl = new moodle_url('/local/gestion_actividades/manager_downloads.php', ['action' => 'view_typeb_workshops']);
} else {
    $returnurl = new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['status' => 'pending']);
}
$messages = [
    'validated' => 'Solicitud validada. El alumno ya puede escribir su reflexión; al entregarla, las horas se suman a su ficha.',
    'rejected' => 'Solicitud rechazada. El alumno puede corregirla con «Editar» y volver a enviarla.',
    'pending' => 'Solicitud devuelta a pendiente.',
];
redirect($returnurl, $messages[$status], null, \core\output\notification::NOTIFY_SUCCESS);
