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
$PAGE->set_url(new moodle_url('/local/gestion_actividades/gradehistory.php', ['id' => $id]));
$PAGE->set_title(get_string('gradehistory', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

$records = method_exists(manager::class, 'get_grade_import_log') ? manager::get_grade_import_log($activity->activitykey, 2000) : [];
if (!$records) {
    $records = manager::get_grade_history($activity->activitykey, 2000);
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('gradehistory', 'local_gestion_actividades') . ': ' . format_string($activity->name));
echo html_writer::div(get_string('gradehistoryinfo', 'local_gestion_actividades'), 'alert alert-info');
echo html_writer::link(new moodle_url('/local/gestion_actividades/view.php', ['id' => $id]), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary mb-3']);

if (!$records) {
    echo $OUTPUT->notification(get_string('nogradehistory', 'local_gestion_actividades'), 'info');
    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('academicyearcol', 'local_gestion_actividades'),
    get_string('fullname', 'local_gestion_actividades'),
    'Email',
    get_string('grade', 'local_gestion_actividades'),
    'Fecha de importación/actualización',
];
$table->data = [];
foreach ($records as $r) {
    $table->data[] = [
        s($r->academicyear),
        s(fullname($r)),
        s($r->email),
        is_null($r->grade) ? '-' : format_float($r->grade, 2),
        userdate($r->timemodified),
    ];
}
echo html_writer::table($table);
if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
