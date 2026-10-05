<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\grade_manager;
use local_gestion_actividades\local\manager;

require_login();
$systemcontext = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($systemcontext, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$courses = grade_manager::get_managed_courses();
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid <= 0 && $courses) {
    $first = reset($courses);
    $courseid = (int)$first->id;
}
$course = ($courseid > 0 && isset($courses[$courseid]))
    ? $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING)
    : false;
if ($courseid > 0 && !$course) {
    throw new moodle_exception('invalidcourseid');
}

if ($course && data_submitted() && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    if ($action === 'save_quiz') {
        $cmid = optional_param('selfassessmentcmid', 0, PARAM_INT);
        grade_manager::save_selfassessment_quiz((int)$course->id, $cmid, (int)$USER->id);
        grade_manager::get_course_grade_rows((int)$course->id, true);
        redirect(
            new moodle_url('/local/gestion_actividades/grades_report.php', ['courseid' => $course->id]),
            'Cuestionario guardado, restringido a 54 horas y calificaciones sincronizadas.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($action === 'sync') {
        grade_manager::get_course_grade_rows((int)$course->id, true);
        redirect(
            new moodle_url('/local/gestion_actividades/grades_report.php', ['courseid' => $course->id]),
            'Calificaciones HEE sincronizadas con el cuaderno de Moodle.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/grades_report.php', $courseid > 0 ? ['courseid' => $courseid] : []));
$PAGE->set_title('Notas de alumnos');
$PAGE->set_heading('Gestión HEE');

function local_ga_grades_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_grades_value($value): string {
    if ($value === null || $value === '') {
        return html_writer::span('Pendiente', 'badge bg-warning text-dark');
    }
    return html_writer::span(format_float((float)$value, 2, true), 'font-weight-bold');
}

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/gestion_actividades/dashboard.php', $courseid > 0 ? ['courseid' => $courseid] : []),
        local_ga_grades_icon('t/left', 'Volver al panel'),
        ['class' => 'btn btn-outline-secondary mb-3']
    ),
    'mb-2'
);
echo $OUTPUT->heading('6. Notas de alumnos');
echo html_writer::tag(
    'p',
    'Desglose de Nota Talleres A, Portafolio, Autoevaluación y Nota Final. La Nota Final se calcula como 60% + 30% + 10% y solo se publica cuando las tres partes están disponibles.',
    ['class' => 'text-muted']
);

if (!$courses) {
    echo $OUTPUT->notification('No hay cursos con talleres HEE configurados.', 'info');
    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();
    exit;
}

if (count($courses) > 1) {
    echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3']);
    echo html_writer::label('Curso', 'courseid', false, ['class' => 'mr-2']);
    echo html_writer::start_tag('select', ['name' => 'courseid', 'id' => 'courseid', 'class' => 'form-control me-2']);
    foreach ($courses as $availablecourse) {
        $attributes = ['value' => (int)$availablecourse->id];
        if ((int)$availablecourse->id === $courseid) {
            $attributes['selected'] = 'selected';
        }
        echo html_writer::tag('option', format_string($availablecourse->fullname), $attributes);
    }
    echo html_writer::end_tag('select');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Cambiar curso', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
}

