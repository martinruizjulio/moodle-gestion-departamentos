<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$courseid = optional_param('courseid', 0, PARAM_INT);
// What to delete must always be chosen explicitly: '' = nothing chosen yet,
// 'all' = every Edición of the course, 's<ID>' = only that Edición.
// There is NO default, so a single click can never turn into «delete all».
$scope = optional_param('scope', '', PARAM_ALPHANUMEXT);
$seriesid = preg_match('/^s(\d+)$/', $scope, $m) ? (int)$m[1] : 0;
if ($scope !== 'all' && $seriesid <= 0) {
    $scope = '';
}
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
        $expected = $scope === 'all' ? 'BORRAR TODO' : 'BORRAR PRUEBAS';
        if ($scope === '') {
            $error = 'Elige primero qué quieres borrar.';
        } else if ($seriesid > 0 && !$DB->record_exists('local_ga_workshop_series', ['id' => $seriesid, 'courseid' => $courseid])) {
            $error = 'La Edición elegida no pertenece a este curso. No se ha borrado nada.';
        } else if ($confirm !== $expected) {
            $error = 'Escribe exactamente ' . $expected . ' para confirmar. No se ha borrado nada.';
        } else {
            try {
                // Deleting many activities is slow (each one also goes through
                // Moodle's recycle bin backup): with the default PHP limits the
                // request could die with an HTTP 500. Lift the limits first.
                \core_php_time_limit::raise();
                raise_memory_limit(MEMORY_HUGE);
                ignore_user_abort(true);
                $result = manager::purge_course_test_data($courseid, $scope === 'all' ? [] : [$seriesid]);
                $message = $scope === 'all'
                    ? 'Limpieza completada. El curso mantiene usuarios y contenido ajeno a Gestión HEE.'
                    : 'Edición borrada. El resto de Ediciones del curso no se ha tocado.';
                $scope = '';
                $seriesid = 0;
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
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), '← Volver al panel', ['class' => 'btn local-ga-back-panel me-2 mb-3']) .
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
    // Step 2: what to delete — one Edición or the whole course.
    $courseseries = $DB->get_records('local_ga_workshop_series', ['courseid' => $courseid], 'datefrom ASC, id ASC');
    if ($seriesid > 0 && !isset($courseseries[$seriesid])) {
        $scope = '';
        $seriesid = 0;
    }
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', '2. ¿Qué quieres borrar?', ['class' => 'h5']);
    echo html_writer::tag('p', 'Al marcar una opción se muestra al momento qué se borrará. No hay ninguna opción marcada por defecto.', ['class' => 'text-muted']);
    // Choosing an option reloads the page with that scope, so the confirmation
    // below always matches what is checked here.
    echo html_writer::start_tag('form', ['method' => 'get', 'id' => 'ga-cleanup-scope']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    foreach ($courseseries as $cs) {
        $types = $DB->get_fieldset_sql("SELECT DISTINCT w.workshoptype FROM {local_ga_series_items} i
                                          JOIN {local_ga_workshops} w ON w.id = i.workshopid WHERE i.seriesid = ?", [$cs->id]);
        $typelabel = $types ? implode(' + ', array_map(fn($t) => $t === 'typeb' ? 'Tipo B' : 'Tipo A', $types)) : 'sin talleres';
        $ntalleres = $DB->count_records('local_ga_series_items', ['seriesid' => $cs->id]);
        $label = format_string($cs->title) . ' · ' . $typelabel . ' · ' . $ntalleres . ' taller(es) · '
            . userdate((int)$cs->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$cs->dateto, '%d/%m/%Y')
            . ((string)$cs->status === 'finished' ? ' · finalizada' : '');
        echo html_writer::start_div('form-check');
        echo html_writer::empty_tag('input', ['type' => 'radio', 'class' => 'form-check-input', 'name' => 'scope',
            'id' => 'series' . $cs->id, 'value' => 's' . $cs->id, 'onchange' => 'this.form.submit()']
            + ($seriesid === (int)$cs->id ? ['checked' => 'checked'] : []));
        echo html_writer::label('Solo la Edición «' . $label . '»', 'series' . $cs->id, false, ['class' => 'form-check-label']);
        echo html_writer::end_div();
    }
    echo html_writer::start_div('form-check mt-2');
    echo html_writer::empty_tag('input', ['type' => 'radio', 'class' => 'form-check-input', 'name' => 'scope', 'id' => 'seriesall',
        'value' => 'all', 'onchange' => 'this.form.submit()'] + ($scope === 'all' ? ['checked' => 'checked'] : []));
    echo html_writer::label('<strong>Todas</strong> las Ediciones y datos HEE de prueba de este curso', 'seriesall', false, ['class' => 'form-check-label']);
    echo html_writer::end_div();
    echo html_writer::tag('button', 'Ver qué se borrará', ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();

    if ($scope === '') {
        echo $OUTPUT->notification('Elige en el paso 2 qué Edición quieres borrar (o «Todas»). Hasta entonces no se puede borrar nada.', 'info');
        echo $OUTPUT->footer();
        exit;
    }

    $summary = manager::course_test_data_summary($courseid, $seriesid > 0 ? [$seriesid] : []);
    $scopelabel = $seriesid > 0
        ? 'la Edición «' . format_string($courseseries[$seriesid]->title) . '»'
        : 'TODAS las Ediciones de ' . format_string($course->fullname);

    echo html_writer::start_div('card border-danger mb-4');
    echo html_writer::start_div('card-header bg-danger text-white');
    echo html_writer::tag('strong', '3. Confirmar: se borrará ' . $scopelabel);
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
    ] + ($seriesid > 0 ? [] : ['Estructuras HEE huérfanas en el curso' => $summary->orphanstructures]) as $label => $value) {
        $preview->data[] = [$label, (int)$value];
    }
    echo html_writer::table($preview);

    if ((int)$summary->workshops === 0 && (int)$summary->series === 0 && (int)$summary->orphanstructures === 0) {
        echo $OUTPUT->notification('No hay datos ni estructuras HEE que limpiar en este curso.', 'info');
    } else {
        $expected = $scope === 'all' ? 'BORRAR TODO' : 'BORRAR PRUEBAS';
        echo html_writer::tag('p',
            'Esta acción es irreversible. Se borrará <strong>' . $scopelabel . '</strong>. Para confirmar, escribe exactamente <strong>'
                . $expected . '</strong>.',
            ['class' => 'alert alert-danger']
        );
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'onsubmit' => "return confirm(" . json_encode('¿Confirmas que quieres borrar ' . strip_tags($scopelabel) . '?') . ");",
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'purge']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'scope', 'value' => $scope]);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'confirmtext',
            'class' => 'form-control mb-3',
            'placeholder' => $expected,
            'autocomplete' => 'off',
            'required' => 'required',
        ]);
        echo html_writer::tag('button', $seriesid > 0 ? 'Borrar solo esta Edición' : 'Borrar todos los datos HEE de prueba de este curso', [
            'type' => 'submit',
            'class' => 'btn btn-danger',
        ]);
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
