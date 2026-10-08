<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop($edition->workshopid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/edition_sync.php', ['id' => $id]));
$PAGE->set_title(get_string('synceditionenrolments', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('synceditionenrolments', 'local_gestion_actividades') . ': ' . format_string($edition->name));

if (data_submitted() && confirm_sesskey()) {
    $summary = manager::sync_edition_enrolments_from_group($id);
    echo $OUTPUT->notification(get_string('synceditiondone', 'local_gestion_actividades'), 'success');
    $table = new html_table();
    $table->data = [
        [get_string('groupmembers', 'local_gestion_actividades'), $summary->members],
        [get_string('participantsloaded', 'local_gestion_actividades'), $summary->inserted],
        [get_string('blockedrepeat', 'local_gestion_actividades'), $summary->blockedrepeat],
        [get_string('overplaces', 'local_gestion_actividades'), $summary->overplaces],
        [get_string('closedfull', 'local_gestion_actividades'), $summary->closed ? get_string('yes') : get_string('no')],
    ];
    echo html_writer::table($table);
}

echo html_writer::tag('p', get_string('syncedition_help', 'local_gestion_actividades'));
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('synceditionenrolments', 'local_gestion_actividades')]);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => $workshop->id]), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
