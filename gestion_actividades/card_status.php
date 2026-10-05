<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

global $USER;
require_login();
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$statuses = [];
foreach (['typea', 'typeb'] as $type) {
    foreach (manager::list_workshops($courseid, $type) as $workshop) {
        $edition = manager::get_primary_workshop_edition((int)$workshop->id);
        if (!$edition) {
            continue;
        }
        $enrolment = manager::get_edition_enrolment((int)$edition->id, (int)$USER->id);
        $isenrolled = manager::is_active_enrolment($enrolment);
        $closed = manager::is_edition_enrolment_closed($edition);
        $statuses[(int)$edition->id] = [
            'enrolled' => $isenrolled,
            'closed' => $closed,
            'label' => $isenrolled
                ? get_string('enrolledbutton', 'local_gestion_actividades')
                : ($closed ? get_string('enrolmentclosed', 'local_gestion_actividades') : get_string('enrolme', 'local_gestion_actividades')),
        ];
    }
}

echo json_encode(['statuses' => $statuses], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
