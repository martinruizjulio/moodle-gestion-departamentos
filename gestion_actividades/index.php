<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/index.php'));
$PAGE->set_title(get_string('studentssection', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('studentssection', 'local_gestion_actividades'));
echo html_writer::tag('p', get_string('studentspanelcleanintro', 'local_gestion_actividades'), ['class' => 'alert alert-info']);

echo html_writer::tag('h4', get_string('studentsmanagement', 'local_gestion_actividades'));
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/users.php'), get_string('bulkcreateusers', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']) . ' ' .
    html_writer::link(new moodle_url('/local/gestion_actividades/edit.php'), 'Nuevo listado de alumnos/notas', ['class' => 'btn btn-primary']),
    'mb-3'
);

$activities = method_exists(manager::class, 'list_activities') ? manager::list_activities() : [];
if ($activities) {
    echo html_writer::tag('h4', 'Listados de alumnos y notas');
    $table = new html_table();
    $table->head = ['Nombre', 'Curso ID', 'Plazas', 'Identificador', 'Acciones'];
    foreach ($activities as $activity) {
        $actions = html_writer::link(new moodle_url('/local/gestion_actividades/view.php', ['id' => $activity->id]), 'Ver listado', ['class' => 'btn btn-secondary btn-sm']) . ' ' .
            html_writer::link(new moodle_url('/local/gestion_actividades/upload.php', ['id' => $activity->id]), 'Subir notas', ['class' => 'btn btn-primary btn-sm']) . ' ' .
            html_writer::link(new moodle_url('/local/gestion_actividades/export.php', ['id' => $activity->id]), get_string('export'), ['class' => 'btn btn-secondary btn-sm']) . ' ' .
            html_writer::link(new moodle_url('/local/gestion_actividades/gradehistory.php', ['id' => $activity->id]), get_string('gradehistory', 'local_gestion_actividades'), ['class' => 'btn btn-secondary btn-sm']);
        $table->data[] = [format_string($activity->name), (int)$activity->courseid, (int)$activity->places, s($activity->idfield), $actions];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no hay listados de alumnos/notas. Crea uno para poder subir CSV con notas de expediente.', 'info');
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
