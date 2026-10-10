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
 * typeb_review.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
