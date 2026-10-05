<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$view = optional_param('view', 'active', PARAM_ALPHA);
$view = $view === 'finished' ? 'finished' : 'active';
$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/my_workshops.php', ['view' => $view]));
$PAGE->set_title($view === 'finished' ? 'Profesor HEE · Mis talleres finalizados' : 'Profesor HEE · Mis talleres vigentes');
$PAGE->set_heading('Gestión HEE');

$dbman = $DB->get_manager();
foreach (['local_ga_edition_teachers', 'local_ga_workshop_editions', 'local_ga_workshops'] as $tablename) {
    if (!$dbman->table_exists(new xmldb_table($tablename))) {
        throw new moodle_exception('La estructura de Gestión HEE aún no está disponible.');
    }
}

$finishedsql = "(e.archived = 1 OR e.status IN ('archived','finished','completed','closed_finished'))";
$condition = $view === 'finished' ? $finishedsql : "NOT " . $finishedsql;
$sql = "SELECT e.id AS editionid, e.workshopid, e.editioncode, e.name AS editionname,
               e.sessiondate, e.status, e.archived, e.places,
               w.code AS workshopcode, w.name AS workshopname, w.workshoptype,
               c.id AS courseid, c.fullname AS coursename
          FROM {local_ga_edition_teachers} et
          JOIN {local_ga_workshop_editions} e ON e.id = et.editionid
          JOIN {local_ga_workshops} w ON w.id = e.workshopid
          JOIN {course} c ON c.id = w.courseid
         WHERE et.userid = :userid AND {$condition}
      ORDER BY e.sessiondate DESC, w.name ASC, e.id DESC";
$rows = $DB->get_records_sql($sql, ['userid' => (int)$USER->id]);

$totalassigned = $DB->count_records('local_ga_edition_teachers', ['userid' => (int)$USER->id]);
if (!$totalassigned && !manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'moodle/course:update', 'nopermissions', '');
}

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/my/'),
        $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver a mis cursos',
        ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);
echo $OUTPUT->heading($view === 'finished' ? 'Profesor HEE · Mis talleres finalizados' : 'Profesor HEE · Mis talleres vigentes');
echo html_writer::tag(
    'p',
    'Como Profesor HEE puedes gestionar únicamente las ediciones de talleres que te han sido asignadas: alumnado, asistencia, materiales y la actividad obligatoria o reflexión correspondiente. Este permiso no te concede edición general del curso Moodle.',
    ['class' => 'alert alert-info']
);

echo html_writer::start_div('mb-3');
echo html_writer::link(new moodle_url('/local/gestion_actividades/my_workshops.php', ['view' => 'active']),
    'Talleres vigentes', ['class' => 'btn ' . ($view === 'active' ? 'btn-primary' : 'btn-outline-secondary') . ' me-2']);
echo html_writer::link(new moodle_url('/local/gestion_actividades/my_workshops.php', ['view' => 'finished']),
    'Mis talleres finalizados', ['class' => 'btn ' . ($view === 'finished' ? 'btn-primary' : 'btn-outline-secondary')]);
echo html_writer::end_div();

if (!$rows) {
    echo $OUTPUT->notification($view === 'finished'
        ? 'No tienes talleres finalizados asignados como Profesor HEE.'
        : 'No tienes talleres vigentes asignados como Profesor HEE.', 'info');
} else {
    $table = new html_table();
    $table->head = ['Tipo', 'Código', 'Taller', 'Edición', 'Fecha', 'Curso', 'Acciones'];
    foreach ($rows as $row) {
        if (!manager::can_manage_edition((int)$row->editionid, (int)$USER->id)) {
            continue;
        }
        $type = manager::normalize_workshop_type((string)($row->workshoptype ?? 'typea')) === 'typeb' ? 'Tipo B' : 'Tipo A';
        $date = !empty($row->sessiondate) ? manager::format_date_compact((int)$row->sessiondate) : '-';
        $label = $view === 'finished' ? 'Revisar taller' : 'Gestionar taller';
        $actions = html_writer::link(
            new moodle_url('/local/gestion_actividades/teacher_view.php', [
                'id' => (int)$row->workshopid,
                'editionid' => (int)$row->editionid,
            ]),
            $label,
            ['class' => 'btn btn-primary btn-sm']
        );
        $table->data[] = [s($type), s($row->workshopcode), format_string($row->workshopname),
            s($row->editioncode ?: ('Edición ' . (int)$row->editionid)), $date,
            format_string($row->coursename), $actions];
    }
    echo html_writer::table($table);
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
