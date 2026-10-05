<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
require_sesskey();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$workshopid = required_param('workshopid', PARAM_INT);
$ok = manager::ensure_workshop_sections_safely($workshopid);

redirect(
    new moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => $workshopid]),
    $ok ? get_string('sectionsrepairedok', 'local_gestion_actividades') : get_string('sectionsrepairedpartial', 'local_gestion_actividades')
);
