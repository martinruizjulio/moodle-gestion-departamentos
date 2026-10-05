<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;

require_login();
$context = context_system::instance();
$canmanageplugin = manager::can_manage_globally((int)$USER->id);
$courseid = optional_param('courseid', 0, PARAM_INT);

if (!$canmanageplugin) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

workshop_series::ensure_schema();

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

function local_ga_dash_series_edition(stdClass $series, int $workshopid): ?stdClass {
    $editions = manager::list_workshop_editions($workshopid);
    if (!$editions) {
        return null;
    }
    $matching = [];
    foreach ($editions as $edition) {
        $date = (int)($edition->sessiondate ?? 0);
        if ($date > 0 && $date >= (int)$series->datefrom && $date <= (int)$series->dateto) {
            $matching[] = $edition;
        }
    }
    if ($matching) {
        usort($matching, static function($a, $b) {
            $datecmp = ((int)($b->sessiondate ?? 0)) <=> ((int)($a->sessiondate ?? 0));
            return $datecmp !== 0 ? $datecmp : ((int)$b->id <=> (int)$a->id);
        });
        return reset($matching);
    }
    return manager::get_primary_workshop_edition($workshopid);
}

function local_ga_dash_type_badge(?string $type): string {
    $type = manager::normalize_workshop_type((string)($type ?? 'typea'));
    return html_writer::span($type === 'typeb' ? 'Tipo B' : 'Tipo A', $type === 'typeb' ? 'badge badge-primary' : 'badge badge-success');
}

function local_ga_dash_workshop_status(?stdClass $edition): string {
    if (!$edition) {
        return html_writer::span('Pendiente de configurar', 'badge badge-warning');
    }
    $status = (string)($edition->status ?? '');
    if (in_array($status, ['open', 'active', 'published'], true)) {
        return html_writer::span('Abierto', 'badge badge-success');
    }
    if (in_array($status, ['closed', 'completed', 'finished', 'closed_finished'], true)) {
        return html_writer::span('Cerrado', 'badge badge-secondary');
    }
    return html_writer::span($status !== '' ? s($status) : 'Configurado', 'badge badge-info');
}

function local_ga_dash_course_series_url(stdClass $series): moodle_url {
    global $DB;
    $params = ['id' => (int)$series->courseid];
    if (!empty($series->sectionid)) {
        $section = $DB->get_record('course_sections', ['id' => (int)$series->sectionid], 'id,section', IGNORE_MISSING);
        if ($section) {
            $params['section'] = (int)$section->section;
        }
    }
    return new moodle_url('/course/view.php', $params);
}

$returncourseid = $courseid;
if ($returncourseid <= 0) {
    $fw = $DB->get_record_sql("SELECT courseid FROM {local_ga_workshops} ORDER BY id DESC", [], IGNORE_MULTIPLE);
    $returncourseid = $fw ? (int)$fw->courseid : 0;
}
$returncourse = $returncourseid > 0
    ? $DB->get_record('course', ['id' => $returncourseid], 'id,fullname,shortname', IGNORE_MISSING)
    : null;

echo $OUTPUT->header();

$returnurl = $returncourse
    ? (new moodle_url('/course/view.php', ['id' => $returncourse->id]))->out(false)
    : 'javascript:history.back();';

