<?php
// In-place Apuntarme / Desapuntarme from the course page (no navigation).
// POST only, sesskey required; answers JSON with the new button state.
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;

$editionid = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$edition = manager::get_workshop_edition($editionid);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => (int)$workshop->courseid], '*', MUST_EXIST);

require_login($course, false, null, false, true);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}
require_sesskey();

$userid = (int)$USER->id;
$result = (object)['success' => false, 'message' => ''];

if ($action === 'enrol') {
    $series = workshop_series::series_for_edition($editionid);
    if (!empty($edition->archived) || manager::is_edition_finished($edition)
            || ($series && (string)($series->status ?? '') === 'finished')) {
        $result->message = 'Esta edición ya está finalizada y no admite inscripciones.';
    } else {
        $result = manager::enrol_user_in_edition($editionid, $userid, 'self');
    }
} else if ($action === 'unenrol') {
    if (!empty($edition->sessiondate) && time() >= (int)$edition->sessiondate) {
        $result->message = 'El taller ya ha comenzado. Si necesitas darte de baja, contacta con el profesor o gestor.';
    } else {
        $result = manager::unenrol_user_from_edition($editionid, $userid);
    }
} else {
    throw new invalid_parameter_exception('Acción no válida.');
}

$edition = manager::get_workshop_edition($editionid);
echo json_encode([
    'success' => !empty($result->success),
    'message' => (string)($result->message ?? ''),
    'status' => manager::enrol_button_status($edition, $userid),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
