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
 * portfolio.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
    // Always the course (never «browser back», which could land on another HEE page).
    return \local_gestion_actividades\local\manager::back_to_course_button();
}

function local_ga_portfolio_badge(string $status): string {
    if ($status === 'generated') {
        return html_writer::span('Generado', 'badge bg-success');
    }
    if ($status === 'validated') {
        return html_writer::span('Validado y completado', 'badge bg-success');
    }
    if ($status === portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION) {
        return html_writer::span('Validado · falta reflexión', 'badge bg-warning text-dark');
    }
    if ($status === 'pending') {
        return html_writer::span('Pendiente de validar', 'badge bg-warning text-dark');
    }
    if ($status === 'rejected') {
        return html_writer::span('Rechazado', 'badge bg-danger');
    }
    return html_writer::span(s($status), 'badge bg-secondary');
}

function local_ga_student_progress_block(
    float $typeahours,
    float $typebvalidatedhours,
    float $typebuploadedhours,
    int $typeacount,
    int $typebcount
): block_contents {
    $requiredhours = 54.0;
    $totalvalidated = $typeahours + $typebvalidatedhours;
    $pending = max(0.0, $requiredhours - $totalvalidated);
    $percent = $requiredhours > 0 ? min(100, round(($totalvalidated / $requiredhours) * 100)) : 0;

    $content = html_writer::start_div('local-ga-student-progress');
    $content .= html_writer::tag(
        'div',
        round($totalvalidated, 2) . ' / ' . round($requiredhours, 2) . ' h',
        ['style' => 'font-size:1.35rem;font-weight:700;margin-bottom:6px;']
    );
    $content .= html_writer::start_div('progress mb-2', ['style' => 'height:18px;']);
    $content .= html_writer::div(
        $percent . '%',
        'progress-bar',
        [
            'role' => 'progressbar',
            'style' => 'width:' . $percent . '%;',
            'aria-valuenow' => $percent,
            'aria-valuemin' => 0,
            'aria-valuemax' => 100,
        ]
    );
    $content .= html_writer::end_div();
    $content .= html_writer::tag(
        'p',
        $pending > 0
            ? 'Te faltan ' . round($pending, 2) . ' h para completar las 54 h.'
            : 'Objetivo de 54 h completado.',
        ['class' => $pending > 0 ? 'text-muted' : 'text-success fw-bold']
    );
    $content .= html_writer::tag('hr', '');
    $content .= html_writer::tag(
        'p',
        '<strong>Tipo A:</strong> ' . round($typeahours, 2) . ' h · ' . (int)$typeacount . ' certificado(s)',
        ['class' => 'mb-1']
    );
    $content .= html_writer::tag(
        'p',
        '<strong>Tipo B reconocido:</strong> ' . round($typebvalidatedhours, 2) . ' h',
        ['class' => 'mb-1']
    );
    $content .= html_writer::tag(
        'p',
        '<strong>Tipo B externo enviado:</strong> ' . round($typebuploadedhours, 2) . ' h · ' . (int)$typebcount . ' solicitud(es)',
        ['class' => 'mb-2']
    );
    $content .= html_writer::link(
        new moodle_url('/local/gestion_actividades/portfolio.php'),
        local_ga_btn_icon('i/report', 'Ver portafolio'),
        ['class' => 'btn btn-primary btn-sm d-block w-100 mb-1']
    );
    $content .= html_writer::link(
        new moodle_url('/local/gestion_actividades/transfer_typeb.php'),
        local_ga_btn_icon('t/right', 'Traspasar A a B'),
        ['class' => 'btn btn-secondary btn-sm d-block w-100']
    );
    $content .= html_writer::end_div();

    $block = new block_contents();
    $block->title = 'Mis horas HEE';
    $block->content = $content;
    $block->attributes['class'] = 'local-ga-student-progress-block';
    return $block;
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
        $saved
            ? 'Comentario del reconocimiento institucional Tipo B guardado.'
            : 'No se ha podido guardar el comentario.',
        null,
        $saved ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

$typeacerts = manager::list_user_certificates((int)$USER->id);
$typebworkshopcerts = manager::list_user_typeb_workshop_certificates((int)$USER->id);
$typebcerts = portfolio_typeb::list_for_user((int)$USER->id);
$institutionalrecords = institutional_hours::list_for_user((int)$USER->id);

$hourssummary = \local_gestion_actividades\local\hours_calculator::for_user((int)$USER->id);
$typeahours = (float)$hourssummary->typeahours;
$typebvalidatedhours = (float)$hourssummary->typebhours;
$typebuploadedhours = portfolio_typeb::total_uploaded_hours((int)$USER->id);
$totalvalidated = (float)$hourssummary->totalhours;
$requiredhours = 54.0;
$pendinghours = max(0.0, $requiredhours - $totalvalidated);

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

$PAGE->blocks->add_fake_block(
    local_ga_student_progress_block(
        $typeahours,
        $typebvalidatedhours,
        $typebuploadedhours,
        count($typeacerts),
        count($typebcerts)
    ),
    'side-pre'
);

echo $OUTPUT->header();

echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap mb-3');
echo html_writer::tag('h1', 'Mi portafolio HEE', ['class' => 'mb-2']);
echo html_writer::div(local_ga_student_return_course_button(), 'mb-2');
echo html_writer::end_div();

echo html_writer::start_div('alert alert-info');
echo html_writer::tag('strong', 'Objetivo: 54 horas. ');
echo 'Tienes reconocidas ' . round($totalvalidated, 2) . ' h. ';
echo $pendinghours > 0
    ? 'Te faltan ' . round($pendinghours, 2) . ' h.'
    : 'Ya has completado el objetivo.';
echo html_writer::end_div();

echo html_writer::start_div('row mb-3');
$cards = [
    ['Talleres Tipo A', round($typeahours, 2) . ' h', 'Sistema + reconocimiento institucional'],
    ['Talleres Tipo B', round($typebvalidatedhours, 2) . ' h', 'Internos + externos + traspasos + reconocimiento institucional'],
    ['Horas pendientes', round($pendinghours, 2) . ' h', 'Hasta completar 54 horas'],
];
foreach ($cards as $card) {
    echo html_writer::start_div('col-md-4 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h2', s($card[0]), ['class' => 'h5 card-title']);
    echo html_writer::tag('div', s($card[1]), ['style' => 'font-size:2rem;font-weight:700;']);
    echo html_writer::tag('p', s($card[2]), ['class' => 'text-muted mb-0']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($gradesummary) {
    $formatgrade = static function ($value): string {
        return ($value === null || $value === '')
            ? html_writer::span('Pendiente', 'badge bg-warning text-dark')
            : html_writer::span(format_float((float)$value, 2, true) . ' / 10', 'fw-bold');
    };

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h2', 'Mis calificaciones HEE', ['class' => 'h4 mb-1']);
    echo html_writer::tag(
        'p',
        'La Nota Final se publica cuando están disponibles las tres calificaciones: Talleres A (60%), Portafolio (30%) y Autoevaluación (10%).',
        ['class' => 'text-muted']
    );
    echo html_writer::start_div('row');
    $gradecards = [
        [
            'Nota Talleres A',
            $gradesummary->typeagrade,
            'Media de las notas de los talleres Tipo A superados (asistencia todos los días y 5 o más) y del reconocimiento institucional.',
        ],
        [
            'Portafolio',
            $gradesummary->portfoliograde,
            '10 al completar 54 h y todas las reflexiones Tipo B requeridas.',
        ],
        [
            'Autoevaluación',
            $gradesummary->autoevaluationgrade,
            $selfassessmentinfo ? format_string($selfassessmentinfo->name) : 'Cuestionario pendiente de vincular',
        ],
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

echo html_writer::tag('h2', 'Talleres Tipo A', ['class' => 'mt-4']);
echo html_writer::tag(
    'p',
    'Certificados generados cuando se cumplen la asistencia y la actividad evaluada del taller. En los talleres nuevos, la actividad predeterminada es un cuestionario Moodle con nota mínima de 5/10.',
    ['class' => 'text-muted']
);
if ($typeacerts) {
    $table = new html_table();
    $table->head = ['Curso', 'Taller', 'Horas', 'Fecha de emisión', 'Estado', 'Acciones'];
    foreach ($typeacerts as $c) {
        $url = new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $c->id]);
        $table->data[] = [
            format_string($c->coursename),
            s($c->workshopcode . ' - ' . $c->workshopname),
            !empty($c->hours) ? format_float((float)$c->hours, 2, true) . ' h' : '-',
            userdate((int)$c->timeissued),
            local_ga_portfolio_badge($c->status ?: 'generated'),
            html_writer::link(
                $url,
                local_ga_btn_icon('t/download', 'Descargar PDF'),
                ['class' => 'btn btn-primary btn-sm']
            ),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no tienes certificados Tipo A generados.', 'info');
}

echo html_writer::tag('h2', 'Talleres Tipo B internos', ['class' => 'mt-4']);
echo html_writer::tag(
    'p',
    'Talleres Tipo B organizados dentro de HEE. Para quedar Apto deben constar la asistencia y la reflexión entregada mediante la Tarea Moodle.',
    ['class' => 'text-muted']
);
if ($typebworkshopcerts) {
    $table = new html_table();
    $table->head = ['Curso', 'Taller', 'Horas', 'Asistencia', 'Reflexión', 'Fecha de emisión', 'Acciones'];
    foreach ($typebworkshopcerts as $c) {
        $url = new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $c->id]);
        $table->data[] = [
            format_string($c->coursename ?? ''),
            s(($c->workshopcode ?? '') . ' - ' . ($c->workshopname ?? '')),
            !empty($c->hours) ? format_float((float)$c->hours, 2, true) . ' h' : '-',
            'Confirmada',
            !empty($c->reflectiontext) ? format_text(\local_gestion_actividades\local\manager::reflection_plain($c->reflectiontext), FORMAT_PLAIN) : 'Entregada',
            userdate((int)$c->timeissued),
            html_writer::link(
                $url,
                local_ga_btn_icon('t/download', 'Descargar PDF'),
                ['class' => 'btn btn-primary btn-sm']
            ),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no tienes certificados de talleres Tipo B internos.', 'info');
}

// Tipo B PAT (1.º): shown like the other Tipo B talleres — only the ones that
// count (aptos), with attendance and, in place of the reflection, the quiz.
$patrows = array_filter(\local_gestion_actividades\local\pat_typeb::for_user((int)$USER->id), static fn($p) => !empty($p->passed));
if ($patrows) {
    echo html_writer::tag('h2', 'Talleres Tipo B PAT (1.º)', ['class' => 'mt-4']);
    echo html_writer::tag('p', 'Talleres PAT de 1.º curso. Igual que en los Tipo B, cuentan con la asistencia confirmada y, en lugar de la reflexión, el cuestionario superado.', ['class' => 'text-muted']);
    $table = new html_table();
    $table->head = ['Curso académico', 'Taller', 'Horas', 'Asistencia', 'Cuestionario (en lugar de reflexión)', 'Resultado'];
    foreach ($patrows as $p) {
        $table->data[] = [
            s($p->academicyear),
            s($p->workshopname),
            format_float((float)$p->hours, 2, true) . ' h',
            'Confirmada',
            'Superado',
            html_writer::span('Apto', 'badge bg-success'),
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::tag('h2', 'Formación externa Tipo B', ['class' => 'mt-4']);
echo html_writer::tag(
    'p',
    'Aquí aparecen todas tus solicitudes externas. El flujo es: certificado enviado → validación del gestor → reflexión del alumno → horas reconocidas.',
    ['class' => 'text-muted']
);
if ($typebcerts) {
    $table = new html_table();
    $table->head = ['Actividad', 'Fecha', 'Horas', 'Estado', 'Reflexión', 'Comentario del gestor', 'Acciones'];
    foreach ($typebcerts as $c) {
        $status = (string)($c->status ?? 'pending');
        $reflection = trim((string)($c->reflectiontext ?? ''));
        $actions = html_writer::link(
            new moodle_url('/local/gestion_actividades/typeb_view.php', ['id' => (int)$c->id]),
            local_ga_btn_icon('t/preview', 'Ver certificado'),
            ['class' => 'btn btn-outline-secondary btn-sm me-1', 'target' => '_blank']
        );
        if (
            $status === portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION
                || ($status === 'validated' && $reflection === '')
        ) {
            $params = [];
            if ($gradecourseid > 0) {
                $params['courseid'] = $gradecourseid;
            }
            $actions .= html_writer::link(
                new moodle_url('/local/gestion_actividades/typeb_upload.php', $params),
                local_ga_btn_icon('t/edit', 'Completar reflexión'),
                ['class' => 'btn btn-primary btn-sm']
            );
        }
        $table->data[] = [
            s((string)$c->activityname),
            !empty($c->activitydate)
                ? userdate((int)$c->activitydate, get_string('strftimedatefullshort', 'langconfig'))
                : '-',
            format_float((float)$c->hours, 2, true) . ' h',
            local_ga_portfolio_badge($status),
            $reflection !== '' ? format_text($reflection, FORMAT_PLAIN) : '-',
            trim((string)($c->reviewcomment ?? '')) !== '' ? s($c->reviewcomment) : '-',
            $actions,
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no has enviado formación externa para reconocimiento Tipo B.', 'info');
}

$typebuploadparams = [];
if ($gradecourseid > 0) {
    $typebuploadparams['courseid'] = $gradecourseid;
}
echo html_writer::link(
    new moodle_url('/local/gestion_actividades/typeb_upload.php', $typebuploadparams),
    local_ga_btn_icon('t/add', 'Solicitar validación de formación externa Tipo B'),
    ['class' => 'btn btn-primary mb-4']
);

echo html_writer::tag('h2', 'Reconocimiento institucional', ['class' => 'mt-4']);
echo html_writer::tag(
    'p',
    'Horas reconocidas previamente por el Decanato de la Facultad. En Tipo A se muestra la actividad evaluada y la nota importada; en Tipo B se exige el comentario del alumno cuando corresponda.',
    ['class' => 'text-muted']
);
if (!empty($institutionalrecords)) {
    $table = new html_table();
    $table->head = [
        'Concepto',
        'Origen',
        'Curso',
        'Grupo',
        'Horas',
        'Asistencia',
        'Actividad evaluada',
        'Nota Tipo A',
        'Comentario Tipo B',
    ];
    foreach ($institutionalrecords as $r) {
        if ((float)$r->typeahours > 0) {
            $table->data[] = [
                'Reconocimiento institucional - Horas Tipo A',
                s($r->source),
                s($r->courselevel ?? '-'),
                s($r->groupname ?? '-'),
                format_float((float)$r->typeahours, 2, true) . ' h',
                'Confirmada',
                'Registrada',
                ($r->taskgrade !== null && $r->taskgrade !== '')
                    ? format_float((float)$r->taskgrade, 2, true)
                    : '-',
                '-',
            ];
        }
        if ((float)$r->typebhours > 0) {
            $reflection = trim((string)($r->typebreflection ?? ''));
            $reflectionstatus = $reflection !== ''
                ? html_writer::span('Cumplimentado', 'badge bg-success')
                : html_writer::span('Pendiente', 'badge bg-warning text-dark');
            $table->data[] = [
                'Reconocimiento institucional - Horas Tipo B',
                s($r->source),
                s($r->courselevel ?? '-'),
                s($r->groupname ?? '-'),
                format_float((float)$r->typebhours, 2, true) . ' h',
                'Confirmada',
                'No aplica',
                '-',
                $reflectionstatus,
            ];
        }
    }
    if (!empty($table->data)) {
        echo html_writer::table($table);
    }

    foreach ($institutionalrecords as $r) {
        if ((float)$r->typebhours <= 0 || trim((string)($r->typebreflection ?? '')) !== '') {
            continue;
        }
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', 'Comentario del reconocimiento institucional Tipo B', ['class' => 'h5']);
        echo html_writer::tag(
            'p',
            'Explica en qué consistieron las actividades reconocidas y cuál es su principal utilidad. Este comentario es obligatorio para completar el portafolio.',
            ['class' => 'text-muted']
        );
        echo html_writer::start_tag(
            'form',
            ['method' => 'post', 'action' => new moodle_url('/local/gestion_actividades/portfolio.php')]
        );
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'action',
            'value' => 'save_institutional_typeb_reflection',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'institutionalrecordid',
            'value' => (int)$r->id,
        ]);
        if ($gradecourseid > 0) {
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'courseid',
                'value' => $gradecourseid,
            ]);
        }
        echo html_writer::tag(
            'textarea',
            '',
            [
                'name' => 'institutionaltypebreflection',
                'class' => 'form-control mb-2',
                'rows' => 6,
                'required' => 'required',
                'maxlength' => 5000,
            ]
        );
        echo html_writer::tag(
            'button',
            local_ga_btn_icon('t/save', 'Guardar comentario'),
            ['type' => 'submit', 'class' => 'btn btn-primary']
        );
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
} else {
    echo $OUTPUT->notification('No constan horas de reconocimiento institucional importadas.', 'info');
}

echo html_writer::tag('h2', 'Traspasos de Tipo A a Tipo B', ['class' => 'mt-4']);
echo html_writer::tag(
    'p',
    'Talleres Tipo A certificados que has traspasado a Tipo B. Estas horas dejan de contar como Tipo A y pasan a contar como Tipo B sin cambiar tu total reconocido.',
    ['class' => 'text-muted']
);
$transferrows = manager::list_user_typeb_transfers((int)$USER->id);
if ($transferrows) {
    $table = new html_table();
    $table->head = ['Taller A traspasado', 'Horas', 'Reflexión', 'Fecha'];
    foreach ($transferrows as $r) {
        $table->data[] = [
            s(trim((string)($r->workshopcode ?? '') . ' - ' . (string)($r->workshopname ?? ''))),
            format_float((float)($r->hours ?? 0), 2, true) . ' h',
            format_text(\local_gestion_actividades\local\manager::reflection_plain($r->reflectiontext ?? ''), FORMAT_PLAIN),
            !empty($r->timecreated) ? userdate((int)$r->timecreated) : '-',
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no has traspasado ningún taller Tipo A a Tipo B.', 'info');
}

echo html_writer::start_div('card mt-4 mb-3');
echo html_writer::start_div('card-body d-flex justify-content-between align-items-center flex-wrap');
echo html_writer::start_div('mb-2');
echo html_writer::tag('h2', 'Descargas del portafolio', ['class' => 'h4 mb-1']);
echo html_writer::tag(
    'p',
    'Descarga el resumen PDF o el expediente completo con los documentos disponibles.',
    ['class' => 'text-muted mb-0']
);
echo html_writer::end_div();
echo html_writer::start_div('mb-2');
echo html_writer::link(
    new moodle_url('/local/gestion_actividades/portfolio_pdf_download.php'),
    local_ga_btn_icon('t/download', 'Descargar portafolio PDF'),
    ['class' => 'btn btn-primary me-2 mb-2']
);
echo html_writer::link(
    new moodle_url('/local/gestion_actividades/portfolio_package_download.php'),
    local_ga_btn_icon('t/download', 'Descargar expediente ZIP'),
    ['class' => 'btn btn-primary mb-2']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
