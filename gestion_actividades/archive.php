<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;

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
            continue; // Linked to another Edición de seminarios: never borrow it by dates.
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
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');
echo $OUTPUT->heading(get_string('workshoparchive', 'local_gestion_actividades'));
echo html_writer::tag('p', 'Las Ediciones de seminarios finalizadas expresamente se muestran agrupadas de la más reciente a la más antigua. Los seminarios del modelo anterior que no pertenecen a una edición se mantienen en un bloque independiente.', ['class' => 'alert alert-info']);

$serieslist = workshop_series::list_for_course(0);
$alllinkedworkshops = [];
foreach ($serieslist as $allseries) {
    foreach (workshop_series::items((int)$allseries->id) as $allitem) { $alllinkedworkshops[(int)$allitem->workshopid] = true; }
}

$archivedseriescount = 0;
foreach ($serieslist as $series) {
    $isarchived = ($series->status ?? '') === 'finished';
    if (!$isarchived) { continue; }
    $items = workshop_series::items((int)$series->id);
    if (!$items) { continue; }
    $archivedseriescount++;
    $course = $DB->get_record('course', ['id' => (int)$series->courseid], 'id,fullname', IGNORE_MISSING);
    echo html_writer::start_div('card shadow-sm mb-4');
    echo html_writer::start_div('card-header bg-light');
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start');
    echo html_writer::start_div('me-3');
    echo html_writer::tag('h3', s($series->title), ['class' => 'h5 mb-1']);
    echo html_writer::tag('div', ($course ? format_string($course->fullname) : 'Curso #' . (int)$series->courseid) . ' · ' . userdate((int)$series->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y') . ' · Finalizada / oculta', ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::div(html_writer::link(local_ga_archive_course_series_url($series), 'Abrir en el curso', ['class' => 'btn btn-sm btn-outline-secondary']), 'mb-1');
    echo html_writer::end_div();
    echo html_writer::end_div();
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
        $schedule = $sessiondate > 0 ? userdate($sessiondate, '%H:%M') : '-';
        if ($sessiondate > 0 && $sessionend > 0) { $schedule .= '–' . userdate($sessionend, '%H:%M'); }
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
        $table->data[] = ['Taller ' . sprintf('%02d', (int)$item->sortorder), local_ga_archive_type_badge($item->workshoptype ?? 'typea'), format_string($item->name), $sessiondate > 0 ? userdate($sessiondate, '%d/%m/%Y') : '-', $schedule, format_float((float)($item->hours ?? 0), 2, true) . ' h', $edition && (int)($edition->places ?? 0) > 0 ? (int)$edition->places : '-', $edition ? manager::get_edition_enrolment_count((int)$edition->id) : '-', $teachernames ? s(implode(', ', $teachernames)) : '-', $groupname, $actions];
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

$legacyarchived = [];
foreach (manager::get_workshop_overview_rows() as $row) {
    $workshopid = (int)($row->workshopid ?? 0);
    if (!empty($alllinkedworkshops[$workshopid])) { continue; }
    if (($row->computedstatus ?? '') === 'archived') { $legacyarchived[] = $row; }
}
if ($legacyarchived) {
    echo html_writer::tag('h3', 'Talleres anteriores sin Edición de seminarios', ['class' => 'h4 mt-4']);
    echo html_writer::tag('p', 'Registros archivados del modelo anterior. Se mantienen disponibles para consulta y modificación de notas sin mezclarlos con las nuevas Ediciones de seminarios.', ['class' => 'text-muted']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Tipo', 'Código', 'Taller', 'Código de edición', 'Fecha', 'Plazas', 'Inscritos', 'Profesor/es', 'Grupo', 'Acciones'];
    foreach ($legacyarchived as $row) {
        $actions = html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$row->id]), 'Alumnos / asistencia', ['class' => 'btn btn-secondary btn-sm me-1 mb-1']);
        if (manager::normalize_workshop_type((string)($row->workshoptype ?? 'typea')) === 'typea') {
            $actions .= html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => (int)$row->workshopid, 'editionid' => (int)$row->id]), 'Modificar notas', ['class' => 'btn btn-primary btn-sm mb-1']);
        }
        $table->data[] = [local_ga_archive_type_badge($row->workshoptype ?? 'typea'), s($row->workshopcode ?? ''), format_string($row->workshopname ?? ''), s($row->editioncode ?? ''), !empty($row->sessiondate) ? manager::format_date_compact((int)$row->sessiondate) : '-', (int)($row->places ?? 0), (int)($row->enrolledcount ?? 0), $row->teachers ?: '-', $row->groupname ?: '-', $actions];
    }
    echo html_writer::table($table);
}
if ($archivedseriescount === 0 && !$legacyarchived) { echo $OUTPUT->notification(get_string('noarchivedworkshops', 'local_gestion_actividades'), 'info'); }
if (function_exists('local_gestion_actividades_enable_interactive_tables')) { local_gestion_actividades_enable_interactive_tables(); }
echo $OUTPUT->footer();
