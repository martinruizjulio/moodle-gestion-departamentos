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
        $statuses[(int)$edition->id] = manager::enrol_button_status($edition, (int)$USER->id);
    }
}

echo json_encode(['statuses' => $statuses], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
