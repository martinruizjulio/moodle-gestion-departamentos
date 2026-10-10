<?php
// Buscar alumno: ficha completa con todo su historial HEE (talleres,
// asistencia, calificaciones, certificados, Tipo B externo, traspasos y
// reconocimiento institucional) para comprobar si falta algo. Consulta por defecto; los botones Editar abren los controles canónicos.
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\academic_year;
use local_gestion_actividades\local\portfolio_typeb;
use local_gestion_actividades\local\typeb_reflection_activity;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$q = trim(optional_param('q', '', PARAM_TEXT));
$userid = optional_param('userid', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/student_search.php', array_filter(['q' => $q, 'userid' => $userid])));
$PAGE->set_title('Buscar alumno');
$PAGE->set_heading('Gestión HEE');

function local_ga_ss_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_ss_badge(string $text, string $class): string {
    return html_writer::span(s($text), 'badge ' . $class);
}

/** [Calificación / estado, Resultado] of a student in an edition. */
function local_ga_ss_activity_status(stdClass $edition, stdClass $workshop, int $userid, $attended): array {
    global $DB;
    $attendanceok = !empty($attended);
    $type = manager::normalize_workshop_type((string)($workshop->workshoptype ?? 'typea'));
    try {
        if ($type === 'typeb') {
            $summary = typeb_reflection_activity::submission_summary((int)$edition->id, $userid);
            $submitted = !empty($summary->submitted);
            return [$submitted ? 'Reflexión entregada' : 'Reflexión pendiente',
                ($attendanceok && $submitted) ? 'Apto' : ($submitted ? 'No apto' : 'Pendiente')];
        }
        if (in_array('quiz', manager::get_required_activity_types($edition), true)) {
            $requirement = manager::get_quiz_requirement($edition);
            $cmid = (int)$requirement->cmid;
            if ($cmid <= 0) {
                return ['Sin cuestionario', 'Pendiente'];
            }
            if (!manager::user_submitted_required_activity($userid, $cmid)) {
                return ['Cuestionario no realizado', 'Pendiente'];
            }
            $grade = manager::get_user_quiz_grade_out_of_10($userid, $cmid);
            if ($grade === null) {
                return ['Pendiente de calificar', 'Pendiente'];
            }
            return [format_float((float)$grade, 2, true) . ' / 10',
                ($attendanceok && (float)$grade >= (float)$requirement->minimum) ? 'Apto' : 'No apto'];
        }
        if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_task_submissions'))) {
            $sub = $DB->get_record('local_ga_task_submissions', ['editionid' => (int)$edition->id, 'userid' => $userid], '*', IGNORE_MISSING);
            if (!$sub || empty($sub->fileitemid)) {
                return ['Tarea no entregada', 'Pendiente'];
            }
            if ($sub->grade === null || $sub->grade === '') {
                return ['Pendiente de calificar', 'Pendiente'];
            }
            return [format_float((float)$sub->grade, 2, true) . ' / 10', ($attendanceok && (float)$sub->grade >= 5.0) ? 'Apto' : 'No apto'];
        }
    } catch (Throwable $e) {
        return ['No disponible', 'Pendiente'];
    }
    return ['Sin actividad vinculada', 'Pendiente'];
}

function local_ga_ss_result_badge(string $result): string {
    if ($result === 'Apto') {
        return local_ga_ss_badge('Apto', 'bg-success');
    }
    if ($result === 'No apto') {
        return local_ga_ss_badge('No apto', 'bg-danger');
    }
    return local_ga_ss_badge($result, 'bg-warning text-dark');
}

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), local_ga_ss_icon('t/left', 'Volver al panel'), ['class' => 'btn local-ga-back-panel me-2 mb-3'])
    . ($userid > 0 && $q !== '' ? html_writer::link(new moodle_url('/local/gestion_actividades/student_search.php', ['q' => $q]), local_ga_ss_icon('t/left', 'Volver a resultados'), ['class' => 'btn local-ga-back-panel mb-3']) : ''),
    'mb-2');
echo $OUTPUT->heading('Buscar alumno');
echo html_writer::tag('p', 'Busca un alumno por nombre, apellidos, correo o DNI/ID para ver todo su historial HEE en una sola página: '
    . 'talleres en los que se inscribió (asistencia, calificación y resultado), certificados, Tipo B externo, traspasos, reconocimiento institucional y total de horas. '
    . 'La ficha es de consulta por defecto; usa «Editar» solo para casos especiales y se abrirán los controles canónicos de asistencia/calificación.', ['class' => 'alert alert-info']);

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4']);
echo html_writer::div(
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $q, 'class' => 'form-control',
        'placeholder' => 'Nombre, apellidos, correo o DNI', 'autofocus' => 'autofocus', 'aria-label' => 'Buscar alumno'])
    . html_writer::tag('button', local_ga_ss_icon('a/search', 'Buscar'), ['type' => 'submit', 'class' => 'btn btn-primary']),
    'input-group', ['style' => 'max-width:640px']);
