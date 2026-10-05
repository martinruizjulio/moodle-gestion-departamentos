<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$activity = manager::get_activity($id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/view.php', ['id' => $id]));
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($activity->name));

echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php'), get_string('workshopsandeditions', 'local_gestion_actividades'), ['class' => 'btn btn-primary']) . ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/index.php'), get_string('callsandranking', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']), 'mb-3');

$buttons = [];
$buttons[] = html_writer::link(new moodle_url('/local/gestion_actividades/index.php'), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);
$buttons[] = html_writer::link(new moodle_url('/local/gestion_actividades/upload.php', ['id' => $id]), get_string('upload', 'local_gestion_actividades'), ['class' => 'btn btn-primary']);
$buttons[] = html_writer::link(new moodle_url('/local/gestion_actividades/export.php', ['id' => $id]), get_string('export', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);
$buttons[] = html_writer::link(new moodle_url('/local/gestion_actividades/gradehistory.php', ['id' => $id]), get_string('gradehistory', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);
$buttons[] = html_writer::link(new moodle_url('/local/gestion_actividades/workshopgroup.php', ['id' => $id]), get_string('setworkshopgroup', 'local_gestion_actividades'), ['class' => 'btn btn-primary']);
$buttons[] = html_writer::link(new moodle_url('/local/gestion_actividades/attendance.php', ['id' => $id]), get_string('syncattendance', 'local_gestion_actividades'), ['class' => 'btn btn-success']);
$buttons[] = html_writer::link(new moodle_url('/local/gestion_actividades/complete.php', ['id' => $id, 'sesskey' => sesskey()]), get_string('markcompleted', 'local_gestion_actividades'), ['class' => 'btn btn-warning']);
echo html_writer::div(implode(' ', $buttons), 'mb-3');

$info = new html_table();
$info->data = [
    [get_string('courseid', 'local_gestion_actividades'), $activity->courseid],
    [get_string('activitykey', 'local_gestion_actividades'), s($activity->activitykey)],
    [get_string('places', 'local_gestion_actividades'), $activity->places],
    [get_string('idfield', 'local_gestion_actividades'), s($activity->idfield)],
    [get_string('teacherid', 'local_gestion_actividades'), $activity->teacherid ?: '-'],
];
echo html_writer::table($info);

$candidates = $DB->get_records('local_ga_candidates', ['activityid' => $id]);
if (!$candidates) {
    echo $OUTPUT->notification(get_string('nocandidates', 'local_gestion_actividades'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$statusorder = [
    'selected' => 10,
    'reserve' => 20,
    'attended' => 30,
    'completed' => 35,
    'nograde' => 40,
    'notfound' => 50,
    'duplicate' => 60,
    'invalid' => 70,
];
usort($candidates, function($a, $b) use ($statusorder) {
    $oa = $statusorder[$a->status] ?? 99;
    $ob = $statusorder[$b->status] ?? 99;
    if ($oa !== $ob) {
        return $oa <=> $ob;
    }
    if ((int)$a->rank !== (int)$b->rank) {
        return (int)$a->rank <=> (int)$b->rank;
    }
    if ((float)$a->grade !== (float)$b->grade) {
        return ((float)$a->grade < (float)$b->grade) ? 1 : -1;
    }
    return strcmp((string)$a->lastname, (string)$b->lastname);
});

$table = new html_table();
$table->head = [get_string('rank', 'local_gestion_actividades'), get_string('fullname', 'local_gestion_actividades'), get_string('identifier', 'local_gestion_actividades'), get_string('grade', 'local_gestion_actividades'), get_string('status', 'local_gestion_actividades'), get_string('reason', 'local_gestion_actividades')];
$table->data = [];
foreach ($candidates as $c) {
    $fullname = trim($c->firstname . ' ' . $c->lastname);
    $identifier = $c->identifier;
    $status = get_string_manager()->string_exists($c->status, 'local_gestion_actividades') ? get_string($c->status, 'local_gestion_actividades') : s($c->status);
    $table->data[] = [
        $c->rank ?: '-',
        s($fullname),
        s($identifier),
        is_null($c->grade) ? '-' : format_float($c->grade, 2),
        $status,
        s($c->reason),
    ];
}
echo html_writer::table($table);

echo $OUTPUT->footer();
