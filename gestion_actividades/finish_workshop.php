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

// Finishing issues the pending certificates itself (attendance + quiz >= 5/10
// for Type A, attendance + reflection for Type B) and records the hours.
try {
    \local_gestion_actividades\local\auto_certificates::process_edition((int)$edition->id);
} catch (Throwable $e) {
    // Archiving must remain available; certificates can be generated later.
    debugging('No se pudieron emitir los certificados al finalizar: ' . $e->getMessage(), DEBUG_DEVELOPER);
}
manager::archive_finished_workshop_edition((int)$edition->id);

$message = get_string('workshopfinishedhardarchived', 'local_gestion_actividades');

redirect(
    new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]),
    $message,
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
