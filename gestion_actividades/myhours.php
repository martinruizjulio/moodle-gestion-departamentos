<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
require_capability('local/gestion_actividades:view', $context);

$userid = (int)optional_param('userid', (int)$USER->id, PARAM_INT);
if ($userid !== (int)$USER->id) {
    if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}
}

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/myhours.php', ['userid' => $userid]));
$PAGE->set_title(get_string('mytypeahours', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('mytypeahours', 'local_gestion_actividades') . ': ' . fullname($user));

$total = manager::get_student_total_hours($userid);
echo html_writer::tag('div',
    html_writer::tag('strong', get_string('totaltypeahours', 'local_gestion_actividades') . ': ') . round($total, 2) . ' h',
    ['class' => 'alert alert-success']
);

echo html_writer::tag('p', get_string('storedhoursnote', 'local_gestion_actividades'), ['class' => 'text-muted']);

$rows = manager::get_student_hour_history($userid);
if (!$rows) {
    echo $OUTPUT->notification(get_string('noworkshopeditionsyet', 'local_gestion_actividades'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('workshop', 'local_gestion_actividades'),
        get_string('edition', 'local_gestion_actividades'),
        get_string('hours', 'local_gestion_actividades'),
        get_string('completedon', 'local_gestion_actividades'),
        get_string('certificate', 'local_gestion_actividades'),
    ];
    foreach ($rows as $row) {
        $table->data[] = [
            s($row->workshopcode) . ' - ' . format_string($row->workshopname),
            format_string($row->editionname),
            round((float)$row->hours, 2),
            $row->timecompleted ? userdate($row->timecompleted) : '-',
            s($row->certificatestatus),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
