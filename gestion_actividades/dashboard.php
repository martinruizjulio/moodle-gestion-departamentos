<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
$canmanageplugin = manager::can_manage_globally((int)$USER->id);
$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/dashboard.php', $courseid > 0 ? ['courseid' => $courseid] : []));
$PAGE->set_title(get_string('dashboard', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall mr-1']) . ' ' . $label;
}

function local_ga_dash_card(string $title, string $text, moodle_url $url, string $button, string $classes = 'btn btn-primary'): string {
    return html_writer::start_div('col-md-4 col-xl-4 mb-3') .
        html_writer::start_div('card h-100 shadow-sm') .
        html_writer::start_div('card-body d-flex flex-column') .
        html_writer::tag('h3', $title, ['class' => 'h5 card-title']) .
        html_writer::tag('p', $text, ['class' => 'card-text text-muted flex-grow-1']) .
        html_writer::link($url, local_ga_btn_icon('t/go', $button), ['class' => $classes]) .
        html_writer::end_div() . html_writer::end_div() . html_writer::end_div();
}

function local_ga_dash_status_badge(string $status): string {
    $status = trim($status);
    $class = 'badge badge-secondary';
    if (in_array($status, ['open', 'active', 'published'], true)) {
        $class = 'badge badge-success';
    } else if (in_array($status, ['draft', 'pending', 'future'], true)) {
        $class = 'badge badge-warning';
    } else if (in_array($status, ['closed', 'completed'], true)) {
        $class = 'badge badge-info';
    }
    return html_writer::span(s($status), $class, ['style' => 'font-size:0.82rem;padding:6px 9px;']);
}

$allrows = [];
$rows = [];
try {
    $allrows = manager::get_workshop_overview_rows();
    foreach ($allrows as $r) {
        if (($r->computedstatus ?? '') === 'archived') {
            continue;
        }
        if ($courseid > 0 && (int)($r->courseid ?? 0) !== $courseid) {
            continue;
        }
        $teacherids = $r->teacherids ?? [];
        $coursecontext = !empty($r->courseid) ? context_course::instance((int)$r->courseid, IGNORE_MISSING) : null;
        $courseeditor = $coursecontext && has_capability('moodle/course:update', $coursecontext, (int)$USER->id);
        if ($canmanageplugin || in_array((int)$USER->id, $teacherids, true) || ($courseeditor && empty($teacherids))) {
            $rows[] = $r;
        }
    }
} catch (Throwable $e) {
    $rows = [];
}

if (!$canmanageplugin) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$authorized = [];
try {
    $authorized = manager::get_authorized_managers();
} catch (Throwable $e) {
    $authorized = [];
}

$returncourseid = $courseid;
if ($returncourseid <= 0 && !empty($rows)) {
    $firstrow = reset($rows);
    $returncourseid = !empty($firstrow->courseid) ? (int)$firstrow->courseid : 0;
}
if ($returncourseid <= 0) {
    $fw = $DB->get_record_sql("SELECT courseid FROM {local_ga_workshops} ORDER BY id DESC", [], IGNORE_MULTIPLE);
    $returncourseid = $fw ? (int)$fw->courseid : 0;
}
$returncourse = null;
if ($returncourseid > 0) {
    $returncourse = $DB->get_record('course', ['id' => $returncourseid], 'id,fullname,shortname', IGNORE_MISSING);
}

echo $OUTPUT->header();

$returnurl = $returncourse
    ? (new moodle_url('/course/view.php', ['id' => $returncourse->id]))->out(false)
    : 'javascript:history.back();';

echo html_writer::div(
    html_writer::link(
        $returnurl,
        local_ga_btn_icon('t/left', 'Volver al curso'),
        ['class' => 'btn btn-outline-secondary mb-3']
    ),
    'mb-2'
);

echo html_writer::tag('h3', 'Gestión y alumnos', ['class' => 'h4 mt-3 mb-3']);
echo html_writer::start_div('row');
echo local_ga_dash_card('1. Usuarios autorizados', 'Gestionar qué usuarios pueden acceder a la administración de Gestión HEE.', new moodle_url('/local/gestion_actividades/authorized_users.php', $courseid > 0 ? ['courseid' => $courseid] : []), 'Gestionar usuarios autorizados', 'btn btn-secondary');
echo local_ga_dash_card('2. Alumnos y notas de expediente', 'Importación de alumnos, notas de expediente, ranking y convocatorias antiguas.', new moodle_url('/local/gestion_actividades/index.php'), 'Abrir alumnos y ranking');
echo local_ga_dash_card('3. Listados y descargas', 'Descargar listados de talleres, certificados Tipo A/B, horas, portafolios y expedientes completos.', new moodle_url('/local/gestion_actividades/manager_downloads.php'), 'Abrir listados y descargas', 'btn btn-primary');
echo local_ga_dash_card('4. Reconocimiento institucional', 'Importar horas Tipo A y Tipo B reconocidas previamente por el Decanato desde Excel.', new moodle_url('/local/gestion_actividades/institutional_import.php'), 'Importar reconocimiento', 'btn btn-info');
echo local_ga_dash_card('5. Traspasos A→B', 'Consultar los traspasos de horas Tipo A a Tipo B realizados por el alumnado.', new moodle_url('/local/gestion_actividades/manager_downloads.php', ['view' => 'view_transfers']), 'Ver traspasos', 'btn btn-secondary');
echo local_ga_dash_card('6. Notas Asignatura HEE', 'Consultar Nota Talleres A, Portafolio, Autoevaluación y Nota Final, con descarga en Excel y PDF.', new moodle_url('/local/gestion_actividades/grades_report.php', $courseid > 0 ? ['courseid' => $courseid] : []), 'Abrir notas de alumnos', 'btn btn-primary');
echo local_ga_dash_card('7. Validación externa Tipo B', 'Revisar certificados de formación externa enviados por alumnos y validarlos o rechazarlos como Tipo B.', new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['status' => 'pending']), 'Revisar solicitudes Tipo B', 'btn btn-warning');
echo local_ga_dash_card('8. Listado personalizado de talleres', 'Seleccionar uno, varios o todos los talleres y obtener nombre, horas, nota de tarea y asistencia.', new moodle_url('/local/gestion_actividades/workshop_report.php'), 'Crear listado de talleres', 'btn btn-primary');
echo html_writer::end_div();

