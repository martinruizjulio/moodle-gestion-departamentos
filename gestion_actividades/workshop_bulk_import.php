<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\bulk_workshops;
use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$token = optional_param('token', '', PARAM_ALPHANUM);
$quiztemplatecmid = optional_param('quiztemplatecmid', 0, PARAM_INT);
$notestemplatecmid = optional_param('notestemplatecmid', 0, PARAM_INT);

if ($action === 'template') {
    require_sesskey();
    bulk_workshops::send_template();
}

$preview = [];
$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if ($action === 'upload') {
            if ($courseid <= 0) throw new RuntimeException('Selecciona el curso antes de subir la plantilla.');
            $token = bulk_workshops::save_uploaded_file($_FILES['workshopfile'] ?? []);
            $preview = bulk_workshops::preview($token, $courseid);
        } else if ($action === 'confirm') {
            if ($courseid <= 0 || $token === '') throw new RuntimeException('La importación ha caducado. Vuelve a subir el Excel.');
            $result = bulk_workshops::import($token, $courseid, $quiztemplatecmid, $notestemplatecmid);
            $token = '';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php'));
$PAGE->set_title('Importación masiva de talleres');
$PAGE->set_heading('Gestión HEE');

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), '← Volver al panel', ['class' => 'btn btn-outline-secondary mr-2 mb-3']) .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typea']), 'Creación manual de talleres', ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);

echo $OUTPUT->heading('Importación masiva de talleres desde Excel');
echo html_writer::tag('p', 'Esta vía no sustituye la creación manual. Los talleres importados quedan como talleres normales y pueden editarse después uno a uno.', ['class' => 'lead']);

echo html_writer::start_div('alert alert-info');
echo '<strong>Flujo:</strong> descarga la plantilla, completa una fila por taller, súbela, revisa la previsualización y confirma. El sistema reutiliza la creación normal de edición, grupo, inscripción y asistencia. Para los cuestionarios selecciona un cuestionario Moodle modelo vacío del mismo curso; se duplicará y se restringirá al grupo de cada taller.';
echo html_writer::end_div();

if ($error !== '') echo $OUTPUT->notification(s($error), 'error');
if ($result) {
    echo $OUTPUT->notification('Importación finalizada: ' . (int)$result->created . ' taller(es) creados; ' . (int)$result->skipped . ' omitidos; ' . (int)$result->quizcreated . ' cuestionario(s) duplicados; ' . (int)$result->notescreated . ' recurso(s) de apuntes duplicados.', 'success');
    if (!empty($result->messages)) {
        echo html_writer::start_div('card mb-4');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', 'Detalle', ['class' => 'h5']);
        echo html_writer::start_tag('ul');
        foreach ($result->messages as $message) echo html_writer::tag('li', s($message));
        echo html_writer::end_tag('ul');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

$courseoptions = manager::get_course_options();

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', '1. Plantilla Excel', ['class' => 'h5']);
echo html_writer::tag('p', 'La plantilla contiene las columnas Código, Nombre, Tipo, Fecha, horario, horas, plazas, cierre de inscripción, profesor, apuntes y cuestionario.');
echo html_writer::link(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php', ['action' => 'template', 'sesskey' => sesskey()]), 'Descargar plantilla XLSX', ['class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();

if ($token === '' && !$result) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', '2. Subir y previsualizar', ['class' => 'h5']);
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'upload']);
    echo html_writer::label('Curso donde se crearán los talleres', 'courseid');
    echo html_writer::select($courseoptions, 'courseid', $courseid, ['' => 'Selecciona un curso'], ['class' => 'form-control mb-3', 'required' => 'required']);
    echo html_writer::label('Plantilla XLSX cumplimentada', 'workshopfile');
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'workshopfile', 'id' => 'workshopfile', 'accept' => '.xlsx', 'class' => 'form-control mb-3', 'required' => 'required']);
    echo html_writer::tag('button', 'Previsualizar importación', ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($token !== '' && $courseid > 0) {
    if (!$preview) {
        try { $preview = bulk_workshops::preview($token, $courseid); } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $quizoptions = bulk_workshops::quiz_templates($courseid);
    $notesoptions = bulk_workshops::notes_templates($courseid);

    echo html_writer::tag('h3', '3. Previsualización', ['class' => 'h4']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Fila', 'Taller', 'Tipo', 'Fecha', 'Horas', 'Plazas', 'Cierre inscripción', 'Profesor', 'Apuntes', 'Cuestionario', 'Estado'];
    $valid = 0;
    $needsquiz = false;
    foreach ($preview as $row) {
        if (!empty($row['ok'])) $valid++;
        if (!empty($row['createquiz'])) $needsquiz = true;
        $messages = [];
        foreach ($row['errors'] as $m) $messages[] = html_writer::span(s($m), 'text-danger d-block');
        foreach ($row['warnings'] as $m) $messages[] = html_writer::span(s($m), 'text-warning d-block');
        if (!$messages) $messages[] = html_writer::span('Preparado', 'badge badge-success');
        $table->data[] = [
            (int)$row['rownum'],
            '<strong>' . s($row['code']) . '</strong><br>' . s($row['name']),
            $row['type'] === 'typeb' ? 'B' : 'A',
            !empty($row['sessiondate']) ? userdate((int)$row['sessiondate'], '%d/%m/%Y %H:%M') : '-',
            format_float((float)$row['hours'], 2, true),
            (int)$row['places'],
            !empty($row['enrolenddate']) ? userdate((int)$row['enrolenddate'], '%d/%m/%Y %H:%M') : '-',
            s($row['teacheremail'] ?: '-'),
            !empty($row['createnotes']) ? 'Sí' : 'No',
            !empty($row['createquiz']) ? 'Sí' : 'No',
            implode('', $messages),
        ];
    }
    echo html_writer::table($table);

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', '4. Modelos Moodle y confirmación', ['class' => 'h5']);
    echo html_writer::tag('p', 'Selecciona un cuestionario modelo vacío. Moodle lo duplicará para cada Taller Tipo A marcado con “Crear cuestionario = Sí”. Las preguntas se podrán crear o importar después por la vía convencional de Moodle.', ['class' => 'text-muted']);
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $token]);
    echo html_writer::label('Cuestionario Moodle modelo', 'quiztemplatecmid');
    echo html_writer::select($quizoptions, 'quiztemplatecmid', $quiztemplatecmid, false, ['class' => 'form-control mb-3', 'required' => $needsquiz ? 'required' : null]);
    echo html_writer::label('Recurso/carpeta modelo para Apuntes (opcional)', 'notestemplatecmid');
    echo html_writer::select($notesoptions, 'notestemplatecmid', $notestemplatecmid, false, ['class' => 'form-control mb-3']);
    echo html_writer::tag('div', '<strong>' . $valid . '</strong> de ' . count($preview) . ' fila(s) están preparadas para crear.', ['class' => 'alert alert-secondary']);
    echo html_writer::tag('button', 'Confirmar y crear talleres', ['type' => 'submit', 'class' => 'btn btn-success', 'disabled' => $valid <= 0 ? 'disabled' : null]);
    echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php'), 'Cancelar', ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
