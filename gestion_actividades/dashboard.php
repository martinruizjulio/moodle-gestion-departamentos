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
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_dash_card(string $title, string $text, moodle_url $url, string $button, string $classes = 'btn btn-primary'): string {
    return html_writer::start_div('col-md-4 col-xl-4 mb-3')
        . html_writer::start_div('card h-100 shadow-sm')
        . html_writer::start_div('card-body d-flex flex-column')
        . html_writer::tag('h3', $title, ['class' => 'h5 card-title'])
        . html_writer::tag('p', $text, ['class' => 'card-text text-muted flex-grow-1'])
        . html_writer::link($url, local_ga_btn_icon('t/go', $button), ['class' => $classes])
        . html_writer::end_div()
        . html_writer::end_div()
        . html_writer::end_div();
}

function local_ga_dash_series_edition(stdClass $series, int $workshopid): ?stdClass {
    $editions = manager::list_workshop_editions($workshopid);
    if (!$editions) {
        return null;
    }

    foreach ($editions as $edition) {
        if (isset($edition->seriesid) && (int)$edition->seriesid === (int)$series->id) {
            return $edition;
        }
    }

    $matching = [];
    foreach ($editions as $edition) {
        if (!empty($edition->seriesid) && (int)$edition->seriesid !== (int)$series->id) {
            continue; // Linked to another Edición de talleres: never borrow it by dates.
        }
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
    $primary = manager::get_primary_workshop_edition($workshopid);
    if ($primary && !empty($primary->seriesid) && (int)$primary->seriesid !== (int)$series->id) {
        return null;
    }
    return $primary;
}

function local_ga_dash_type_badge(?string $type): string {
    $type = manager::normalize_workshop_type((string)($type ?? 'typea'));
    return html_writer::span(
        $type === 'typeb' ? 'Tipo B' : 'Tipo A',
        $type === 'typeb' ? 'badge bg-primary' : 'badge bg-success'
    );
}

function local_ga_dash_workshop_status(?stdClass $edition, ?stdClass $series = null): string {
    // A finished Edición closes all its talleres, whatever their own status.
    if ($series && (string)($series->status ?? '') === 'finished') {
        return html_writer::span('Finalizado', 'badge bg-secondary');
    }
    if (!$edition) {
        return html_writer::span('Pendiente de configurar', 'badge bg-warning text-dark');
    }
    $status = (string)($edition->status ?? '');
    if (in_array($status, ['open', 'active', 'published', 'closed_full'], true)) {
        return html_writer::span($status === 'closed_full' ? 'Completo' : 'Abierto', 'badge bg-success');
    }
    if (in_array($status, ['closed', 'completed', 'finished', 'closed_finished'], true)) {
        return html_writer::span('Cerrado', 'badge bg-secondary');
    }
    return html_writer::span($status !== '' ? s($status) : 'Configurado', 'badge bg-info text-dark');
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

// Preserve the Moodle course from which the manager entered Gestión HEE.
// Several management pages can return to dashboard.php without carrying the
// courseid parameter; using "the latest workshop course" as a fallback could
// therefore send "Volver al curso" to the wrong place (and fails completely
// after a test purge removes all workshop rows).
if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
    $SESSION->local_ga_return_courseid = $courseid;
}

$returncourseid = $courseid;
// Without ?courseid, prefer the course page the user just came from.
if ($returncourseid <= 0) {
    $referer = get_local_referer(false);
    if ($referer && preg_match('~/course/view\.php\?(?:[^#]*&)?id=(\d+)~', $referer, $m)
            && $DB->record_exists('course', ['id' => (int)$m[1]]) && (int)$m[1] !== (int)SITEID) {
        $returncourseid = (int)$m[1];
        $SESSION->local_ga_return_courseid = $returncourseid;
    }
}
if ($returncourseid <= 0 && !empty($SESSION->local_ga_return_courseid)) {
    $candidatecourseid = (int)$SESSION->local_ga_return_courseid;
    if ($DB->record_exists('course', ['id' => $candidatecourseid])) {
        $returncourseid = $candidatecourseid;
    }
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
echo local_ga_dash_card(
    '1. Usuarios autorizados',
    'Gestionar Gestores HEE globales y asignar Profesores HEE únicamente a las ediciones concretas que deben gestionar.',
    new moodle_url('/local/gestion_actividades/authorized_users.php', $courseid > 0 ? ['courseid' => $courseid] : []),
    'Gestionar usuarios autorizados',
    'btn btn-secondary'
);
echo local_ga_dash_card(
    '2. Alumnos y notas de expediente',
    'Cotejar cuentas institucionales existentes, gestionar listados de alumnos y mantener las notas de expediente y convocatorias históricas.',
    new moodle_url('/local/gestion_actividades/index.php'),
    'Abrir alumnos y ranking'
);
echo local_ga_dash_card(
    '3. Listados y descargas',
    'Consultar y descargar talleres Tipo A/B, asistencia, resultados, certificados, horas, portafolios y expedientes completos.',
    new moodle_url('/local/gestion_actividades/manager_downloads.php'),
    'Abrir listados y descargas',
    'btn btn-primary'
);
echo local_ga_dash_card(
    '4. Reconocimiento institucional',
    'Importar desde Excel horas Tipo A y Tipo B reconocidas previamente por el Decanato. Tipo B exige después la reflexión del alumno.',
    new moodle_url('/local/gestion_actividades/institutional_import.php'),
    'Importar reconocimiento',
    'btn btn-info'
);
echo local_ga_dash_card(
    '5. Traspasos A→B',
    'Consultar los traspasos de horas Tipo A a Tipo B realizados por el alumnado; el total reconocido no cambia.',
    new moodle_url('/local/gestion_actividades/manager_downloads.php', ['view' => 'view_transfers']),
    'Ver traspasos',
    'btn btn-secondary'
);
echo local_ga_dash_card(
    '6. Notas Asignatura HEE',
    'Consultar Nota Talleres A, Portafolio, Autoevaluación y Nota Final. También permite crear o vincular la autoevaluación que se desbloquea a las 54 horas.',
    new moodle_url('/local/gestion_actividades/grades_report.php', $courseid > 0 ? ['courseid' => $courseid] : []),
    'Abrir notas de alumnos',
    'btn btn-primary'
);
echo local_ga_dash_card(
    '7. Validación externa Tipo B',
    'Revisar certificados externos. Tras la validación, el alumno completa la reflexión; solo entonces las horas computan como Tipo B.',
    new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['status' => 'pending']),
    'Revisar solicitudes Tipo B',
    'btn btn-warning'
);
echo local_ga_dash_card(
    '8. Listado personalizado de talleres',
    'Seleccionar uno, varios o todos los talleres (también pasados) y obtener apellidos, nombre, correo, taller, Edición, horas, calificación, asistencia y resultado. Descargable en CSV.',
    new moodle_url('/local/gestion_actividades/workshop_report.php'),
    'Crear listado de talleres',
    'btn btn-primary'
);
echo html_writer::end_div();

echo html_writer::tag('h3', 'Talleres', ['class' => 'h4 mt-4 mb-3']);
echo html_writer::start_div('row');
echo local_ga_dash_card(
    'Talleres Tipo A',
    'Gestionar ediciones con su calendario y subsecciones. La actividad predeterminada de los talleres Tipo A nuevos es un cuestionario Moodle; se mantienen compatibles las ediciones históricas.',
    new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typea']),
    'Gestionar talleres Tipo A'
);
echo local_ga_dash_card(
    'Talleres Tipo B',
    'Gestionar ediciones con calendario y subsecciones, inscripción, asistencia y Tarea Moodle de reflexión como actividad canónica.',
    new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typeb']),
    'Gestionar talleres Tipo B',
    'btn btn-primary'
);
echo local_ga_dash_card(
    'Ediciones anteriores',
    'Consultar las ediciones finalizadas u ocultas que se conservan como histórico por debajo de las ediciones activas del curso.',
    new moodle_url('/local/gestion_actividades/archive.php'),
    'Abrir ediciones anteriores',
    'btn btn-secondary'
);
echo html_writer::end_div();

// Templates to create Ediciones from Excel, always at hand.
$tplurl = fn(string $t) => new moodle_url('/local/gestion_actividades/workshop_bulk_import.php',
    ['action' => 'template', 'templatetype' => $t, 'sesskey' => sesskey()]);
echo html_writer::start_div('row');
echo html_writer::start_div('col-12 mb-3');
echo html_writer::start_div('card shadow-sm');
echo html_writer::start_div('card-body d-flex flex-wrap align-items-center gap-2');
echo html_writer::div(html_writer::tag('h3', 'Plantillas de talleres', ['class' => 'h5 card-title mb-1'])
    . html_writer::tag('p', 'Excel para crear una Edición completa. Una plantilla por tipo; admite talleres de uno o dos días.',
        ['class' => 'card-text text-muted mb-0']), 'me-auto');
echo html_writer::link($tplurl('typea'), local_ga_btn_icon('t/download', 'Plantilla_Talleres_A'), ['class' => 'btn btn-primary']);
echo html_writer::link($tplurl('typeb'), local_ga_btn_icon('t/download', 'Plantilla_Talleres_B'),
    ['class' => 'btn', 'style' => 'background:#7a3e9d;border-color:#7a3e9d;color:#fff']);
echo html_writer::link(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php', $courseid > 0 ? ['courseid' => $courseid] : []),
    local_ga_btn_icon('i/import', 'Crear Edición desde Excel'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::tag('h3', 'Vista general de talleres ofertados actualmente', ['class' => 'h4 mt-4']);
echo html_writer::tag(
    'p',
    'Las ediciones activas se muestran de la más reciente a la más antigua. Dentro de cada edición aparecen sus talleres en el orden visible Taller 01, Taller 02, etc.',
    ['class' => 'text-muted']
);

$serieslist = workshop_series::list_for_course($courseid);
$alllinkedworkshops = [];
foreach ($serieslist as $allseries) {
    foreach (workshop_series::items((int)$allseries->id) as $allitem) {
        $alllinkedworkshops[(int)$allitem->workshopid] = true;
    }
}

$activecount = 0;
foreach ($serieslist as $series) {
    $isactive = ($series->status ?? '') !== 'finished';
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
    echo html_writer::start_div('me-3');
    echo html_writer::tag('h4', s($series->title), ['class' => 'h5 mb-1']);
    echo html_writer::tag(
        'div',
        ($course ? format_string($course->fullname) : 'Curso #' . (int)$series->courseid)
            . ' · ' . userdate((int)$series->datefrom, '%d/%m/%Y')
            . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y'),
        ['class' => 'text-muted']
    );
    echo html_writer::end_div();
    echo html_writer::div(
        html_writer::link(
            local_ga_dash_course_series_url($series),
            local_ga_btn_icon('i/course', 'Abrir en el curso'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        ),
        'mb-1'
    );
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('card-body p-0');
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm mb-0';
    $table->head = ['Orden', 'Tipo', 'Taller', 'Fecha', 'Horario', 'Horas', 'Plazas', 'Inscritos', 'Profesor/es', 'Estado', 'Acciones'];
    foreach ($items as $item) {
        $edition = local_ga_dash_series_edition($series, (int)$item->workshopid);
        $sessiondate = $edition ? (int)($edition->sessiondate ?? 0) : 0;
        $sessionend = (int)($item->sessionenddate ?? 0);
        $teachers = $edition ? manager::get_edition_teachers((int)$edition->id) : [];
        $teachernames = [];
        foreach ($teachers as $teacher) {
            $teachernames[] = fullname($teacher);
        }
        [$datetext, $schedule] = \local_gestion_actividades\local\workshop_series::schedule_parts($sessiondate, $item);
        $datetext = nl2br(s($datetext));
        $schedule = nl2br(s($schedule));
        $actions = '-';
        if ($edition) {
            $actions = html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_edit.php', [
                    'id' => (int)$edition->id,
                    'workshopid' => (int)$item->workshopid,
                    'seriesid' => (int)$series->id,
                    'sortorder' => (int)$item->sortorder,
                ]),
                local_ga_btn_icon('t/edit', 'Editar'),
                ['class' => 'btn btn-secondary btn-sm me-1 mb-1']
            );
            $actions .= html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$edition->id]),
                local_ga_btn_icon('i/users', 'Alumnos / asistencia'),
                ['class' => 'btn btn-secondary btn-sm me-1 mb-1']
            );
            $actions .= html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_sync.php', ['id' => (int)$edition->id]),
                local_ga_btn_icon('t/reload', 'Sincronizar'),
                ['class' => 'btn btn-outline-secondary btn-sm mb-1']
            );
        }
        $table->data[] = [
            'TALLER ' . sprintf('%02d', (int)$item->sortorder),
            local_ga_dash_type_badge($item->workshoptype ?? 'typea'),
            format_string($item->name),
            $datetext,
            $schedule,
            format_float((float)($item->hours ?? 0), 2, true) . ' h',
            $edition && (int)($edition->places ?? 0) > 0 ? (int)$edition->places : '-',
            $edition ? manager::get_edition_enrolment_count((int)$edition->id) : '-',
            $teachernames ? s(implode(', ', $teachernames)) : '-',
            local_ga_dash_workshop_status($edition, $series),
            $actions,
        ];
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

$legacyrows = [];
foreach (manager::get_workshop_overview_rows() as $row) {
    $workshopid = (int)($row->workshopid ?? 0);
    if (!empty($alllinkedworkshops[$workshopid]) || ($row->computedstatus ?? '') === 'archived') {
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
        $actions = html_writer::link(
            new moodle_url('/local/gestion_actividades/edition_edit.php', [
                'id' => (int)$row->id,
                'workshopid' => (int)$row->workshopid,
            ]),
            local_ga_btn_icon('t/edit', 'Editar'),
            ['class' => 'btn btn-secondary btn-sm me-1']
        );
        $actions .= html_writer::link(
            new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$row->id]),
            local_ga_btn_icon('i/users', 'Alumnos / asistencia'),
            ['class' => 'btn btn-secondary btn-sm']
        );
        $table->data[] = [
            local_ga_dash_type_badge($row->workshoptype ?? 'typea'),
            s($row->workshopcode ?? ''),
            format_string($row->workshopname ?? ''),
            !empty($row->sessiondate) ? manager::format_date_compact((int)$row->sessiondate) : '-',
            isset($row->workshophours) && $row->workshophours !== null
                ? format_float((float)$row->workshophours, 2, true) . ' h'
                : '-',
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
