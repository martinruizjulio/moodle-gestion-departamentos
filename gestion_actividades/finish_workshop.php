<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$id = required_param('id', PARAM_INT); // edition id

require_login();

$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($course->id);

// An HEE professor may only finish the concrete edition assigned to them.
// Workshop-level permission would be too broad when a base workshop has several editions.
if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}

require_sesskey();

$certcount = manager::count_edition_certificates((int)$edition->id);
$enrolledcount = count(manager::list_edition_enrolled_users_ultrasafe((int)$edition->id));
if ($certcount <= 0 && $enrolledcount > 0) {
    redirect(
        new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]),
        'Antes de terminar y archivar debes generar los certificados.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

try {
    manager::refresh_completed_hours_for_edition((int)$edition->id);
} catch (Throwable $e) {
    // Archiving must remain available; completed hours can be rebuilt from management tools.
}
manager::archive_finished_workshop_edition((int)$edition->id);

$message = get_string('workshopfinishedhardarchived', 'local_gestion_actividades');

redirect(
    new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]),
    $message,
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
