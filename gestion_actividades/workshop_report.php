<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * workshop_report.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\typeb_reflection_activity;
use local_gestion_actividades\local\academic_year;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/workshop_report.php'));
$PAGE->set_title('Listado personalizado de talleres');
$PAGE->set_heading('Gestión HEE');

$selected = optional_param_array('editions', [], PARAM_INT);
$export = optional_param('export', 0, PARAM_BOOL);
$ay = academic_year::selected();
$selected = array_values(array_unique(array_filter(array_map('intval', $selected), static fn($id) => $id > 0)));

function local_ga_wr_send_csv(array $rows): void {
    \core\session\manager::write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="listado_talleres_seleccionados.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Curso académico', 'Apellidos', 'Nombre', 'Correo', 'Taller', 'Edición', 'Horas', 'Calificación', 'Asistencia', 'Resultado'], ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

function local_ga_wr_series_title(int $seriesid, string $fallback): string {
    global $DB;
    static $cache = [];
    if ($seriesid <= 0) {
        return $fallback;
    }
    if (!array_key_exists($seriesid, $cache)) {
        $cache[$seriesid] = (string)$DB->get_field(\local_gestion_actividades\local\workshop_series::TABLE, 'title', ['id' => $seriesid]);
    }
    return $cache[$seriesid] !== '' ? $cache[$seriesid] : $fallback;
}

function local_ga_wr_activity_status(stdClass $edition, stdClass $workshop, int $userid, $attended): array {
    global $DB;

    $attendanceok = !empty($attended);
    $type = manager::normalize_workshop_type((string)($workshop->workshoptype ?? 'typea'));

    if ($type === 'typeb') {
        $summary = typeb_reflection_activity::submission_summary((int)$edition->id, $userid);
        $submitted = !empty($summary->submitted);
        return [
            $submitted ? 'Reflexión entregada' : 'Reflexión pendiente',
            ($attendanceok && $submitted) ? 'Apto' : ($submitted ? 'No apto' : 'Pendiente'),
        ];
    }

    $requiredtypes = manager::get_required_activity_types($edition);
    if (in_array('quiz', $requiredtypes, true)) {
        $requirement = manager::get_quiz_requirement($edition);
        $cmid = (int)$requirement->cmid;
        if ($cmid <= 0) {
            return ['Sin cuestionario', 'Pendiente'];
        }
        $finished = manager::user_submitted_required_activity($userid, $cmid);
        $grade = $finished ? manager::get_user_quiz_grade_out_of_10($userid, $cmid) : null;
        if (!$finished) {
            $status = 'No realizado';
            $result = 'Pendiente';
        } else if ($grade === null) {
            $status = 'Pendiente de calificar';
            $result = 'Pendiente';
        } else {
            $status = format_float((float)$grade, 2, true);
            $result = ($attendanceok && (float)$grade >= (float)$requirement->minimum) ? 'Apto' : 'No apto';
        }
        return [$status, $result];
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
        return ['No entregada', 'Pendiente'];
    }
    if ($grade === null) {
        return ['Pendiente de calificar', 'Pendiente'];
    }
    return [
        format_float($grade, 2, true),
        ($attendanceok && $grade >= 5.0) ? 'Apto' : 'No apto',
    ];
}

$editions = $DB->get_records_sql(
    "SELECT e.id,
            e.name AS editionname,
            e.sessiondate,
            e.seriesid,
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
$editions = array_filter($editions, static fn($e) => academic_year::matches(
    $ay,
    academic_year::for_row((int)($e->seriesid ?? 0), (int)($e->sessiondate ?? 0))
));

$reportrows = [];
if ($selected) {
    [$insql, $params] = $DB->get_in_or_equal($selected, SQL_PARAMS_NAMED, 'ed');
    $sql = "SELECT " . $DB->sql_concat('e.id', "'-'", 'u.id') . " AS uniqid,
                   e.id AS editionid,
                   e.name AS editionname,
                   e.seriesid,
                   e.sessiondate,
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
              JOIN {local_ga_edition_enrolments} ee ON ee.editionid = e.id AND " . manager::active_enrolment_sql('ee') . "
              JOIN {user} u ON u.id = ee.userid AND u.deleted = 0
             WHERE e.id $insql
          ORDER BY e.sessiondate ASC, w.code ASC, e.id ASC, u.lastname ASC, u.firstname ASC";
    $records = $DB->get_records_sql($sql, $params);

    foreach ($records as $r) {
        $edition = manager::get_workshop_edition((int)$r->editionid);
        $workshop = manager::get_workshop((int)$r->workshopid);
        [$grade, $result] = local_ga_wr_activity_status(
            $edition,
            $workshop,
            (int)$r->userid,
            $r->attended
        );
        $attendance = $r->attended === null
            ? 'Sin registrar'
            : (!empty($r->attended) ? 'Presente' : 'Ausente');
        $reportrows[] = [
            academic_year::for_row((int)($r->seriesid ?? 0), (int)($r->sessiondate ?? 0)),
            (string)$r->lastname,
            (string)$r->firstname,
            (string)$r->email,
            trim((string)$r->workshopcode . ' - ' . (string)$r->workshopname, ' -'),
            local_ga_wr_series_title((int)($r->seriesid ?? 0), (string)($r->editionname ?: '-')),
            format_float((float)$r->hours, 2, true),
            $grade,
            $attendance,
            $result,
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
        ['class' => 'btn local-ga-back-panel mb-3']
    )
);

echo $OUTPUT->heading('Listado personalizado de talleres');
echo html_writer::tag(
    'p',
    'Selecciona uno, varios o todos los talleres. Columnas: Apellidos, Nombre, Correo, Taller, Edición, Horas, Calificación (nota del cuestionario en Tipo A; reflexión entregada o pendiente en Tipo B), Asistencia y Resultado (Apto / No apto). Descargable en CSV para Excel.',
    ['class' => 'alert alert-info']
);

echo academic_year::selector(new moodle_url('/local/gestion_actividades/workshop_report.php'), $ay, 'Muestra los talleres de ese curso para seleccionarlos.');
if (!$editions) {
    echo $OUTPUT->notification('No hay talleres en el curso académico seleccionado.', 'info');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'card mb-4']);
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', 'Seleccionar talleres', ['class' => 'h5']);
echo html_writer::tag(
    'div',
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

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => academic_year::PARAM, 'value' => academic_year::param_value($ay)]);
echo html_writer::tag('button', 'Generar listado', ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$PAGE->requires->js_init_code("document.getElementById('ga-select-all').addEventListener('click',function(){document.querySelectorAll('.ga-edition-check').forEach(function(c){c.checked=true;});});document.getElementById('ga-clear-all').addEventListener('click',function(){document.querySelectorAll('.ga-edition-check').forEach(function(c){c.checked=false;});});");

if ($selected) {
    echo html_writer::tag('h3', 'Resultado', ['class' => 'h4']);
    $exportparams = ['export' => 1, 'sesskey' => sesskey()] + academic_year::url_params($ay);
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
        echo $OUTPUT->notification('Los talleres seleccionados no tienen alumnado inscrito.', 'info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Curso académico', 'Apellidos', 'Nombre', 'Correo', 'Taller', 'Edición', 'Horas', 'Calificación', 'Asistencia', 'Resultado'];
        foreach ($reportrows as $row) {
            $table->data[] = array_map('s', $row);
        }
        echo html_writer::table($table);
    }
}

if (!function_exists('local_gestion_actividades_enable_interactive_tables')) {
    require_once($CFG->dirroot . '/local/gestion_actividades/lib.php');
}
local_gestion_actividades_enable_interactive_tables();
echo $OUTPUT->footer();
