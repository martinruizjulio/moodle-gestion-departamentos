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

$type = optional_param('type', 'typea', PARAM_ALPHA);
$type = $type === 'typeb' ? 'typeb' : 'typea';
$typetitle = $type === 'typeb' ? 'Talleres Tipo B' : 'Talleres Tipo A';

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/workshops.php', ['type' => $type]));
$PAGE->set_title($typetitle);
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

function local_ga_workshops_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

/**
 * Returns the workshop edition that belongs to the selected workshop series.
 * Legacy workshops may have more than one old edition, so prefer the one whose
 * session date falls inside the series range and otherwise use the current one.
 */
function local_ga_series_workshop_edition(stdClass $series, int $workshopid): ?stdClass {
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

function local_ga_series_status_label(stdClass $series): string {
    if (($series->status ?? '') === 'finished') {
        return html_writer::span('Finalizada / oculta', 'badge bg-secondary');
    }
    if (!empty($series->dateto) && (int)$series->dateto < time()) {
        return html_writer::span('Fecha final superada / oculta', 'badge bg-secondary');
    }
    return html_writer::span('Activa', 'badge bg-success');
}

function local_ga_workshop_status_label(?stdClass $edition): string {
    if (!$edition) {
        return html_writer::span('Pendiente de configurar', 'badge bg-warning text-dark');
    }
    $status = (string)($edition->status ?? '');
    if (in_array($status, ['open', 'active', 'published'], true)) {
        return html_writer::span('Abierto', 'badge bg-success');
    }
    if (in_array($status, ['closed', 'completed', 'finished', 'closed_finished'], true)) {
        return html_writer::span('Cerrado', 'badge bg-secondary');
    }
    return html_writer::span($status !== '' ? s($status) : 'Configurado', 'badge badge-info');
}

function local_ga_course_series_url(stdClass $series): moodle_url {
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

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/gestion_actividades/dashboard.php'),
        local_ga_workshops_btn_icon('t/left', 'Volver al panel'),
        ['class' => 'btn btn-outline-secondary mb-3']
    ),
    'mb-2'
);

echo $OUTPUT->heading($typetitle);

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/gestion_actividades/workshop_series.php'),
        local_ga_workshops_btn_icon('i/calendar', 'Ediciones de talleres'),
        ['class' => 'btn btn-primary me-1 mb-1']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/local/gestion_actividades/workshop_bulk_import.php'),
        local_ga_workshops_btn_icon('t/upload', 'Crear edición desde Excel'),
        ['class' => 'btn btn-success me-1 mb-1']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/local/gestion_actividades/repair_course_visuals.php', ['sesskey' => sesskey()]),
        local_ga_workshops_btn_icon('t/reload', get_string('repaircoursevisuals', 'local_gestion_actividades')),
        ['class' => 'btn btn-secondary mb-1']
    ),
    'mb-3'
);

echo html_writer::tag(
    'p',
    'Los talleres creados manualmente y los importados desde Excel aparecen juntos dentro de su Edición de talleres. Los datos estructurales se editan aquí; cuestionarios, apuntes y asistencia siguen siendo actividades Moodle editables desde la vista del curso.',
    ['class' => 'text-muted mb-4']
);

$serieslist = workshop_series::list_for_course(0);
$linkedworkshops = [];
$shownseries = 0;

