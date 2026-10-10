<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;
use local_gestion_actividades\local\academic_year;

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
    html_writer::link($returnurl, local_ga_btn_icon('t/left', 'Volver al curso'), ['class' => 'btn local-ga-back-course mb-3']),
    'mb-2'
);
echo html_writer::div(
    html_writer::span('Curso académico actual: ' . html_writer::tag('strong', s(academic_year::current())), 'me-2')
    . html_writer::span('Cambia automáticamente el 1 de septiembre. Cada Edición de talleres guarda su curso (se puede cambiar en «Editar edición») y los listados filtran por curso.', 'text-muted small'),
    'mb-3 p-2 border rounded bg-light');

// ---------------------------------------------------------------------------
// Portada simplificada: «Pendiente» + 4 bloques por tarea (mismas opciones).
// ---------------------------------------------------------------------------
$cparams = $courseid > 0 ? ['courseid' => $courseid] : [];
$now = time();

// Pending: Tipo B requests to validate.
$pendingtypeb = 0;
try {
    $pendingtypeb = \local_gestion_actividades\local\portfolio_typeb::count_pending();
} catch (Throwable $e) {
    $pendingtypeb = 0;
}
// Pending: talleres already held (with students) whose attendance list has a
// session not taken yet; and Ediciones whose end date passed but not finished.
$untaken = [];
$overdue = [];
foreach (workshop_series::list_for_course($courseid) as $ps) {
    if ((string)($ps->status ?? '') === 'finished') {
        continue;
    }
    if (!empty($ps->dateto) && (int)$ps->dateto < $now) {
        $overdue[] = $ps;
    }
    foreach (workshop_series::items((int)$ps->id) as $pitem) {
        $ped = $DB->get_record('local_ga_workshop_editions', ['seriesid' => (int)$ps->id, 'workshopid' => (int)$pitem->workshopid],
            'id, attendancecmid, groupid, sessiondate', IGNORE_MULTIPLE);
        if (!$ped || empty($ped->attendancecmid) || workshop_series::last_end($pitem) >= $now
                || manager::get_edition_enrolment_count((int)$ped->id) <= 0) {
            continue;
        }
        $attid = (int)$DB->get_field('course_modules', 'instance', ['id' => (int)$ped->attendancecmid]);
        if ($attid && $DB->record_exists_select('attendance_sessions',
                'attendanceid = :a AND lasttaken = 0 AND sessdate + duration < :now AND (groupid = :g OR groupid = 0)',
                ['a' => $attid, 'now' => $now, 'g' => (int)$ped->groupid])) {
            $untaken[] = (object)['id' => (int)$ped->id, 'name' => $pitem->name];
        }
    }
}
$alerts = [];
if ($pendingtypeb > 0) {
    $alerts[] = html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['status' => 'pending']),
        '<strong>' . $pendingtypeb . '</strong> solicitud(es) Tipo B externo por validar') . ' › Revisar';
}
if ($untaken) {
    $links = [];
    foreach (array_slice($untaken, 0, 5) as $u) {
        $links[] = html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $u->id]), s($u->name));
    }
    $alerts[] = '<strong>' . count($untaken) . '</strong> taller(es) ya celebrado(s) sin pasar lista: ' . implode(' · ', $links)
        . (count($untaken) > 5 ? ' …' : '');
}
if ($overdue) {
    $alerts[] = html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php'),
        '<strong>' . count($overdue) . '</strong> Edición(es) con la fecha de fin pasada y sin finalizar') . ' › Finalizar';
}
if ($alerts) {
    echo html_writer::div(html_writer::tag('strong', 'Pendiente') . html_writer::alist($alerts, ['class' => 'mb-0 mt-1']),
        'alert alert-warning');
}

$tplurl = fn(string $t) => new moodle_url('/local/gestion_actividades/workshop_bulk_import.php',
    ['action' => 'template', 'templatetype' => $t, 'sesskey' => sesskey()]);
$btn = fn(moodle_url $url, string $pix, string $label, string $class = 'btn btn-outline-primary')
    => html_writer::link($url, local_ga_btn_icon($pix, $label), ['class' => $class . ' text-start']);