echo html_writer::div(
    html_writer::link($returnurl, local_ga_btn_icon('t/left', 'Volver al curso'), ['class' => 'btn btn-outline-secondary mb-3']),
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
echo local_ga_dash_card('6. Talleres archivados', 'Consultar ediciones finalizadas u ocultas y sus talleres, distinguiendo entre Tipo A y Tipo B.', new moodle_url('/local/gestion_actividades/archive.php'), 'Abrir talleres archivados', 'btn btn-secondary');
echo html_writer::end_div();

echo html_writer::tag('h3', 'Vista general de talleres ofertados actualmente', ['class' => 'h4 mt-4']);
echo html_writer::tag('p', 'Las ediciones activas se muestran de la más reciente a la más antigua. Dentro de cada edición aparecen sus talleres en el orden visible Taller 01, Taller 02, etc.', ['class' => 'text-muted']);

$activecount = 0;
$linkedworkshops = [];
foreach (workshop_series::list_for_course($courseid) as $series) {
    $isactive = ($series->status ?? '') !== 'finished' && ((int)$series->dateto <= 0 || (int)$series->dateto >= time());
    if (!$isactive) {
        continue;
    }
    $items = workshop_series::items((int)$series->id);
    if (!$items) {
        continue;
    }
    $activecount++;
    $course = $DB->get_record('course', ['id' => (int)$series->courseid], 'id,fullname', IGNORE_MISSING);

    echo html_writer::start_div('card shadow-sm mb-4');
    echo html_writer::start_div('card-header bg-light');
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start');
    echo html_writer::start_div('mr-3');
    echo html_writer::tag('h4', s($series->title), ['class' => 'h5 mb-1']);
    echo html_writer::tag('div', ($course ? format_string($course->fullname) : 'Curso #' . (int)$series->courseid) . ' · ' . userdate((int)$series->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y'), ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::div(
        html_writer::link(local_ga_dash_course_series_url($series), local_ga_btn_icon('i/course', 'Abrir en el curso'), ['class' => 'btn btn-sm btn-outline-secondary']),
        'mb-1'
    );
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('card-body p-0');
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm mb-0';
    $table->head = ['Orden', 'Tipo', 'Taller', 'Fecha', 'Horario', 'Horas', 'Plazas', 'Inscritos', 'Profesor/es', 'Estado', 'Acciones'];

    foreach ($items as $item) {
        $linkedworkshops[(int)$item->workshopid] = true;
        $edition = local_ga_dash_series_edition($series, (int)$item->workshopid);
        $sessiondate = $edition ? (int)($edition->sessiondate ?? 0) : 0;
        $sessionend = (int)($item->sessionenddate ?? 0);
        $teachers = $edition ? manager::get_edition_teachers((int)$edition->id) : [];
        $teachernames = [];
        foreach ($teachers as $teacher) {
            $teachernames[] = fullname($teacher);
        }
        $schedule = $sessiondate > 0 ? userdate($sessiondate, '%H:%M') : '-';
        if ($sessiondate > 0 && $sessionend > 0) {
            $schedule .= '–' . userdate($sessionend, '%H:%M');
        }
        $actions = '-';
        if ($edition) {
            $actions = html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => (int)$edition->id, 'workshopid' => (int)$item->workshopid, 'seriesid' => (int)$series->id, 'sortorder' => (int)$item->sortorder]),
                local_ga_btn_icon('t/edit', 'Editar'),
                ['class' => 'btn btn-secondary btn-sm mr-1 mb-1']
            );
            $actions .= html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$edition->id]),
                local_ga_btn_icon('i/users', 'Alumnos / asistencia'),
                ['class' => 'btn btn-secondary btn-sm mr-1 mb-1']
            );
            $actions .= html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_sync.php', ['id' => (int)$edition->id]),
                local_ga_btn_icon('t/reload', 'Sincronizar'),
                ['class' => 'btn btn-outline-secondary btn-sm mb-1']
            );
        }
        $table->data[] = [
            'Taller ' . sprintf('%02d', (int)$item->sortorder),
            local_ga_dash_type_badge($item->workshoptype ?? 'typea'),
            format_string($item->name),
            $sessiondate > 0 ? userdate($sessiondate, '%d/%m/%Y') : '-',
            $schedule,
            format_float((float)($item->hours ?? 0), 2, true) . ' h',
            $edition && (int)($edition->places ?? 0) > 0 ? (int)$edition->places : '-',
            $edition ? manager::get_edition_enrolment_count((int)$edition->id) : '-',
            $teachernames ? s(implode(', ', $teachernames)) : '-',
            local_ga_dash_workshop_status($edition),
            $actions,
        ];
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Legacy active workshops remain visible until they are linked to a workshop series.
$legacyrows = [];
foreach (manager::get_workshop_overview_rows() as $row) {
    $workshopid = (int)($row->workshopid ?? 0);
    if (!empty($linkedworkshops[$workshopid]) || ($row->computedstatus ?? '') === 'archived') {
        continue;
    }
    if ($courseid > 0 && (int)($row->courseid ?? 0) !== $courseid) {
        continue;
    }
    $legacyrows[] = $row;
}
if ($legacyrows) {
    echo html_writer::tag('h4', 'Talleres anteriores sin Edición de talleres', ['class' => 'h5 mt-3']);
    echo html_writer::tag('p', 'Se mantienen aquí temporalmente para no perder registros del modelo anterior.', ['class' => 'text-muted']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Tipo', 'Código', 'Taller', 'Fecha', 'Horas', 'Plazas', 'Inscritos', 'Profesor/es', 'Acciones'];
    foreach ($legacyrows as $row) {
        $actions = html_writer::link(new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => (int)$row->id, 'workshopid' => (int)$row->workshopid]), local_ga_btn_icon('t/edit', 'Editar'), ['class' => 'btn btn-secondary btn-sm mr-1']);
        $actions .= html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$row->id]), local_ga_btn_icon('i/users', 'Alumnos / asistencia'), ['class' => 'btn btn-secondary btn-sm']);
        $table->data[] = [
            local_ga_dash_type_badge($row->workshoptype ?? 'typea'),
            s($row->workshopcode ?? ''),
            format_string($row->workshopname ?? ''),
            !empty($row->sessiondate) ? manager::format_date_compact((int)$row->sessiondate) : '-',
            isset($row->workshophours) && $row->workshophours !== null ? format_float((float)$row->workshophours, 2, true) . ' h' : '-',
            (int)($row->places ?? 0),
            (int)($row->enrolledcount ?? 0),
            $row->teachers ?: '-',
            $actions,
        ];
    }
    echo html_writer::table($table);
}

if ($activecount === 0 && !$legacyrows) {
    echo $OUTPUT->notification('No hay actualmente Ediciones de talleres activas.', 'info');
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