foreach ($serieslist as $series) {
    $allitems = workshop_series::items((int)$series->id);
    $items = [];
    foreach ($allitems as $item) {
        $itemtype = (string)($item->workshoptype ?? 'typea');
        $itemtype = $itemtype === 'typeb' ? 'typeb' : 'typea';
        if ($itemtype === $type) {
            $items[] = $item;
            $linkedworkshops[(int)$item->workshopid] = true;
        }
    }
    if (!$items) {
        continue;
    }
    $shownseries++;

    $course = $DB->get_record('course', ['id' => (int)$series->courseid], 'id,fullname', IGNORE_MISSING);
    $coursetitle = $course ? format_string($course->fullname) : 'Curso #' . (int)$series->courseid;
    $daterange = userdate((int)$series->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y');

    echo html_writer::start_div('card shadow-sm mb-4');
    echo html_writer::start_div('card-header bg-light');
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start');
    echo html_writer::start_div('mr-3');
    echo html_writer::tag('h3', s($series->title), ['class' => 'h5 mb-1']);
    echo html_writer::tag('div', s($coursetitle) . ' · ' . s($daterange) . ' · ' . local_ga_series_status_label($series), ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::start_div('text-right');
    echo html_writer::link(
        new moodle_url('/local/gestion_actividades/workshop_series.php', ['id' => (int)$series->id]),
        local_ga_workshops_btn_icon('t/edit', 'Editar edición'),
        ['class' => 'btn btn-sm btn-outline-primary me-1 mb-1']
    );
    echo html_writer::link(
        new moodle_url('/local/gestion_actividades/workshop_edit.php', [
            'type' => $type,
            'seriesid' => (int)$series->id,
            'sortorder' => workshop_series::next_sortorder((int)$series->id),
        ]),
        local_ga_workshops_btn_icon('t/add', 'Añadir taller manual'),
        ['class' => 'btn btn-sm btn-success me-1 mb-1']
    );
    echo html_writer::link(
        local_ga_course_series_url($series),
        local_ga_workshops_btn_icon('i/course', 'Abrir en el curso'),
        ['class' => 'btn btn-sm btn-outline-secondary mb-1']
    );
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('card-body p-0');
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm mb-0';
    $table->head = ['Orden', 'Taller', 'Fecha', 'Horario', 'Horas', 'Plazas', 'Inscritos', 'Estado', 'Acciones'];

    foreach ($items as $item) {
        $edition = local_ga_series_workshop_edition($series, (int)$item->workshopid);
        $sessiondate = $edition ? (int)($edition->sessiondate ?? 0) : 0;
        $sessionend = (int)($item->sessionenddate ?? 0);
        $date = $sessiondate > 0 ? userdate($sessiondate, '%d/%m/%Y') : '-';
        $schedule = $sessiondate > 0 ? userdate($sessiondate, '%H:%M') : '-';
        if ($sessiondate > 0 && $sessionend > 0) {
            $schedule .= '–' . userdate($sessionend, '%H:%M');
        }
        $places = $edition ? (int)($edition->places ?? 0) : 0;
        $enrolled = $edition ? manager::get_edition_enrolment_count((int)$edition->id) : 0;

        $editparams = [
            'workshopid' => (int)$item->workshopid,
            'seriesid' => (int)$series->id,
            'sortorder' => (int)$item->sortorder,
        ];
        if ($edition) {
            $editparams['id'] = (int)$edition->id;
        }
        $actions = html_writer::link(
            new moodle_url('/local/gestion_actividades/edition_edit.php', $editparams),
            local_ga_workshops_btn_icon('t/edit', 'Editar taller'),
            ['class' => 'btn btn-sm btn-primary me-1 mb-1']
        );
        if ($edition) {
            $actions .= html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$edition->id]),
                local_ga_workshops_btn_icon('i/users', 'Alumnos / asistencia'),
                ['class' => 'btn btn-sm btn-outline-secondary mb-1']
            );
        }

        $table->data[] = [
            'Taller ' . sprintf('%02d', (int)$item->sortorder),
            s($item->name),
            $date,
            $schedule,
            format_float((float)($item->hours ?? 0), 2, true) . ' h',
            $places > 0 ? $places : '-',
            $edition ? $enrolled : '-',
            local_ga_workshop_status_label($edition),
            $actions,
        ];
    }

    echo html_writer::table($table);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($shownseries === 0) {
    echo $OUTPUT->notification('Todavía no hay Ediciones de talleres con talleres de este tipo.', 'info');
}

// Keep legacy or not-yet-attached workshops visible so nothing disappears during the transition.
$unlinked = [];
foreach (manager::list_workshops(0, $type) as $workshop) {
    if (empty($linkedworkshops[(int)$workshop->id])) {
        $unlinked[] = $workshop;
    }
}

if ($unlinked) {
    echo html_writer::tag('h3', 'Talleres sin Edición de talleres', ['class' => 'h4 mt-4']);
    echo html_writer::tag('p', 'Registros anteriores o todavía no asociados a una Edición de talleres. Se mantienen visibles para poder revisarlos y migrarlos sin perder información.', ['class' => 'text-muted']);
    $legacytable = new html_table();
    $legacytable->attributes['class'] = 'generaltable table-sm';
    $legacytable->head = ['Curso', 'Código', 'Taller', 'Horas', 'Configuración', 'Acciones'];
    foreach ($unlinked as $workshop) {
        $course = $DB->get_record('course', ['id' => (int)$workshop->courseid], 'id,fullname', IGNORE_MISSING);
        $editions = manager::list_workshop_editions((int)$workshop->id);
        $editioncount = count($editions);
        if ($editioncount === 1) {
            $edition = reset($editions);
            $editurl = new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => (int)$edition->id, 'workshopid' => (int)$workshop->id]);
        } else if ($editioncount > 1) {
            $editurl = new moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => (int)$workshop->id]);
        } else {
            $editurl = new moodle_url('/local/gestion_actividades/edition_edit.php', ['workshopid' => (int)$workshop->id]);
        }
        $legacytable->data[] = [
            $course ? format_string($course->fullname) : (int)$workshop->courseid,
            s($workshop->code),
            format_string($workshop->name),
            isset($workshop->hours) && $workshop->hours !== null ? format_float((float)$workshop->hours, 2, true) . ' h' : '-',
            $editioncount > 0 ? $editioncount . ' edición(es)' : 'Pendiente de configurar',
            html_writer::link($editurl, local_ga_workshops_btn_icon('t/edit', 'Revisar'), ['class' => 'btn btn-sm btn-outline-primary']),
        ];
    }
    echo html_writer::table($legacytable);
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}

echo $OUTPUT->footer();
