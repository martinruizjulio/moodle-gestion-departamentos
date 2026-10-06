<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$message = '';
$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($action === 'purge') {
        $courseid = required_param('courseid', PARAM_INT);
        // The purge deletes Moodle activities and groups of this course, so the
        // user must also be able to manage that course's activities.
        require_capability('moodle/course:manageactivities', context_course::instance($courseid));
        $confirm = trim(required_param('confirmtext', PARAM_TEXT));
        if ($confirm !== 'BORRAR PRUEBAS') {
            $error = 'Escribe exactamente BORRAR PRUEBAS para confirmar la limpieza.';
        } else {
            try {
                // Deleting many activities is slow (each one also goes through
                // Moodle's recycle bin backup): with the default PHP limits the
                // request could die with an HTTP 500. Lift the limits first.
                \core_php_time_limit::raise();
                raise_memory_limit(MEMORY_HUGE);
                ignore_user_abort(true);
                $result = manager::purge_course_test_data($courseid);
                $message = 'Limpieza completada. El curso mantiene usuarios y contenido ajeno a Gestión HEE.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/test_cleanup.php', $courseid > 0 ? ['courseid' => $courseid] : []));
$PAGE->set_title('Limpieza de datos de prueba HEE');
$PAGE->set_heading('Gestión HEE');

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), '← Volver al panel', ['class' => 'btn btn-outline-secondary me-2 mb-3']) .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php'), 'Ediciones de talleres', ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);

echo $OUTPUT->heading('Limpieza de datos de prueba HEE');
echo html_writer::tag('p',
    'Herramienta destructiva para reiniciar las pruebas de Talleres/Ediciones de un curso. '
    . 'Borra talleres, Ediciones, inscripciones, grupos HEE, horas/certificados/reflexiones internos y las actividades Moodle propias de cada edición. '
    . 'Las actividades vinculadas que no son propias de una sola edición (asistencia o certificado del curso, actividades compartidas, autoevaluación) se conservan; '
    . 'lo que hubiera dentro de las subsecciones se mueve a la sección oculta «HEE · Actividades conservadas tras limpieza». '
    . 'También elimina del curso las secciones HEE antiguas o huérfanas que ya no tengan registro en la gestión. '
    . 'No borra usuarios Moodle, el curso, formaciones Tipo B externas, reconocimiento institucional ni contenido ajeno a Gestión HEE.',
    ['class' => 'alert alert-warning']
);

if ($message !== '') {
    echo $OUTPUT->notification($message, 'success');
}
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
if ($result) {
    $details = [
        'Ediciones de talleres: ' . (int)$result->series,
        'Talleres: ' . (int)$result->workshops,
        'Ediciones concretas: ' . (int)$result->editions,
        'Inscripciones: ' . (int)$result->enrolments,
        'Certificados: ' . (int)$result->certificates,
        'Registros de horas: ' . (int)$result->hours,
        'Reflexiones: ' . (int)$result->reflections,
        'Entregas: ' . (int)$result->submissions,
        'Traspasos: ' . (int)$result->transfers,
        'Módulos Moodle eliminados: ' . (int)$result->modulesdeleted,
        'Actividades conservadas (no creadas por HEE o compartidas; sin vínculo HEE): ' . (int)($result->modulespreserved ?? 0),
        'Grupos eliminados: ' . (int)$result->groupsdeleted,
        'Secciones Moodle HEE eliminadas: ' . (int)$result->sectionsdeleted,
        'Secciones HEE huérfanas eliminadas: ' . (int)($result->orphansectionsdeleted ?? 0),
    ];
    echo html_writer::alist($details, ['class' => 'mb-4']);
}

$courseoptions = manager::get_course_options();

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', '1. Selecciona el curso de pruebas', ['class' => 'h5']);
echo html_writer::start_tag('form', ['method' => 'get']);
echo html_writer::select($courseoptions, 'courseid', $courseid, ['' => 'Selecciona un curso'], ['class' => 'form-select mb-3', 'required' => 'required']);
echo html_writer::tag('button', 'Ver datos que se borrarán', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', MUST_EXIST);
    require_capability('moodle/course:manageactivities', context_course::instance($courseid));
    $summary = manager::course_test_data_summary($courseid);

    echo html_writer::start_div('card border-danger mb-4');
    echo html_writer::start_div('card-header bg-danger text-white');
    echo html_writer::tag('strong', '2. Confirmar limpieza de ' . format_string($course->fullname));
    echo html_writer::end_div();
    echo html_writer::start_div('card-body');

    $preview = new html_table();
    $preview->attributes['class'] = 'generaltable table-sm';
    $preview->head = ['Dato HEE', 'Cantidad'];
    foreach ([
        'Ediciones de talleres' => $summary->series,
        'Talleres' => $summary->workshops,
        'Ediciones concretas' => $summary->editions,
        'Inscripciones' => $summary->enrolments,
        'Certificados' => $summary->certificates,
        'Registros de horas' => $summary->hours,
        'Reflexiones' => $summary->reflections,
        'Entregas' => $summary->submissions,
        'Traspasos' => $summary->transfers,
        'Grupos de Edición' => $summary->groups,
        'Estructuras HEE huérfanas en el curso' => $summary->orphanstructures,
    ] as $label => $value) {
        $preview->data[] = [$label, (int)$value];
    }
    echo html_writer::table($preview);

    if ((int)$summary->workshops === 0 && (int)$summary->series === 0 && (int)$summary->orphanstructures === 0) {
        echo $OUTPUT->notification('No hay datos ni estructuras HEE que limpiar en este curso.', 'info');
    } else {
        echo html_writer::tag('p',
            'Esta acción es irreversible. Para confirmar, escribe exactamente <strong>BORRAR PRUEBAS</strong>.',
            ['class' => 'alert alert-danger']
        );
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'onsubmit' => "return confirm('¿Confirmas la limpieza masiva de los datos HEE de prueba de este curso?');",
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'purge']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'confirmtext',
            'class' => 'form-control mb-3',
            'placeholder' => 'BORRAR PRUEBAS',
            'autocomplete' => 'off',
            'required' => 'required',
        ]);
        echo html_writer::tag('button', 'Borrar todos los datos HEE de prueba de este curso', [
            'type' => 'submit',
            'class' => 'btn btn-danger',
        ]);
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
