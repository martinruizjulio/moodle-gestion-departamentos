<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\grade_manager;
use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\selfassessment_quiz;

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
    if ($action === 'create_quiz') {
        $cmid = selfassessment_quiz::create_and_link((int)$course->id, (int)$USER->id,
            optional_param('replace', 0, PARAM_BOOL));
        grade_manager::get_course_grade_rows((int)$course->id, true);
        redirect(
            new moodle_url('/local/gestion_actividades/grades_report.php', ['courseid' => $course->id]),
            'Cuestionario «Autoevaluación final HEE» creado y vinculado. Ya puedes editar sus preguntas en Moodle; el alumnado no lo verá hasta alcanzar 54 horas.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($action === 'save_quiz') {
        $cmid = optional_param('selfassessmentcmid', 0, PARAM_INT);
        try {
            grade_manager::save_selfassessment_quiz((int)$course->id, $cmid, (int)$USER->id);
        } catch (\invalid_parameter_exception $e) {
            redirect(new moodle_url('/local/gestion_actividades/grades_report.php', ['courseid' => $course->id]),
                $e->debuginfo ?: $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
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
        ['class' => 'btn local-ga-back-panel mb-3']
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
    echo html_writer::label('Curso', 'courseid', false, ['class' => 'me-2']);
    echo html_writer::start_tag('select', ['name' => 'courseid', 'id' => 'courseid', 'class' => 'form-select me-2']);
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
$coursecontext = context_course::instance($courseid);
$cancreatequiz = has_capability('moodle/course:manageactivities', $coursecontext);
$workshopquizzes = grade_manager::workshop_quiz_cmids($courseid);
$selectedisworkshop = $selectedinfo && isset($workshopquizzes[(int)$selectedinfo->cmid]);
$selectedisauto = $selectedinfo && (string)$DB->get_field('course_modules', 'idnumber', ['id' => (int)$selectedinfo->cmid]) === 'gestion_hee_selfassessment';

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', 'Cuestionario de autoevaluación', ['class' => 'h4']);
echo html_writer::tag(
    'p',
    'Puedes crear automáticamente un cuestionario Moodle vacío de autoevaluación y después editar sus preguntas normalmente, o seleccionar un cuestionario que ya exista. Gestión HEE lo oculta por completo hasta que cada alumno alcance 54 horas.',
    ['class' => 'text-muted']
);

if ($selectedisworkshop) {
    echo $OUTPUT->notification('Atención: el cuestionario seleccionado («' . format_string($selectedinfo->name) . '») es el de un TALLER. '
        . 'Mientras siga seleccionado, ese taller queda oculto para sus alumnos hasta las 54 horas. '
        . 'Pulsa «Crear cuestionario de autoevaluación HEE»: se creará el correcto y el taller volverá a estar visible.', 'error');
}
if (!$selectedinfo || $selectedisworkshop || !$selectedisauto) {
    if ($cancreatequiz) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create_quiz']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'replace', 'value' => $selectedinfo ? 1 : 0]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => $selectedinfo ? 'Crear cuestionario de autoevaluación HEE y usarlo en lugar del seleccionado' : 'Crear cuestionario de autoevaluación HEE',
            'class' => 'btn btn-success',
        ]);
        echo html_writer::tag(
            'div',
            'Se creará al final del curso, en una sección propia, ya vinculado a la regla de 54 horas. Después podrás añadir o modificar sus preguntas desde Moodle.',
            ['class' => 'form-text mt-1']
        );
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::div(
            'Para crear automáticamente una actividad Moodle necesitas el permiso institucional «moodle/course:manageactivities». Puedes seguir seleccionando un cuestionario ya creado por quien tenga ese permiso.',
            'alert alert-info'
        );
    }
}

echo html_writer::start_tag('details', ['class' => 'mb-3'] + ($selectedinfo && !$selectedisauto && !$selectedisworkshop ? ['open' => 'open'] : []));
echo html_writer::tag('summary', 'Opción avanzada: usar otro cuestionario que ya exista en el curso', ['class' => 'text-muted mb-2']);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_quiz']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::label('Cuestionario de autoevaluación HEE', 'selfassessmentcmid');
echo html_writer::start_tag('select', ['name' => 'selfassessmentcmid', 'id' => 'selfassessmentcmid', 'class' => 'form-select mb-2', 'style' => 'max-width:700px;']);
echo html_writer::tag('option', '— Sin seleccionar —', ['value' => 0]);
foreach ($quizzes as $quiz) {
    // Taller quizzes are never offered (only shown if wrongly selected).
    if (isset($workshopquizzes[(int)$quiz->cmid]) && (int)$quiz->cmid !== (int)$settings->selfassessmentcmid) {
        continue;
    }
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
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Guardar selector', 'class' => 'btn btn-outline-primary']);
echo html_writer::end_tag('form');
echo html_writer::end_tag('details');
if ($selectedinfo) {
    echo html_writer::div(
        'Seleccionado: ' . format_string($selectedinfo->name) . '. El cuestionario permanecerá oculto para cada alumno hasta alcanzar 54 horas.',
        'alert alert-success mt-3'
    );
    echo html_writer::start_div('d-flex flex-wrap gap-2');
    echo html_writer::link(
        new moodle_url('/mod/quiz/edit.php', ['cmid' => (int)$selectedinfo->cmid]),
        'Editar preguntas',
        ['class' => 'btn btn-primary']
    );
    if ($cancreatequiz) {
        echo html_writer::link(
            new moodle_url('/course/modedit.php', ['update' => (int)$selectedinfo->cmid, 'return' => 1]),
            'Editar configuración del cuestionario',
            ['class' => 'btn btn-outline-secondary']
        );
    }
    echo html_writer::end_div();
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
            s(local_gestion_actividades_student_group((int)$row->id)),
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