echo html_writer::end_tag('form');

// Search results.
if ($userid <= 0 && $q !== '') {
    $params = [];
    $conds = [];
    $i = 0;
    foreach (preg_split('/\s+/', $q) as $word) {
        if ($word === '') {
            continue;
        }
        $i++;
        $like = '%' . $DB->sql_like_escape($word) . '%';
        $or = [];
        foreach (['firstname', 'lastname', 'email', 'idnumber', 'username'] as $f) {
            $or[] = $DB->sql_like($f, ":{$f}{$i}", false, false);
            $params["{$f}{$i}"] = $like;
        }
        $conds[] = '(' . implode(' OR ', $or) . ')';
    }
    $users = $conds ? $DB->get_records_select('user', 'deleted = 0 AND id > 1 AND ' . implode(' AND ', $conds), $params,
        'lastname ASC, firstname ASC', 'id, firstname, lastname, email, idnumber', 0, 100) : [];
    if (count($users) === 1) {
        $userid = (int)reset($users)->id;
    } else if ($users) {
        echo html_writer::tag('h3', count($users) . ' alumno(s) encontrado(s)' . (count($users) >= 100 ? ' (se muestran los 100 primeros; afina la búsqueda)' : ''), ['class' => 'h5']);
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Apellidos', 'Nombre', 'Correo', 'DNI / ID', 'Grupo', 'Horas totales', ''];
        foreach ($users as $u) {
            $h = \local_gestion_actividades\local\hours_calculator::for_user((int)$u->id);
            $table->data[] = [s($u->lastname), s($u->firstname), s($u->email), s($u->idnumber ?: '-'),
                s(local_gestion_actividades_student_group((int)$u->id)),
                format_float((float)$h->totalhours, 2, true) . ' h',
                html_writer::link(new moodle_url('/local/gestion_actividades/student_search.php', ['userid' => $u->id, 'q' => $q]),
                    local_ga_ss_icon('i/search', 'Ver historial'), ['class' => 'btn btn-sm btn-primary'])];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('No se ha encontrado ningún alumno con «' . s($q) . '».', 'info');
    }
}

$user = $userid > 0 ? $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING) : null;
if ($user) {
    $hours = \local_gestion_actividades\local\hours_calculator::for_user((int)$user->id);

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', s(fullname($user)), ['class' => 'h4 mb-1']);
    echo html_writer::div(s($user->email) . ($user->idnumber ? ' · DNI/ID: ' . s($user->idnumber) : '')
        . ' · Grupo: ' . s(local_gestion_actividades_student_group((int)$user->id))
        . ' · Cursos con actividad: ' . s(academic_year::user_years_text((int)$user->id)), 'text-muted mb-3');
    $kpi = static function(string $label, float $value, string $style = ''): string {
        return html_writer::div(html_writer::div(s($label), 'small text-muted')
            . html_writer::div(format_float($value, 2, true) . ' h', 'h4 mb-0'), 'border rounded p-2 me-2 mb-2', ['style' => 'min-width:150px;' . $style]);
    };
    echo html_writer::div(
        $kpi('Horas Tipo A', (float)$hours->typeahours)
        . $kpi('Horas Tipo B', (float)$hours->typebhours)
        . $kpi('Total reconocido', (float)$hours->totalhours, 'background:#eef7ea')
        . $kpi('Pendiente hasta ' . (int)$hours->target, (float)$hours->remaining),
        'd-flex flex-wrap');
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['userid' => $user->id]), local_ga_ss_icon('i/report', 'Portafolio y Tipo B'), ['class' => 'btn btn-outline-primary me-1 mb-1'])
        . html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_pdf_download.php', ['userid' => $user->id]), local_ga_ss_icon('t/download', 'Portafolio PDF'), ['class' => 'btn btn-outline-secondary me-1 mb-1'])
        . html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_package_download.php', ['userid' => $user->id]), local_ga_ss_icon('t/download', 'Expediente completo ZIP'), ['class' => 'btn btn-outline-secondary me-1 mb-1'])
        . html_writer::link(new moodle_url('/user/profile.php', ['id' => $user->id]), local_ga_ss_icon('i/user', 'Perfil Moodle'), ['class' => 'btn btn-outline-secondary mb-1']),
        'mt-2');
    echo html_writer::end_div() . html_writer::end_div();

    // 1. Talleres (every enrolment, active or not).
    echo html_writer::tag('h3', 'Talleres en los que se inscribió', ['class' => 'h5']);
    $hasseries = array_key_exists('seriesid', $DB->get_columns('local_ga_workshop_editions'));
    $enrolments = $DB->get_records_sql("SELECT ee.id AS enrolid, ee.status AS enrolstatus, ee.attended, ee.editionid,
                                               e.sessiondate, e.name AS editionname, " . ($hasseries ? 'e.seriesid' : '0 AS seriesid') . ",
                                               w.id AS workshopid, w.code, w.name AS workshopname, w.hours, w.workshoptype,
                                               c.fullname AS coursename
                                          FROM {local_ga_edition_enrolments} ee
                                          JOIN {local_ga_workshop_editions} e ON e.id = ee.editionid
                                          JOIN {local_ga_workshops} w ON w.id = e.workshopid
                                     LEFT JOIN {course} c ON c.id = w.courseid
                                         WHERE ee.userid = :userid
                                      ORDER BY e.sessiondate DESC, ee.id DESC", ['userid' => $user->id]);
    $certbyedition = [];
    if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_certificates'))) {
        foreach ($DB->get_records('local_ga_certificates', ['userid' => $user->id]) as $cert) {
            $certbyedition[(int)$cert->editionid] = $cert;
        }
    }
    if ($enrolments) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Curso académico', 'Edición de talleres', 'Tipo', 'Taller', 'Fecha', 'Horas', 'Inscripción', 'Asistencia', 'Calificación / actividad', 'Resultado', 'Certificado', 'Editar'];
        foreach ($enrolments as $r) {
            $series = (int)$r->seriesid > 0 ? $DB->get_record('local_ga_workshop_series', ['id' => (int)$r->seriesid], 'id, title', IGNORE_MISSING) : null;
            $active = in_array((string)($r->enrolstatus ?? ''), ['', 'enrolled', 'attended', 'manual'], true);
            $attendance = $r->attended === null ? local_ga_ss_badge('Sin registrar', 'bg-secondary')
                : (!empty($r->attended) ? local_ga_ss_badge('Presente', 'bg-success') : local_ga_ss_badge('Ausente', 'bg-danger'));
            [$status, $result] = local_ga_ss_activity_status(manager::get_workshop_edition((int)$r->editionid),
                manager::get_workshop((int)$r->workshopid), (int)$user->id, $r->attended);
            $cert = $certbyedition[(int)$r->editionid] ?? null;
            $certcell = $cert
                ? html_writer::link(new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $cert->id]), local_ga_ss_icon('t/download', 'Sí'), ['class' => 'btn btn-sm btn-outline-success'])
                : local_ga_ss_badge('No', 'bg-light text-dark border');
            $istypeb = manager::normalize_workshop_type((string)$r->workshoptype) === 'typeb';
            $editactions = html_writer::link(
                new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => (int)$r->editionid]),
                local_ga_ss_icon('t/edit', 'Asistencia'),
                ['class' => 'btn btn-sm btn-outline-secondary me-1 mb-1']
            );
            $editactions .= html_writer::link(
                new moodle_url('/local/gestion_actividades/teacher_view.php', [
                    'id' => (int)$r->workshopid,
                    'editionid' => (int)$r->editionid,
                ]),
                local_ga_ss_icon('t/edit', $istypeb ? 'Actividad' : 'Calificación'),
                ['class' => 'btn btn-sm btn-outline-primary mb-1']
            );
            $table->data[] = [
                academic_year::for_row((int)$r->seriesid, (int)$r->sessiondate),
                s($series->title ?? ($r->editionname ?: '-')),
                local_ga_ss_badge($istypeb ? 'Tipo B' : 'Tipo A', $istypeb ? 'bg-primary' : 'bg-success'),
                s(trim($r->code . ' - ' . $r->workshopname, ' -')),
                !empty($r->sessiondate) ? userdate((int)$r->sessiondate, '%d/%m/%Y') : '-',
                format_float((float)$r->hours, 2, true) . ' h',
                $active ? local_ga_ss_badge('Inscrito', 'bg-light text-dark border') : local_ga_ss_badge('Baja (' . $r->enrolstatus . ')', 'bg-secondary'),
                $attendance,
                s($status),
                local_ga_ss_result_badge($result),
                $certcell,
                $editactions,
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('No consta inscrito en ningún taller.', 'info');
    }

    // 2. Tipo B PAT histórico.
    echo html_writer::tag('h3', 'Tipo B PAT (1.º curso)', ['class' => 'h5 mt-4']);
    $patrows = \local_gestion_actividades\local\pat_typeb::for_user((int)$user->id);
    if ($patrows) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Curso académico', 'Taller PAT', 'Asistencia', 'Nota cuestionario', 'Horas', 'Resultado'];
        foreach ($patrows as $p) {
            $attendance = $p->attendance === null ? '-' : format_float((float)$p->attendance, 2, true) . '%';
            $grade = $p->grade === null ? '-' : format_float((float)$p->grade, 2, true) . ' / 10';
            $table->data[] = [
                s($p->academicyear),
                s($p->workshopname),
                $attendance,
                $grade,
                format_float((float)$p->hours, 2, true) . ' h',
                !empty($p->passed) ? local_ga_ss_badge('Apto', 'bg-success') : local_ga_ss_badge('No apto', 'bg-danger'),
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('No tiene talleres Tipo B PAT vinculados.', 'info');
    }

    // 3. Tipo B externo.
    echo html_writer::tag('h3', 'Tipo B externo (solicitudes)', ['class' => 'h5 mt-4']);
    $typeb = portfolio_typeb::list_for_user((int)$user->id);
    if ($typeb) {
        $labels = ['pending' => ['Pendiente de validar', 'bg-warning text-dark'], 'validated' => ['Validado y completado', 'bg-success'],
            'rejected' => ['Rechazado', 'bg-danger'], portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION => ['Validado · falta reflexión', 'bg-warning text-dark']];
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Curso académico', 'Taller', 'Fecha realización', 'Horas', 'Estado', 'Reflexión', 'Comentario gestor', 'Certificado'];
        foreach ($typeb as $c) {
            [$label, $class] = $labels[(string)$c->status] ?? [(string)$c->status, 'bg-secondary'];
            $table->data[] = [
                academic_year::for_time((int)($c->timecreated ?? 0)),
                s($c->activityname),
                !empty($c->activitydate) ? userdate((int)$c->activitydate, '%d/%m/%Y') : '-',
                format_float((float)$c->hours, 2, true) . ' h',
                local_ga_ss_badge($label, $class),
                trim((string)($c->reflectiontext ?? '')) !== '' ? local_ga_ss_badge('Entregada', 'bg-success') : local_ga_ss_badge('Pendiente', 'bg-warning text-dark'),
                s($c->reviewcomment ?: '-'),
                html_writer::link(new moodle_url('/local/gestion_actividades/typeb_view.php', ['id' => $c->id]), local_ga_ss_icon('t/preview', 'Ver'), ['class' => 'btn btn-sm btn-outline-secondary', 'target' => '_blank']),
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('No ha presentado solicitudes de Tipo B externo.', 'info');
    }

    // 4. Traspasos A→B.
    echo html_writer::tag('h3', 'Traspasos Tipo A → Tipo B', ['class' => 'h5 mt-4']);
    $transfers = array_filter(manager::list_all_typeb_transfers(), static fn($t) => (int)$t->userid === (int)$user->id);
    if ($transfers) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Curso académico', 'Taller A traspasado', 'Horas', 'Fecha', 'Reflexión'];
        foreach ($transfers as $t) {
            $table->data[] = [academic_year::for_time((int)$t->timecreated), s(trim($t->workshopcode . ' - ' . $t->workshopname, ' -')),
                format_float((float)$t->hours, 2, true) . ' h', userdate((int)$t->timecreated, '%d/%m/%Y'),
                s(\core_text::substr(manager::reflection_plain($t->reflectiontext ?? ''), 0, 200))];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('No ha hecho traspasos.', 'info');
    }

    // 5. Reconocimiento institucional.
    echo html_writer::tag('h3', 'Reconocimiento institucional (horas previas importadas)', ['class' => 'h5 mt-4']);
    $inst = $DB->get_manager()->table_exists(new xmldb_table('local_ga_institutional_hours'))
        ? $DB->get_records('local_ga_institutional_hours', ['userid' => $user->id]) : [];
    if ($inst) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Curso académico', 'Horas Tipo A', 'Horas Tipo B', 'Nota actividad', 'Origen', 'Importado'];
        foreach ($inst as $r) {
            $table->data[] = [academic_year::for_time((int)$r->timemodified), format_float((float)$r->typeahours, 2, true) . ' h',
                format_float((float)$r->typebhours, 2, true) . ' h', $r->taskgrade !== null && $r->taskgrade !== '' ? format_float((float)$r->taskgrade, 2, true) : '-',
                s($r->source ?: '-'), userdate((int)$r->timemodified, '%d/%m/%Y')];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('No tiene reconocimiento institucional importado.', 'info');
    }
} else if ($userid > 0) {
    echo $OUTPUT->notification('El alumno no existe o ha sido eliminado.', 'warning');
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
