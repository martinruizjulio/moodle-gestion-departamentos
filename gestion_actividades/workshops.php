<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/workshops.php'));
$type = optional_param('type', 'typea', PARAM_ALPHA);
$type = $type === 'typeb' ? 'typeb' : 'typea';
$typetitle = $type === 'typeb' ? 'Talleres Tipo B' : 'Talleres Tipo A';
$PAGE->set_title($typetitle);
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall mr-1']) . ' ' . $label;
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall mr-1']) . ' Volver al panel', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');

echo $OUTPUT->heading($typetitle);

echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php'), local_ga_btn_icon('i/calendar', 'Ediciones de talleres'), ['class' => 'btn btn-primary mr-1']) . ' ' .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php'), local_ga_btn_icon('t/add', 'Añadir taller manual a una edición'), ['class' => 'btn btn-outline-primary mr-1']) . ' ' .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php'), local_ga_btn_icon('t/upload', 'Crear edición desde Excel'), ['class' => 'btn btn-success mr-1']) . ' ' .
    html_writer::link(new moodle_url('/local/gestion_actividades/repair_course_visuals.php', ['sesskey' => sesskey()]), local_ga_btn_icon('t/reload', get_string('repaircoursevisuals', 'local_gestion_actividades')), ['class' => 'btn btn-secondary']),
    'mb-3'
);

echo html_writer::tag('p', 'Flujo único: primero se crea o selecciona una Edición de talleres. Después se añaden sus talleres manualmente o desde Excel. Ambos caminos usan los mismos datos, grupos, subsecciones y calendario.', ['class' => 'text-muted']);

$workshops = manager::list_workshops(0, $type);
$table = new html_table();
$table->head = [
    get_string('course'),
    get_string('workshopcode', 'local_gestion_actividades'),
    get_string('workshopname', 'local_gestion_actividades'),
    get_string('workshophours', 'local_gestion_actividades'),
    get_string('editions', 'local_gestion_actividades'),
    get_string('actions'),
];

foreach ($workshops as $w) {
    $course = $DB->get_record('course', ['id' => $w->courseid]);
    $editions = manager::list_workshop_editions($w->id);
    $editioncount = count($editions);
    if ($editioncount === 1) {
        $firstedition = reset($editions);
        $editurl = new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $firstedition->id, 'workshopid' => $w->id]);
    } else if ($editioncount === 0) {
        $editurl = new moodle_url('/local/gestion_actividades/edition_edit.php', ['workshopid' => $w->id]);
    } else {
        $editurl = new moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => $w->id]);
    }

    $actions = html_writer::link(
        $editurl,
        local_ga_btn_icon('t/edit', 'Configurar taller'),
        ['class' => 'btn btn-primary btn-sm']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/local/gestion_actividades/workshop_delete.php', ['id' => $w->id]),
        local_ga_btn_icon('t/delete', get_string('deleteworkshop', 'local_gestion_actividades')),
        ['class' => 'btn btn-danger btn-sm', 'title' => get_string('deleteworkshop', 'local_gestion_actividades'), 'aria-label' => get_string('deleteworkshop', 'local_gestion_actividades')]
    );

    $table->data[] = [
        $course ? format_string($course->fullname) : $w->courseid,
        s($w->code),
        format_string($w->name),
        isset($w->hours) && $w->hours !== null ? round($w->hours, 2) : '-',
        count($editions) > 0 ? count($editions) : 'Pendiente de configurar',
        $actions,
    ];
}
echo html_writer::table($table);
if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
