<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$id = required_param('id', PARAM_INT); // edition id

require_login();
require_sesskey();

$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($course->id);

if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}

$groupid = manager::get_or_create_edition_group((int)$edition->id);
$added = manager::sync_edition_group_members((int)$edition->id);
$restricted = false;

if (!empty($edition->requiredcmid) && $DB->record_exists('course_modules', ['id' => (int)$edition->requiredcmid])) {
    $restricted = manager::restrict_required_activity_to_edition_group((int)$edition->id, (int)$edition->requiredcmid);
}

redirect(
    new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]),
    get_string('requiredactivityrestrictionrepaired', 'local_gestion_actividades', (object)[
        'groupid' => $groupid,
        'added' => $added,
        'restricted' => $restricted ? 1 : 0,
    ])
);
