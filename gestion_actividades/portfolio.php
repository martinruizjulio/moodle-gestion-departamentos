<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\portfolio_typeb;
use local_gestion_actividades\local\institutional_hours;
use local_gestion_actividades\local\grade_manager;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/portfolio.php'));
$PAGE->set_title('Mi portafolio HEE');
$PAGE->set_heading('Gestión HEE');

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_student_return_course_button(): string {
    global $DB;
    $courseid = optional_param('courseid', 0, PARAM_INT);
    if ($courseid > 1 && $DB->record_exists('course', ['id' => $courseid])) {
        return html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), local_ga_btn_icon('t/left', 'Volver al curso'), ['class' => 'btn btn-outline-secondary']);
    }
    return html_writer::link('javascript:history.back();', local_ga_btn_icon('t/left', 'Volver al curso'), ['class' => 'btn btn-outline-secondary']);
}

function local_ga_portfolio_badge(string $status): string {
    if ($status === 'generated' || $status === 'validated') {
        return html_writer::span($status === 'generated' ? 'Generado' : 'Validado', 'badge bg-success', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
    }
    if ($status === 'pending') {
        return html_writer::span('Pendiente de revisión', 'badge bg-warning text-dark', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
    }
    if ($status === 'rejected') {
        return html_writer::span('Rechazado', 'badge bg-danger', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
    }
    return html_writer::span(s($status), 'badge bg-secondary', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'save_institutional_typeb_reflection' && data_submitted()) {
    require_sesskey();
    $recordid = required_param('institutionalrecordid', PARAM_INT);
    $reflectiontext = required_param('institutionaltypebreflection', PARAM_TEXT);
    $saved = institutional_hours::save_typeb_reflection($recordid, (int)$USER->id, $reflectiontext);
    $redirectparams = [];
    $returncourseid = optional_param('courseid', 0, PARAM_INT);
    if ($returncourseid > 0) {
        $redirectparams['courseid'] = $returncourseid;
    }
    redirect(
        new moodle_url('/local/gestion_actividades/portfolio.php', $redirectparams),
        $saved ? 'Comentario del reconocimiento institucional Tipo B guardado.' : 'No se ha podido guardar el comentario.',
        null,
        $saved ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

function local_ga_typea_hours_from_certificates(array $certificates): float {
    $total = 0.0;
    foreach ($certificates as $c) {
        if (isset($c->hours) && $c->hours !== null && $c->hours !== '') {
            $total += (float)$c->hours;
        }
    }
    return $total;
}


function local_ga_student_progress_block(float $typeahours, float $typebvalidatedhours, float $typebuploadedhours, int $typeacount, int $typebcount): block_contents {
    $requiredhours = 54.0;
    $totalvalidated = $typeahours + $typebvalidatedhours;
    $pending = max(0.0, $requiredhours - $totalvalidated);
    $percent = $requiredhours > 0 ? min(100, round(($totalvalidated / $requiredhours) * 100)) : 0;

    $content = html_writer::start_div('local-ga-student-progress');
    $content .= html_writer::tag('div', round($totalvalidated, 2) . ' / ' . round($requiredhours, 2) . ' h', ['style' => 'font-size:1.35rem;font-weight:700;margin-bottom:6px;']);
    $content .= html_writer::start_div('progress mb-2', ['style' => 'height:18px;']);
    $content .= html_writer::div($percent . '%', 'progress-bar', ['role' => 'progressbar', 'style' => 'width:' . $percent . '%;', 'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100]);
    $content .= html_writer::end_div();
    $content .= html_writer::tag('p', $pending > 0 ? 'Te faltan ' . round($pending, 2) . ' h para completar las 54 h.' : 'Objetivo de 54 h completado.', ['class' => $pending > 0 ? 'text-muted' : 'text-success font-weight-bold']);
    $content .= html_writer::tag('hr', '');
    $content .= html_writer::tag('p', '<strong>Tipo A:</strong> ' . round($typeahours, 2) . ' h · ' . (int)$typeacount . ' certificado(s)', ['class' => 'mb-1']);
    $content .= html_writer::tag('p', '<strong>Tipo B validado:</strong> ' . round($typebvalidatedhours, 2) . ' h', ['class' => 'mb-1']);
    $content .= html_writer::tag('p', '<strong>Tipo B subido:</strong> ' . round($typebuploadedhours, 2) . ' h · ' . (int)$typebcount . ' certificado(s)', ['class' => 'mb-2']);
    $content .= html_writer::link(new moodle_url('/local/gestion_actividades/portfolio.php'), local_ga_btn_icon('i/report', 'Ver portafolio'), ['class' => 'btn btn-primary btn-sm d-block w-100 mb-1']);
    $content .= html_writer::link(new moodle_url('/local/gestion_actividades/transfer_typeb.php'), local_ga_btn_icon('t/right', 'Traspasar A a B'), ['class' => 'btn btn-secondary btn-sm d-block w-100']);
    $content .= html_writer::end_div();

    $block = new block_contents();
    $block->title = 'Mis horas de talleres';
    $block->content = $content;
    $block->attributes['class'] = 'local-ga-student-progress-block';
    return $block;
}

$typeacerts = method_exists(manager::class, 'list_user_certificates') ? manager::list_user_certificates((int)$USER->id) : [];
$institutionalrecords = institutional_hours::list_for_user((int)$USER->id);
$institutionaltypeahours = institutional_hours::total_typea_hours((int)$USER->id);
$institutionaltypebhours = institutional_hours::total_typeb_hours((int)$USER->id);

$typebworkshopcerts = manager::list_user_typeb_workshop_certificates((int)$USER->id);
$typebcerts = portfolio_typeb::list_for_user((int)$USER->id);
$typebtransferdata = method_exists(manager::class, 'get_user_typeb_transfer_totals') ? manager::get_user_typeb_transfer_totals((int)$USER->id) : (object)['count' => 0, 'hours' => 0.0];
// Canonical figures shared with the block, the gradebook and the reports.
$hourssummary = \local_gestion_actividades\local\hours_calculator::for_user((int)$USER->id);
$typeahours = (float)$hourssummary->typeahours;
$typebvalidatedhours = (float)$hourssummary->typebhours;
$typebuploadedhours = portfolio_typeb::total_uploaded_hours((int)$USER->id);
$totalvalidated = (float)$hourssummary->totalhours;
$requiredhours = 54.0;
$pendinghours = max(0.0, $requiredhours - $totalvalidated);

// Resolve the HEE course whose gradebook must receive and display this student's grades.
$managedcourses = grade_manager::get_managed_courses();
$gradecourseid = optional_param('courseid', 0, PARAM_INT);
if ($gradecourseid > 0) {
    $candidatecontext = isset($managedcourses[$gradecourseid])
        ? context_course::instance($gradecourseid, IGNORE_MISSING)
        : false;
    if (!$candidatecontext || !is_enrolled($candidatecontext, (int)$USER->id, '', true)) {
        $gradecourseid = 0;
    }
}
if ($gradecourseid <= 0) {
    foreach ($managedcourses as $candidatecourse) {
        $candidatecontext = context_course::instance((int)$candidatecourse->id, IGNORE_MISSING);
        if ($candidatecontext && is_enrolled($candidatecontext, (int)$USER->id, '', true)) {
            $gradecourseid = (int)$candidatecourse->id;
            break;
        }
    }
}
$gradesummary = null;
$selfassessmentinfo = null;
if ($gradecourseid > 0) {
    $gradesummary = grade_manager::get_user_grade_summary($gradecourseid, (int)$USER->id, false);
    $selfassessmentinfo = grade_manager::get_selfassessment_info($gradecourseid);
}

$PAGE->blocks->add_fake_block(local_ga_student_progress_block((float)$typeahours, (float)$typebvalidatedhours, (float)$typebuploadedhours, count($typeacerts), count($typebcerts)), 'side-pre');


echo $OUTPUT->header();

echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap mb-3');
echo html_writer::tag('h1', 'Mi portafolio HEE', ['class' => 'mb-2']);
echo html_writer::div(local_ga_student_return_course_button(), 'mb-2');
echo html_writer::end_div();

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body d-flex justify-content-between align-items-center flex-wrap');
echo html_writer::start_div('mb-2');
echo html_writer::tag('h2', 'Previsualización del portafolio', ['class' => 'h4 mb-1']);
echo html_writer::tag('p', 'Consulta aquí tu resumen de horas, talleres, reconocimiento institucional y traspasos. Puedes descargar el portafolio o el expediente completo desde esta misma pantalla.', ['class' => 'text-muted mb-0']);
echo html_writer::end_div();
echo html_writer::start_div('mb-2');
echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_pdf_download.php'), local_ga_btn_icon('t/download', 'Descargar portafolio PDF'), ['class' => 'btn btn-primary me-2 mb-2']);
echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_package_download.php'), local_ga_btn_icon('t/download', 'Descargar expediente ZIP'), ['class' => 'btn btn-primary mb-2']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

if ($gradesummary) {
    $formatgrade = static function($value): string {
        return ($value === null || $value === '')
            ? html_writer::span('Pendiente', 'badge bg-warning text-dark')
            : html_writer::span(format_float((float)$value, 2, true) . ' / 10', 'font-weight-bold');
    };
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h2', 'Mis calificaciones HEE', ['class' => 'h4 mb-1']);
    echo html_writer::tag('p', 'La Nota Final se publica cuando están disponibles las tres calificaciones: Talleres A (60%), Portafolio (30%) y Autoevaluación (10%).', ['class' => 'text-muted']);
    echo html_writer::start_div('row');
    $gradecards = [
        ['Nota Talleres A', $gradesummary->typeagrade, 'Media de las tareas Tipo A calificadas'],
        ['Portafolio', $gradesummary->portfoliograde, '10 al completar 54 h y los comentarios Tipo B'],
        ['Autoevaluación', $gradesummary->autoevaluationgrade, $selfassessmentinfo ? format_string($selfassessmentinfo->name) : 'Cuestionario pendiente de vincular'],
        ['Nota Final', $gradesummary->finalgrade, '60% + 30% + 10%'],
    ];
    foreach ($gradecards as $gradecard) {
        echo html_writer::start_div('col-md-3 mb-2');
        echo html_writer::start_div('border rounded p-3 h-100');
        echo html_writer::tag('h3', s($gradecard[0]), ['class' => 'h6']);
        echo html_writer::div($formatgrade($gradecard[1]), 'mb-2');
        echo html_writer::tag('div', s($gradecard[2]), ['class' => 'text-muted small']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_div('alert alert-info');
echo html_writer::tag('strong', 'Objetivo: 54 horas. ');
echo 'Tienes reconocidas ' . round((float)$totalvalidated, 2) . ' h. ';
echo $pendinghours > 0 ? 'Te faltan ' . round((float)$pendinghours, 2) . ' h.' : 'Ya has completado el objetivo.';
echo html_writer::end_div();

echo html_writer::start_div('row mb-3');
$cards = [
    ['Talleres Tipo A', round((float)$typeahours, 2) . ' h', 'Sistema + reconocimiento institucional'],
    ['Talleres Tipo B', round((float)$typebvalidatedhours, 2) . ' h', 'Gestionadas + traspasos + reconocimiento institucional'],
    ['Horas pendientes', round((float)$pendinghours, 2) . ' h', 'Hasta completar 54 horas'],
];
foreach ($cards as $card) {
    echo html_writer::start_div('col-md-4 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', s($card[0]), ['class' => 'card-title']);
    echo html_writer::tag('div', s($card[1]), ['style' => 'font-size:2rem;font-weight:700;']);
    echo html_writer::tag('p', s($card[2]), ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::tag('h2', 'Talleres Tipo A');
echo html_writer::tag('p', 'Certificados generados automáticamente por el sistema cuando se cumplen los requisitos del taller.', ['class' => 'text-muted']);
if ($typeacerts) {
    $table = new html_table();
    $table->head = ['Curso', 'Taller', 'Horas', 'Fecha de emisión', 'Estado', 'Acciones'];
    foreach ($typeacerts as $c) {
        $url = new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $c->id]);
        $table->data[] = [
            format_string($c->coursename),
            s($c->workshopcode . ' - ' . $c->workshopname),
            !empty($c->hours) ? s((float)$c->hours) . ' h' : '-',
            userdate((int)$c->timeissued),
            local_ga_portfolio_badge($c->status ?: 'generated'),
            html_writer::link($url, local_ga_btn_icon('t/download', 'Descargar PDF'), ['class' => 'btn btn-primary btn-sm']),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no tienes certificados Tipo A generados.', 'info');
}

echo html_writer::tag('h2', 'Reconocimiento institucional', ['class' => 'mt-4']);
echo html_writer::tag('p', 'Horas reconocidas previamente por el Decanato de la Facultad. En Tipo A se muestra la tarea y la nota importada; en Tipo B solo se reconoce la asistencia y el alumno debe cumplimentar su comentario.', ['class' => 'text-muted']);
if (!empty($institutionalrecords)) {
    $table = new html_table();
    $table->head = ['Concepto', 'Origen', 'Curso', 'Grupo', 'Horas', 'Asistencia', 'Tarea', 'Nota Taller A', 'Comentario Tipo B'];
    foreach ($institutionalrecords as $r) {
        if ((float)$r->typeahours > 0) {
            $table->data[] = ['Reconocimiento institucional - Horas Tipo A', s($r->source), s($r->courselevel ?? '-'), s($r->groupname ?? '-'), format_float((float)$r->typeahours, 2, true) . ' h', 'Confirmada', 'Entregada', ($r->taskgrade !== null && $r->taskgrade !== '') ? format_float((float)$r->taskgrade, 2, true) : '-', '-'];
        }
        if ((float)$r->typebhours > 0) {
            $reflection = trim((string)($r->typebreflection ?? ''));
            $reflectionstatus = $reflection !== ''
                ? html_writer::span('Cumplimentado', 'badge bg-success')
                : html_writer::span('Pendiente', 'badge bg-warning text-dark');
            $table->data[] = ['Reconocimiento institucional - Horas Tipo B', s($r->source), s($r->courselevel ?? '-'), s($r->groupname ?? '-'), format_float((float)$r->typebhours, 2, true) . ' h', 'Confirmada', 'No aplica', '-', $reflectionstatus];
        }
    }
    if (!empty($table->data)) {
        echo html_writer::table($table);
    }
    foreach ($institutionalrecords as $r) {
        if ((float)$r->typebhours <= 0) {
            continue;
        }
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', 'Comentario del reconocimiento institucional Tipo B', ['class' => 'h5']);
        echo html_writer::tag('p', 'Explica en qué consistieron las actividades reconocidas y cuál es su principal utilidad. Este comentario es obligatorio para completar el portafolio.', ['class' => 'text-muted']);
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/gestion_actividades/portfolio.php')]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_institutional_typeb_reflection']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'institutionalrecordid', 'value' => (int)$r->id]);
        if ($gradecourseid > 0) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $gradecourseid]);
        }
        echo html_writer::tag('textarea', s((string)($r->typebreflection ?? '')), ['name' => 'institutionaltypebreflection', 'class' => 'form-control mb-2', 'rows' => 6, 'required' => 'required', 'maxlength' => 5000]);
        echo html_writer::tag('button', local_ga_btn_icon('t/save', 'Guardar comentario'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
} else {
    echo $OUTPUT->notification('No constan horas de reconocimiento institucional importadas.', 'info');
}



echo html_writer::tag('h2', 'Talleres Tipo B', ['class' => 'mt-4']);
echo html_writer::tag('p', 'Talleres Tipo B con asistencia confirmada, texto obligatorio del alumno y certificado emitido.', ['class' => 'text-muted']);
if ($typebworkshopcerts) {
    $table = new html_table();
    $table->head = ['Curso', 'Taller', 'Horas', 'Asistencia', 'Texto alumno', 'Fecha de emisión', 'Acciones'];
    foreach ($typebworkshopcerts as $c) {
        $url = new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $c->id]);
        $table->data[] = [
            format_string($c->coursename ?? ''),
            s(($c->workshopcode ?? '') . ' - ' . ($c->workshopname ?? '')),
            !empty($c->hours) ? s((float)$c->hours) . ' h' : '-',
            'Confirmada',
            !empty($c->reflectiontext) ? format_text($c->reflectiontext, FORMAT_PLAIN) : '-',
            userdate((int)$c->timeissued),
            html_writer::link($url, local_ga_btn_icon('t/download', 'Descargar PDF'), ['class' => 'btn btn-primary btn-sm']),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no tienes certificados de Talleres Tipo B.', 'info');
}

echo html_writer::tag('h2', 'Traspasos de Tipo A a Tipo B', ['class' => 'mt-4']);
echo html_writer::tag('p', 'Talleres Tipo A certificados que has traspasado a Tipo B. Estas horas dejan de contar como Tipo A y pasan a contar como Tipo B.', ['class' => 'text-muted']);
$transferrows = method_exists(manager::class, 'list_user_typeb_transfers') ? manager::list_user_typeb_transfers((int)$USER->id) : [];
if ($transferrows) {
    $table = new html_table();
    $table->head = ['Taller A traspasado', 'Horas', 'Texto obligatorio', 'Fecha'];
    foreach ($transferrows as $r) {
        $table->data[] = [
            s(trim((string)($r->workshopcode ?? '') . ' - ' . (string)($r->workshopname ?? ''))),
            format_float((float)($r->hours ?? 0), 2, true) . ' h',
            format_text((string)($r->reflectiontext ?? ''), FORMAT_PLAIN),
            !empty($r->timecreated) ? userdate((int)$r->timecreated) : '-',
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no has traspasado ningún Taller Tipo A a Tipo B.', 'info');
}


// Flujo antiguo de Talleres Tipo B externos eliminado: ya no se permite subir certificados externos desde el portafolio.

echo $OUTPUT->footer();
