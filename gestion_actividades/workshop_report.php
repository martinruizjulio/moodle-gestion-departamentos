<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\typeb_reflection_activity;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/workshop_report.php'));
$PAGE->set_title('Listado personalizado de seminarios');
$PAGE->set_heading('Gestión HEE');

$selected = optional_param_array('editions', [], PARAM_INT);
$export = optional_param('export', 0, PARAM_BOOL);
$selected = array_values(array_unique(array_filter(array_map('intval', $selected), static fn($id) => $id > 0)));

function local_ga_wr_send_csv(array $rows): void {
    \core\session\manager::write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="listado_seminarios_seleccionados.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Seminario', 'Edición', 'Alumno', 'Horas', 'Actividad', 'Estado actividad', 'Resultado', 'Asistencia'], ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

function local_ga_wr_activity_status(stdClass $edition, stdClass $workshop, int $userid, $attended): array {
    global $DB;

    $attendanceok = !empty($attended);
    $type = manager::normalize_workshop_type((string)($workshop->workshoptype ?? 'typea'));

    if ($type === 'typeb') {
        $summary = typeb_reflection_activity::submission_summary((int)$edition->id, $userid);
        $submitted = !empty($summary->submitted);
        return [
            'Tarea Moodle de reflexión',
            $submitted ? 'Reflexión entregada' : 'Reflexión pendiente',
            ($attendanceok && $submitted) ? 'Apto' : 'Pendiente',
        ];
    }

    $requiredtypes = manager::get_required_activity_types($edition);
    if (in_array('quiz', $requiredtypes, true)) {
        $requirement = manager::get_quiz_requirement($edition);
        $cmid = (int)$requirement->cmid;
        if ($cmid <= 0) {
            return ['Cuestionario Moodle', 'Pendiente de vincular', 'Pendiente'];
        }
        $finished = manager::user_submitted_required_activity($userid, $cmid);
        $grade = $finished ? manager::get_user_quiz_grade_out_of_10($userid, $cmid) : null;
        if (!$finished) {
            $status = 'Cuestionario no finalizado';
            $result = 'Pendiente';
        } else if ($grade === null) {
            $status = 'Finalizado · pendiente de calificación';
            $result = 'Pendiente';
        } else {
            $status = 'Finalizado · ' . format_float((float)$grade, 2, true) . '/10';
            $result = ($attendanceok && (float)$grade >= (float)$requirement->minimum) ? 'Apto' : 'No apto';
        }
        return ['Cuestionario Moodle', $status, $result];
    }

    // Compatibilidad histórica: algunas ediciones Tipo A antiguas usaban una tarea interna.
    $submission = null;
    if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_task_submissions'))) {
        $submission = $DB->get_record('local_ga_task_submissions', [
            'editionid' => (int)$edition->id,
            'userid' => $userid,
        ], '*', IGNORE_MISSING);
    }
    $submitted = $submission && !empty($submission->fileitemid);
    $grade = ($submission && $submission->grade !== null && $submission->grade !== '')
        ? (float)$submission->grade
        : null;
    if (!$submitted) {
        return ['Tarea Moodle (histórica)', 'No entregada', 'Pendiente'];
    }
    if ($grade === null) {
        return ['Tarea Moodle (histórica)', 'Entregada · pendiente de nota', 'Pendiente'];
    }
    return [
        'Tarea Moodle (histórica)',
        'Entregada · ' . format_float($grade, 2, true) . '/10',
        ($attendanceok && $grade >= 5.0) ? 'Apto' : 'No apto',
    ];
}

$editions = $DB->get_records_sql(
    "SELECT e.id,
            e.name AS editionname,
            e.sessiondate,
            w.name AS workshopname,
            w.code AS workshopcode,
            w.hours,
            w.workshoptype,
            c.fullname AS coursename
       FROM {local_ga_workshop_editions} e
       JOIN {local_ga_workshops} w ON w.id = e.workshopid
  LEFT JOIN {course} c ON c.id = w.courseid
   ORDER BY c.fullname ASC, w.name ASC, e.sessiondate DESC, e.id DESC"
);

