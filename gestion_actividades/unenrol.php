<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$editionid = required_param('id', PARAM_INT);
$edition = manager::get_workshop_edition($editionid);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => (int)$workshop->courseid], '*', MUST_EXIST);

require_login($course);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
require_sesskey();

$returnurl = new moodle_url('/local/gestion_actividades/workshop_view.php', [
    'id' => (int)$workshop->id,
    'editionid' => $editionid,
]);

$result = manager::unenrol_user_from_edition($editionid, (int)$USER->id);

redirect(
    $returnurl,
    $result->message,
    null,
    $result->success
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_WARNING
);