$settings = grade_manager::get_course_settings($courseid);
$quizzes = grade_manager::get_course_quizzes($courseid);
$selectedinfo = grade_manager::get_selfassessment_info($courseid);
if ($selectedinfo) {
    grade_manager::ensure_selfassessment_availability($courseid);
}

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', 'Cuestionario de autoevaluación', ['class' => 'h4']);
echo html_writer::tag(
    'p',
    'Selecciona el cuestionario del curso cuya calificación se utilizará como Autoevaluación. Gestión HEE añadirá automáticamente un criterio que lo oculta por completo hasta que cada alumno alcance 54 horas.',
    ['class' => 'text-muted']
);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_quiz']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::label('Cuestionario de autoevaluación HEE', 'selfassessmentcmid');
echo html_writer::start_tag('select', ['name' => 'selfassessmentcmid', 'id' => 'selfassessmentcmid', 'class' => 'form-control mb-2', 'style' => 'max-width:700px;']);
echo html_writer::tag('option', '— Sin seleccionar —', ['value' => 0]);
foreach ($quizzes as $quiz) {
    $range = '';
    if ($quiz->grademax !== null) {
        $range = ' · nota máxima ' . format_float((float)$quiz->grademax, 2, true);
    }
    if (empty($quiz->visible)) {
        $range .= ' · oculto';
    }
    $attributes = ['value' => (int)$quiz->cmid];
    if ((int)$quiz->cmid === (int)$settings->selfassessmentcmid) {
        $attributes['selected'] = 'selected';
    }
    echo html_writer::tag('option', format_string($quiz->name) . $range, $attributes);
}
echo html_writer::end_tag('select');
echo html_writer::tag('div', 'La nota se normaliza automáticamente a una escala de 0 a 10. El acceso se controla mediante un ítem técnico oculto del cuaderno que llega al 100% al completar 54 horas.', ['class' => 'form-text mb-3']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Guardar selector', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
if ($selectedinfo) {
    echo html_writer::div(
        'Seleccionado: ' . format_string($selectedinfo->name) . '. El cuestionario permanecerá oculto para cada alumno hasta alcanzar 54 horas.',
        'alert alert-success mt-3 mb-0'
    );
    if (empty($CFG->enableavailability)) {
        echo html_writer::div('La disponibilidad condicional está desactivada en la configuración general de Moodle. Actívala para que el criterio de 54 horas se aplique.', 'alert alert-warning mt-2 mb-0');
    }
} else {
    echo html_writer::div('Todavía no hay un cuestionario de autoevaluación vinculado.', 'alert alert-warning mt-3 mb-0');
}
echo html_writer::end_div();
echo html_writer::end_div();

$rows = grade_manager::get_course_grade_rows($courseid, true);

echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap mb-3');
echo html_writer::tag('h2', 'Listado de calificaciones', ['class' => 'h4 mb-2']);
echo html_writer::start_div('mb-2');
echo html_writer::link(
    new moodle_url('/local/gestion_actividades/grades_export.php', ['courseid' => $courseid, 'format' => 'excel']),
    local_ga_grades_icon('t/download', 'Descargar Excel'),
    ['class' => 'btn btn-primary me-2 mb-1']
);
echo html_writer::link(
    new moodle_url('/local/gestion_actividades/grades_export.php', ['courseid' => $courseid, 'format' => 'pdf']),
    local_ga_grades_icon('t/download', 'Descargar PDF'),
    ['class' => 'btn btn-secondary me-2 mb-1']
);
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'sync']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Sincronizar ahora', 'class' => 'btn btn-outline-secondary mb-1']);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

if (!$rows) {
    echo $OUTPUT->notification('No se han encontrado alumnos matriculados en este curso.', 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = [
        'Apellidos',
        'Nombre',
        'Email',
        'Grupo',
        'Nota Talleres A',
        'Portafolio',
        'Autoevaluación',
        'Nota Final',
        'Horas A',
        'Horas B',
        'Horas totales',
        'Comentarios B pendientes',
    ];
    foreach ($rows as $row) {
        $table->data[] = [
            s($row->lastname),
            s($row->firstname),
            s($row->email),
            s(local_gestion_actividades_student_group((int)$row->userid)),
            local_ga_grades_value($row->typeagrade),
            local_ga_grades_value($row->portfoliograde),
            local_ga_grades_value($row->autoevaluationgrade),
            local_ga_grades_value($row->finalgrade),
            format_float((float)$row->typeahours, 2, true),
            format_float((float)$row->typebhours, 2, true),
            format_float((float)$row->totalhours, 2, true),
            (int)$row->missingtypebcomments,
        ];
    }
    echo html_writer::table($table);
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
