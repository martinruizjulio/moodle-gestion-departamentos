<?php
// Datos de demostración HEE (solo administradores del sitio).
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_hee_demo\generator;
use local_gestion_actividades\local\workshop_series;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_hee_demo', '', ['courseid' => $courseid]);
require_capability('moodle/site:config', context_system::instance());
$url = new moodle_url('/local/hee_demo/index.php', $courseid ? ['courseid' => $courseid] : []);
$PAGE->set_url($url);

if ($action === 'generate' && $courseid) {
    require_sesskey();
    $seriesids = optional_param_array('series', [], PARAM_INT);
    $password = optional_param('password', '', PARAM_RAW);
    if (!$seriesids || trim($password) === '') {
        redirect($url, 'Elige al menos una Edición y escribe la contraseña de los alumnos demo.', null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $opts = [
        'perworkshop' => max(1, min(200, optional_param('perworkshop', 40, PARAM_INT))),
        'poolsize' => max(1, min(500, optional_param('poolsize', 100, PARAM_INT))),
        'present' => max(0, min(100, optional_param('present', 90, PARAM_INT))),
        'fail' => max(0, min(100, optional_param('fail', 10, PARAM_INT))),
        'reflect' => max(0, min(100, optional_param('reflect', 90, PARAM_INT))),
        'password' => $password,
        'finish' => optional_param('finish', 0, PARAM_BOOL),
    ];
    $report = generator::generate($courseid, $seriesids, $opts);
    $msg = html_writer::tag('strong', 'Demostración generada') . html_writer::alist(array_merge([
        "{$report->users} alumnos demo · {$report->enrolments} inscripciones nuevas",
        "Asistencia: {$report->present} presentes, {$report->absent} faltas",
        "Cuestionarios: {$report->quizpass} aprobados, {$report->quizfail} suspensos · Reflexiones: {$report->reflections}",
    ], $opts['finish'] ? ["Ediciones finalizadas · certificados emitidos: {$report->certificates}"] : []))
        . html_writer::alist($report->lines);
    redirect($url, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'purge') {
    require_sesskey();
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        echo $OUTPUT->header();
        $s = generator::summary();
        echo $OUTPUT->confirm(
            "Se borrarán {$s->users} alumnos demo con sus {$s->enrolments} inscripciones, asistencias, notas, reflexiones, "
                . "{$s->certificates} certificados y horas. Los talleres y Ediciones NO se tocan (para eso: «Borrar pruebas» de Gestión HEE).",
            new moodle_url($url, ['action' => 'purge', 'confirm' => 1, 'sesskey' => sesskey()]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }
    $n = generator::purge();
    redirect($url, "Demostración borrada: $n alumnos demo eliminados.", null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Datos de demostración HEE');
echo $OUTPUT->notification('Plugin temporal para presentaciones. Crea alumnos ficticios (heedemo001…, correo @example.invalid, '
    . 'sin envío de correos) con inscripciones, asistencia, notas y reflexiones reales en las Ediciones elegidas. '
    . 'Desinstálalo al terminar la demo.', \core\output\notification::NOTIFY_INFO);

if (!$courseid) {
    $courses = generator::courses_with_series();
    if (!$courses) {
        echo $OUTPUT->notification('Ningún curso tiene Ediciones de talleres. Crea primero la Edición A y la B e importa las plantillas.',
            \core\output\notification::NOTIFY_WARNING);
    } else {
        echo html_writer::tag('p', 'Elige el curso:');
        $items = [];
        foreach ($courses as $c) {
            $items[] = html_writer::link(new moodle_url('/local/hee_demo/index.php', ['courseid' => $c->id]), format_string($c->fullname));
        }
        echo html_writer::alist($items);
    }
} else {
    $course = get_course($courseid);
    echo $OUTPUT->heading(format_string($course->fullname), 3);
    $rows = '';
    foreach (workshop_series::list_for_course($courseid) as $series) {
        $eds = generator::series_editions((int)$series->id);
        $types = [];
        foreach ($eds as $row) {
            $types[$row->item->workshoptype === 'typeb' ? 'B' : 'A'] = true;
        }
        $label = format_string($series->title) . ' · ' . count($eds) . ' talleres'
            . ($types ? ' · Tipo ' . implode('+', array_keys($types)) : '')
            . ((string)$series->status === 'finished' ? ' · (finalizada)' : '');
        $rows .= html_writer::div(html_writer::checkbox('series[]', $series->id, (string)$series->status !== 'finished', ' ' . $label));
    }
    $num = fn($name, $value, $label) => html_writer::div(html_writer::label($label, 'id_' . $name, true, ['class' => 'me-2'])
        . html_writer::empty_tag('input', ['type' => 'number', 'name' => $name, 'id' => 'id_' . $name, 'value' => $value,
            'class' => 'form-control d-inline-block', 'style' => 'width:7em']), 'mb-2');
    $form = html_writer::tag('h4', '1. Ediciones') . ($rows ?: html_writer::tag('p', 'Este curso no tiene Ediciones.'))
        . html_writer::tag('h4', '2. Parámetros', ['class' => 'mt-3'])
        . $num('perworkshop', 40, 'Alumnos por taller')
        . $num('poolsize', 100, 'Alumnos demo en total (se repiten entre talleres)')
        . $num('present', 90, '% con asistencia «Presente»')
        . $num('fail', 10, '% de suspensos en el cuestionario (Tipo A)')
        . $num('reflect', 90, '% que entrega la reflexión (Tipo B)')
        . html_writer::div(html_writer::label('Contraseña de los alumnos demo', 'id_password', true, ['class' => 'me-2'])
            . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'password', 'id' => 'id_password',
                'class' => 'form-control d-inline-block', 'style' => 'width:14em', 'autocomplete' => 'off'])
            . html_writer::tag('small', ' No se guarda en ningún sitio; apúntala para entrar como alumno.', ['class' => 'text-muted']), 'mb-2')
        . html_writer::div(html_writer::checkbox('finish', 1, false,
            ' Finalizar las Ediciones al terminar (emite certificados y horas). Déjalo sin marcar si quieres pulsar «Finalizar» en directo.'), 'mb-3')
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'generate'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => 'Generar demostración']);
    echo html_writer::tag('form', $form, ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::tag('p', html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), 'Ir al curso')
        . ' · ' . html_writer::link(new moodle_url('/local/hee_demo/index.php'), 'Cambiar de curso'), ['class' => 'mt-3']);
}

$s = generator::summary();
echo $OUTPUT->heading('Alumnos demo existentes', 4, 'mt-4');
if ($s->users) {
    $sample = array_slice(generator::demo_users(), 0, 5);
    echo html_writer::tag('p', "{$s->users} alumnos demo · {$s->enrolments} inscripciones · {$s->certificates} certificados. "
        . 'Usuarios: ' . implode(', ', array_map(fn($u) => s($u->username) . ' (' . s(fullname($u)) . ')', $sample)) . '…');
    echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'purge']), 'Borrar demostración', 'post', ['type' => 'danger']);
} else {
    echo html_writer::tag('p', 'No hay alumnos demo.');
}
echo $OUTPUT->footer();
