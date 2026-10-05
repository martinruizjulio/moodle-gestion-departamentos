<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\typeb_certificate_policy;

$id = required_param('id', PARAM_INT); // edition id
require_login();

$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($course->id);

if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}
require_sesskey();

if (manager::is_typeb_workshop($workshop)) {
    $summary = typeb_certificate_policy::generate_for_edition((int)$edition->id);
    $message = 'Certificados Tipo B: ' . (int)$summary->generated . ' generados, '
        . (int)$summary->existing . ' ya existentes y ' . (int)$summary->skipped . ' pendientes. '
        . 'Falta asistencia: ' . (int)$summary->missingattendance . '. '
        . 'Falta reflexión: ' . (int)$summary->missingreflection . '.';
} else {
    $summary = manager::generate_certificates_for_edition((int)$edition->id);
    $message = get_string('certificatesgeneratedsummary', 'local_gestion_actividades', $summary)
        . ' Correos enviados: alumnado ' . (int)($summary->studentemails ?? 0)
        . ', profesorado/gestores ' . (int)($summary->staffemails ?? 0) . '.';
}

redirect(
    new moodle_url('/local/gestion_actividades/certificates.php', ['editionid' => $edition->id]),
    $message,
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
