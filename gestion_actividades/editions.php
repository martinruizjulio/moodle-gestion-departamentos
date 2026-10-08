<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();

$workshopid = required_param('workshopid', PARAM_INT);
$workshop = manager::get_workshop($workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$context = context_course::instance((int)$course->id);

// Structural edition administration is deliberately global-manager only.
// Professor HEE manages the concrete editions assigned to them from my_workshops.php.
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => $workshopid]));
$PAGE->set_title(get_string('editions', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('editions', 'local_gestion_actividades') . ': ' . s($workshop->code) . ' - ' . format_string($workshop->name));
if ($course) { echo html_writer::tag('p', get_string('coursewherecreated', 'local_gestion_actividades') . ': ' . html_writer::tag('strong', format_string($course->fullname) . ' [' . s($course->shortname) . '] — ID ' . $course->id), ['class' => 'alert alert-info']); }

echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/edition_edit.php', ['workshopid' => $workshopid]), get_string('newedition', 'local_gestion_actividades'), ['class' => 'btn btn-primary']) . ' ' .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php'), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']),
    'mb-3'
);

$table = new html_table();
$table->head = [
    get_string('editioncode', 'local_gestion_actividades'),
    get_string('name'),
    get_string('date'),
    get_string('enrolenddate', 'local_gestion_actividades'),
    get_string('workshophours', 'local_gestion_actividades'),
    get_string('places', 'local_gestion_actividades'),
    get_string('teachers', 'local_gestion_actividades'),
    get_string('status'),
    get_string('actions'),
];

foreach (manager::list_workshop_editions($workshopid) as $e) {
    $group = $e->groupid ? $DB->get_record('groups', ['id' => $e->groupid]) : null;
    $teachers = manager::get_edition_teachers($e->id);
    $tnames = [];
    foreach ($teachers as $t) {
        $tnames[] = fullname($t);
    }
    $members = $group ? (int)$DB->count_records('groups_members', ['groupid' => $group->id]) : 0;
    $placeslabel = (int)$e->places . ' · ' . $members . ' inscritos';
    $hourslabel = isset($workshop->hours) && $workshop->hours !== null
        ? format_float((float)$workshop->hours, 2, true) . ' h'
        : '-';
    $actions = html_writer::link(new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $e->id, 'workshopid' => $workshopid]), $OUTPUT->pix_icon('t/edit', get_string('edit')), ['class' => 'btn btn-secondary btn-sm', 'title' => get_string('edit')]) . ' ' .
        html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshopid, 'editionid' => $e->id]), $OUTPUT->pix_icon('i/grades', 'Gestionar notas'), ['class' => 'btn btn-primary btn-sm', 'title' => 'Gestionar asistencia y notas de esta edición']) . ' ' .
        html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $e->id]), $OUTPUT->pix_icon('i/users', get_string('studentsmanualandstatus', 'local_gestion_actividades')), ['class' => 'btn btn-secondary btn-sm', 'title' => get_string('studentsmanualandstatus', 'local_gestion_actividades')]) . ' ' .
        html_writer::link(new moodle_url('/local/gestion_actividades/edition_delete.php', ['id' => $e->id]), $OUTPUT->pix_icon('t/delete', get_string('deleteedition', 'local_gestion_actividades')), ['class' => 'btn btn-danger btn-sm', 'title' => get_string('deleteedition', 'local_gestion_actividades')]);
    $table->data[] = [
        s($e->editioncode),
        format_string($e->name),
        $e->sessiondate ? userdate($e->sessiondate) : '-',
        $e->enrolenddate ? userdate($e->enrolenddate) : '-',
        $hourslabel,
        s($placeslabel),
        $tnames ? implode(', ', array_map('s', $tnames)) : '-',
        s($e->status),
        $actions,
    ];
}
echo html_writer::table($table);
if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
