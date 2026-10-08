<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;
use local_gestion_actividades\local\academic_year;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

workshop_series::ensure_schema();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/archive.php'));
$PAGE->set_title(get_string('workshoparchive', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

function local_ga_archive_type_badge(?string $type): string {
    $type = manager::normalize_workshop_type((string)($type ?? 'typea'));
    $label = $type === 'typeb' ? 'Tipo B' : 'Tipo A';
    $class = $type === 'typeb' ? 'badge bg-primary' : 'badge bg-success';
    return html_writer::span($label, $class, ['style' => 'font-size:0.82rem;padding:6px 9px;']);
}

function local_ga_archive_series_edition(stdClass $series, int $workshopid): ?stdClass {
    $editions = manager::list_workshop_editions($workshopid);
    if (!$editions) { return null; }
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
        if ($date > 0 && $date >= (int)$series->datefrom && $date <= (int)$series->dateto) { $matching[] = $edition; }
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

function local_ga_archive_course_series_url(stdClass $series): moodle_url {
    global $DB;
    $params = ['id' => (int)$series->courseid];
    if (!empty($series->sectionid)) {
        $section = $DB->get_record('course_sections', ['id' => (int)$series->sectionid], 'id,section', IGNORE_MISSING);
        if ($section) { $params['section'] = (int)$section->section; }
    }
    return new moodle_url('/course/view.php', $params);
}

if (optional_param('archive_due', 0, PARAM_BOOL) && confirm_sesskey()) {
    $count = manager::archive_due_workshop_editions();
    redirect(new moodle_url('/local/gestion_actividades/archive.php'), get_string('archiveduecount', 'local_gestion_actividades', $count));
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');
echo $OUTPUT->heading(get_string('workshoparchive', 'local_gestion_actividades'));
echo html_writer::tag('p', 'Las Ediciones de talleres finalizadas aparecen plegadas y agrupadas por curso académico, de la más reciente a la más antigua (y, en la misma fecha, Tipo A antes que Tipo B). Los talleres del modelo anterior que no pertenecen a una edición se mantienen en un bloque independiente.', ['class' => 'alert alert-info']);

$ay = academic_year::selected(true);
echo academic_year::selector(new moodle_url('/local/gestion_actividades/archive.php'), $ay, 'Las Ediciones se agrupan por curso académico.');
$serieslist = workshop_series::list_for_course(0);
$alllinkedworkshops = [];
foreach ($serieslist as $allseries) {
    foreach (workshop_series::items((int)$allseries->id) as $allitem) { $alllinkedworkshops[(int)$allitem->workshopid] = true; }
}

// Finished Ediciones, folded: most recent first and, for the same date,
// Tipo A before Tipo B. Each one opens with a click.
$archived = [];
foreach ($serieslist as $series) {
    if (($series->status ?? '') !== 'finished') { continue; }
    $items = workshop_series::items((int)$series->id);
    if (!$items) { continue; }
    $types = [];
    foreach ($items as $it) {
        $types[manager::normalize_workshop_type((string)($it->workshoptype ?? 'typea')) === 'typeb' ? 'B' : 'A'] = true;
    }
    ksort($types);
    $year = academic_year::for_series($series);
    if (!academic_year::matches($ay, $year)) { continue; }
    $archived[] = (object)['series' => $series, 'items' => $items, 'types' => array_keys($types), 'year' => $year];
}
usort($archived, static function($a, $b) {
    $y = strcmp($b->year, $a->year);
    if ($y !== 0) { return $y; }
    $d = (int)$b->series->datefrom <=> (int)$a->series->datefrom;
    if ($d !== 0) { return $d; }
    $rank = static fn($t) => $t === ['A'] ? 0 : ($t === ['B'] ? 1 : 2);
    $r = $rank($a->types) <=> $rank($b->types);
    return $r !== 0 ? $r : ((int)$b->series->id <=> (int)$a->series->id);
});
if ($archived) {
    echo html_writer::tag('p', 'Pulsa sobre una Edición para ver sus talleres.', ['class' => 'text-muted']);
}

$archivedseriescount = 0;
$lastyear = null;
foreach ($archived as $entry) {
    if ($entry->year !== $lastyear) {
        echo html_writer::tag('h3', 'Curso académico ' . s($entry->year), ['class' => 'h4 mt-4 mb-2']);
        $lastyear = $entry->year;
    }
    $series = $entry->series;
    $items = $entry->items;
    $archivedseriescount++;
    $course = $DB->get_record('course', ['id' => (int)$series->courseid], 'id,fullname', IGNORE_MISSING);
    $typebadges = '';
    foreach ($entry->types as $t) {
        $typebadges .= local_ga_archive_type_badge($t === 'B' ? 'typeb' : 'typea') . ' ';
    }
    echo html_writer::start_tag('details', ['class' => 'card shadow-sm mb-3']);
    echo html_writer::start_tag('summary', ['class' => 'card-header bg-light', 'style' => 'cursor:pointer;list-style-position:inside']);
    echo html_writer::start_div('d-inline-flex flex-wrap justify-content-between align-items-start', ['style' => 'width:calc(100% - 1.5em);vertical-align:top']);
    echo html_writer::start_div('me-3');
    echo html_writer::tag('span', s($series->title), ['class' => 'h5 mb-1 me-2']) . $typebadges;
    echo html_writer::tag('div', userdate((int)$series->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y')
        . ' · ' . count($items) . ' taller(es) · ' . ($course ? format_string($course->fullname) : 'Curso #' . (int)$series->courseid)
        . ' · Finalizada / oculta', ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::div(html_writer::link(local_ga_archive_course_series_url($series), 'Abrir en el curso', ['class' => 'btn btn-sm btn-outline-secondary']), 'mb-1');
    echo html_writer::end_div();
    echo html_writer::end_tag('summary');
    echo html_writer::start_div('card-body p-0');
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm mb-0';
    $table->head = ['Orden', 'Tipo', 'Taller', 'Fecha', 'Horario', 'Horas', 'Plazas', 'Inscritos', 'Profesor/es', 'Grupo', 'Acciones'];
    foreach ($items as $item) {
        $edition = local_ga_archive_series_edition($series, (int)$item->workshopid);
        $sessiondate = $edition ? (int)($edition->sessiondate ?? 0) : 0;
        $sessionend = (int)($item->sessionenddate ?? 0);
        $teachers = $edition ? manager::get_edition_teachers((int)$edition->id) : [];
        $teachernames = [];
        foreach ($teachers as $teacher) { $teachernames[] = fullname($teacher); }
        [$datetext, $schedule] = \local_gestion_actividades\local\workshop_series::schedule_parts($sessiondate, $item);
        $datetext = nl2br(s($datetext));
        $schedule = nl2br(s($schedule));
        $groupname = '-';
        if ($edition && !empty($edition->groupid)) {
            $group = $DB->get_record('groups', ['id' => (int)$edition->groupid], 'id,name', IGNORE_MISSING);
            if ($group) { $groupname = format_string($group->name); }
        }
        $actions = '-';
        if ($edition) {
            $actions = html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$edition->id]), 'Alumnos / asistencia', ['class' => 'btn btn-secondary btn-sm me-1 mb-1']);
            if (manager::normalize_workshop_type((string)($item->workshoptype ?? 'typea')) === 'typea') {
                $actions .= html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => (int)$item->workshopid, 'editionid' => (int)$edition->id]), 'Modificar notas', ['class' => 'btn btn-primary btn-sm mb-1']);
            }
        }
        $table->data[] = ['Taller ' . sprintf('%02d', (int)$item->sortorder), local_ga_archive_type_badge($item->workshoptype ?? 'typea'), format_string($item->name), $datetext, $schedule, format_float((float)($item->hours ?? 0), 2, true) . ' h', $edition && (int)($edition->places ?? 0) > 0 ? (int)$edition->places : '-', $edition ? manager::get_edition_enrolment_count((int)$edition->id) : '-', $teachernames ? s(implode(', ', $teachernames)) : '-', $groupname, $actions];
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
    echo html_writer::end_tag('details');
}

$legacyarchived = [];
foreach (manager::get_workshop_overview_rows() as $row) {
    $workshopid = (int)($row->workshopid ?? 0);
    if (!empty($alllinkedworkshops[$workshopid])) { continue; }
    if (($row->computedstatus ?? '') === 'archived' && academic_year::matches($ay, academic_year::for_time((int)($row->sessiondate ?? 0)))) { $legacyarchived[] = $row; }
}
if ($legacyarchived) {
    echo html_writer::tag('h3', 'Talleres anteriores sin Edición de talleres', ['class' => 'h4 mt-4']);
    echo html_writer::tag('p', 'Registros archivados del modelo anterior. Se mantienen disponibles para consulta y modificación de notas sin mezclarlos con las nuevas Ediciones de talleres.', ['class' => 'text-muted']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Curso académico', 'Tipo', 'Código', 'Taller', 'Código de edición', 'Fecha', 'Plazas', 'Inscritos', 'Profesor/es', 'Grupo', 'Acciones'];
    foreach ($legacyarchived as $row) {
        $actions = html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$row->id]), 'Alumnos / asistencia', ['class' => 'btn btn-secondary btn-sm me-1 mb-1']);
        if (manager::normalize_workshop_type((string)($row->workshoptype ?? 'typea')) === 'typea') {
            $actions .= html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => (int)$row->workshopid, 'editionid' => (int)$row->id]), 'Modificar notas', ['class' => 'btn btn-primary btn-sm mb-1']);
        }
        $table->data[] = [academic_year::for_time((int)($row->sessiondate ?? 0)), local_ga_archive_type_badge($row->workshoptype ?? 'typea'), s($row->workshopcode ?? ''), format_string($row->workshopname ?? ''), s($row->editioncode ?? ''), !empty($row->sessiondate) ? manager::format_date_compact((int)$row->sessiondate) : '-', (int)($row->places ?? 0), (int)($row->enrolledcount ?? 0), $row->teachers ?: '-', $row->groupname ?: '-', $actions];
    }
    echo html_writer::table($table);
}
if ($archivedseriescount === 0 && !$legacyarchived) { echo $OUTPUT->notification(get_string('noarchivedworkshops', 'local_gestion_actividades'), 'info'); }
if (function_exists('local_gestion_actividades_enable_interactive_tables')) { local_gestion_actividades_enable_interactive_tables(); }
echo $OUTPUT->footer();