echo html_writer::tag('h3', 'Talleres', ['class' => 'h4 mt-4 mb-3']);
echo html_writer::start_div('row');
echo local_ga_dash_card('4. Talleres Tipo A', 'Crear talleres, ediciones, plazas, profesorado, grupos, asistencia, tareas, notas y certificados automáticos.', new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typea']), 'Gestionar talleres Tipo A');
echo local_ga_dash_card('5. Talleres Tipo B', 'Crear talleres Tipo B con inscripción, asistencia, texto obligatorio del alumno y certificado.', new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typeb']), 'Gestionar talleres Tipo B', 'btn btn-primary');
echo local_ga_dash_card('6. Talleres archivados', 'Consultar talleres y ediciones finalizadas o archivadas, distinguiendo entre Tipo A y Tipo B.', new moodle_url('/local/gestion_actividades/archive.php'), 'Abrir talleres archivados', 'btn btn-secondary');
echo html_writer::end_div();

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', 'Vista general de talleres ofertados actualmente', ['class' => 'h4']);
echo html_writer::tag('p', 'Vista rápida de talleres y ediciones activos. Los talleres finalizados o archivados se consultan en la pestaña Talleres archivados.', ['class' => 'text-muted']);

if (!$rows) {
    echo $OUTPUT->notification('Todavía no hay talleres o ediciones visibles para este usuario.', 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = [
        get_string('status'),
        get_string('workshopcode', 'local_gestion_actividades'),
        get_string('workshopname', 'local_gestion_actividades'),
        get_string('editioncode', 'local_gestion_actividades'),
        get_string('workshophours', 'local_gestion_actividades'),
        get_string('date'),
        get_string('places', 'local_gestion_actividades'),
        get_string('enrolledstudents', 'local_gestion_actividades'),
        get_string('teachers', 'local_gestion_actividades'),
        get_string('actions'),
    ];

    foreach ($rows as $row) {
        $workshopid = (int)($row->workshopid ?? 0);
        $statuslabel = get_string('status_' . $row->computedstatus, 'local_gestion_actividades');
        $actions = html_writer::link(new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $row->id, 'workshopid' => $workshopid]), local_ga_btn_icon('t/edit', get_string('edit')), ['class' => 'btn btn-secondary btn-sm']) . ' ' .
                   html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $row->id]), local_ga_btn_icon('i/users', get_string('studentsmanualandstatus', 'local_gestion_actividades')), ['class' => 'btn btn-secondary btn-sm']);
        if ($canmanageplugin) {
            $actions .= ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/edition_sync.php', ['id' => $row->id]), local_ga_btn_icon('t/reload', get_string('synceditionenrolments', 'local_gestion_actividades')), ['class' => 'btn btn-outline-secondary btn-sm']);
        }
        $table->data[] = [
            local_ga_dash_status_badge($statuslabel),
            s($row->workshopcode),
            format_string($row->workshopname),
            s($row->editioncode),
            isset($row->workshophours) && $row->workshophours !== null ? round($row->workshophours, 2) . ' h' : '-',
            $row->sessiondate ? manager::format_date_compact((int)$row->sessiondate) : '-',
            (int)$row->places,
            (int)$row->enrolledcount,
            $row->teachers ?: '-',
            $actions,
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::end_div();
echo html_writer::end_div();

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