$block = function(string $title, string $hint, array $buttons, string $cardstyle = ''): string {
    return html_writer::div(html_writer::div(html_writer::div(
        html_writer::tag('h3', $title, ['class' => 'h5 card-title mb-1'])
        . html_writer::tag('p', $hint, ['class' => 'small mb-3' . ($cardstyle === '' ? ' text-muted' : '')])
        . html_writer::div(implode('', $buttons), 'd-grid gap-2'),
        'card-body'), 'card h-100 shadow-sm', $cardstyle !== '' ? ['style' => $cardstyle] : []), 'col-md-6 col-xl-3 mb-3');
};

echo html_writer::start_div('row');
echo $block('🗓️ Talleres', 'Crear y gestionar las Ediciones de talleres.', [
    $btn(new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typea'] + $cparams), 'i/calendar', 'Talleres Tipo A', 'btn btn-primary'),
    $btn(new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typeb'] + $cparams), 'i/calendar', 'Talleres Tipo B', 'btn btn-primary'),
    $btn(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php', $cparams), 'i/import', 'Crear Edición desde Excel'),
    html_writer::div(
        html_writer::link($tplurl('typea'), local_ga_btn_icon('t/download', 'Plantilla A'), ['class' => 'btn btn-sm btn-outline-secondary flex-fill'])
        . html_writer::link($tplurl('typeb'), local_ga_btn_icon('t/download', 'Plantilla B'), ['class' => 'btn btn-sm btn-outline-secondary flex-fill']),
        'd-flex gap-2'),
    $btn(new moodle_url('/local/gestion_actividades/archive.php', $cparams), 'i/folder', 'Ediciones anteriores', 'btn btn-outline-secondary'),
]);
echo $block('🎓 Alumnos y horas', 'Validar y reconocer horas del alumnado.', [
    $btn(new moodle_url('/local/gestion_actividades/student_search.php'), 'a/search', 'Buscar alumno (historial completo)'),
    $btn(new moodle_url('/local/gestion_actividades/index.php'), 'i/users', 'Alumnos y ranking'),
    $btn(new moodle_url('/local/gestion_actividades/institutional_import.php'), 'i/import', 'Importar reconocimiento institucional'),
    $btn(new moodle_url('/local/gestion_actividades/pat_typeb_import.php'), 'i/import', 'Importar talleres Tipo B PAT'),
    $btn(new moodle_url('/local/gestion_actividades/typeb_catalog.php'), 't/edit', 'Catálogo de talleres B'),
    $btn(new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['status' => 'pending'] + $cparams), 'i/checked',
        'Validar Tipo B externo' . ($pendingtypeb > 0 ? ' (' . $pendingtypeb . ')' : ''), $pendingtypeb > 0 ? 'btn btn-warning' : 'btn btn-outline-primary'),
    $btn(new moodle_url('/local/gestion_actividades/manager_downloads.php', ['action' => 'view_transfers']), 'i/switch', 'Ver traspasos A→B'),
]);
echo $block('📊 Notas e informes', 'Consultar notas y descargar listados.', [
    $btn(new moodle_url('/local/gestion_actividades/manager_downloads.php'), 't/download', 'Listados y descargas'),
    $btn(new moodle_url('/local/gestion_actividades/workshop_report.php'), 'i/report', 'Listado personalizado de talleres'),
    $btn(new moodle_url('/local/gestion_actividades/grades_report.php', $cparams), 'i/grades', 'Notas de la asignatura', 'btn btn-primary'),
]);
echo $block('⚙️ Configuración', 'Recomendable solo para administradores: permisos y herramientas de mantenimiento.', [
    html_writer::tag('details',
        html_writer::tag('summary', 'Mostrar / ocultar opciones', ['class' => 'btn w-100 text-start',
            'style' => 'background:#fff;border:1px solid #e4a1a1;color:#9b2c2c'])
        . html_writer::div(
            $btn(new moodle_url('/local/gestion_actividades/authorized_users.php', $cparams), 'i/permissions', 'Usuarios autorizados y Profesores HEE', 'btn btn-outline-secondary')
            . $btn(new moodle_url('/local/gestion_actividades/portfolio_cover_template.php'), 't/edit', 'Portada del portafolio PDF', 'btn btn-outline-secondary')
            . $btn(new moodle_url('/local/gestion_actividades/test_cleanup.php'), 't/delete', 'Limpieza de pruebas', 'btn btn-outline-danger'),
            'd-grid gap-2 mt-2')),
], 'background:#fdecec;border-color:#f1b5b5;color:#7a1f1f');  // pastel red: delicate tools
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