$reportrows = [];
if ($selected) {
    [$insql, $params] = $DB->get_in_or_equal($selected, SQL_PARAMS_NAMED, 'ed');
    $sql = "SELECT " . $DB->sql_concat('e.id', "'-'", 'u.id') . " AS uniqid,
                   e.id AS editionid,
                   e.name AS editionname,
                   w.id AS workshopid,
                   w.name AS workshopname,
                   w.code AS workshopcode,
                   w.hours,
                   w.workshoptype,
                   u.id AS userid,
                   u.firstname,
                   u.lastname,
                   u.email,
                   ee.attended
              FROM {local_ga_workshop_editions} e
              JOIN {local_ga_workshops} w ON w.id = e.workshopid
              JOIN {local_ga_edition_enrolments} ee ON ee.editionid = e.id
              JOIN {user} u ON u.id = ee.userid AND u.deleted = 0
             WHERE e.id $insql
          ORDER BY w.name ASC, e.sessiondate ASC, e.id ASC, u.lastname ASC, u.firstname ASC";
    $records = $DB->get_records_sql($sql, $params);

    foreach ($records as $r) {
        $edition = manager::get_workshop_edition((int)$r->editionid);
        $workshop = manager::get_workshop((int)$r->workshopid);
        [$activity, $activitystatus, $result] = local_ga_wr_activity_status(
            $edition,
            $workshop,
            (int)$r->userid,
            $r->attended
        );
        $attendance = $r->attended === null
            ? 'Sin registrar'
            : (!empty($r->attended) ? 'Presente' : 'Ausente');
        $reportrows[] = [
            trim((string)$r->workshopcode . ' - ' . (string)$r->workshopname, ' -'),
            (string)($r->editionname ?: '-'),
            fullname($r),
            format_float((float)$r->hours, 2, true),
            $activity,
            $activitystatus,
            $result,
            $attendance,
        ];
    }
}

if ($export && $selected) {
    require_sesskey();
    local_ga_wr_send_csv($reportrows);
}

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/gestion_actividades/dashboard.php'),
        $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel',
        ['class' => 'btn btn-outline-secondary mb-3']
    )
);

echo $OUTPUT->heading('Listado personalizado de seminarios');
echo html_writer::tag(
    'p',
    'Selecciona uno, varios o todos los seminarios. El listado usa la actividad real de cada edición: cuestionario Moodle en Tipo A nuevo, tarea histórica cuando corresponda y reflexión Moodle en Tipo B.',
    ['class' => 'alert alert-info']
);

if (!$editions) {
    echo $OUTPUT->notification('No hay ediciones de seminarios disponibles.', 'info');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'card mb-4']);
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', 'Seleccionar seminarios', ['class' => 'h5']);
echo html_writer::tag('div',
    html_writer::tag('button', 'Seleccionar todos', ['type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm me-2', 'id' => 'ga-select-all']) .
    html_writer::tag('button', 'Quitar selección', ['type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm', 'id' => 'ga-clear-all']),
    ['class' => 'mb-3']
);

foreach ($editions as $edition) {
    $label = trim((string)$edition->workshopcode . ' - ' . (string)$edition->workshopname, ' -');
    if (!empty($edition->editionname)) {
        $label .= ' · ' . $edition->editionname;
    }
    if (!empty($edition->sessiondate)) {
        $label .= ' · ' . userdate((int)$edition->sessiondate, '%d/%m/%Y');
    }
    $label .= ' · ' . format_float((float)$edition->hours, 2, true) . ' h';
    $label .= ((string)$edition->workshoptype === 'typeb') ? ' · Tipo B' : ' · Tipo A';
    $id = 'edition_' . (int)$edition->id;
    $attrs = [
        'type' => 'checkbox',
        'name' => 'editions[]',
        'value' => (int)$edition->id,
        'id' => $id,
        'class' => 'form-check-input ga-edition-check',
    ];
    if (in_array((int)$edition->id, $selected, true)) {
        $attrs['checked'] = 'checked';
    }
    echo html_writer::start_div('form-check mb-1');
    echo html_writer::empty_tag('input', $attrs);
    echo html_writer::tag('label', s($label), ['for' => $id, 'class' => 'form-check-label']);
    echo html_writer::end_div();
}

echo html_writer::tag('button', 'Generar listado', ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$PAGE->requires->js_init_code("document.getElementById('ga-select-all').addEventListener('click',function(){document.querySelectorAll('.ga-edition-check').forEach(function(c){c.checked=true;});});document.getElementById('ga-clear-all').addEventListener('click',function(){document.querySelectorAll('.ga-edition-check').forEach(function(c){c.checked=false;});});");

if ($selected) {
    echo html_writer::tag('h3', 'Resultado', ['class' => 'h4']);
    $exportparams = ['export' => 1, 'sesskey' => sesskey()];
    foreach ($selected as $id) {
        $exportparams['editions'][] = $id;
    }
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/gestion_actividades/workshop_report.php', $exportparams),
            $OUTPUT->pix_icon('t/download', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Descargar CSV',
            ['class' => 'btn btn-primary mb-3']
        )
    );

    if (!$reportrows) {
        echo $OUTPUT->notification('Los seminarios seleccionados no tienen alumnado inscrito.', 'info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Seminario', 'Edición', 'Alumno', 'Horas', 'Actividad', 'Estado actividad', 'Resultado', 'Asistencia'];
        foreach ($reportrows as $row) {
            $table->data[] = array_map('s', $row);
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
