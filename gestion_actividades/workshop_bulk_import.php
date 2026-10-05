<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\bulk_workshops;
use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}
workshop_series::ensure_schema();

$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$token = optional_param('token', '', PARAM_ALPHANUM);
$quiztemplatecmid = optional_param('quiztemplatecmid', 0, PARAM_INT);
$notestemplatecmid = optional_param('notestemplatecmid', 0, PARAM_INT);
$seriestitle = optional_param('seriestitle', '', PARAM_TEXT);
$seriesfromtext = optional_param('seriesfrom_text', '', PARAM_TEXT);
$seriestotext = optional_param('seriesto_text', '', PARAM_TEXT);

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
            if (trim($seriestitle) === '') throw new RuntimeException('Indica el título de la edición de talleres.');
            $seriesfrom = strtotime(str_replace('T', ' ', $seriesfromtext)) ?: 0;
            $seriesto = strtotime(str_replace('T', ' ', $seriestotext)) ?: 0;
            if ($seriesfrom <= 0 || $seriesto <= 0 || $seriesto < $seriesfrom) throw new RuntimeException('Las fechas de la edición no son válidas.');
            $token = bulk_workshops::save_uploaded_file($_FILES['workshopfile'] ?? []);
            $preview = bulk_workshops::preview($token, $courseid);
        } else if ($action === 'confirm') {
            if ($courseid <= 0 || $token === '') throw new RuntimeException('La importación ha caducado. Vuelve a subir el Excel.');
            $seriesfrom = strtotime(str_replace('T', ' ', $seriesfromtext)) ?: 0;
            $seriesto = strtotime(str_replace('T', ' ', $seriestotext)) ?: 0;
            if ($seriesfrom <= 0 || $seriesto <= 0 || $seriesto < $seriesfrom) throw new RuntimeException('Las fechas de la edición no son válidas.');
            if (trim($seriestitle) === '') throw new RuntimeException('Indica el título de la edición de talleres.');

            // Validate every Excel row before creating the parent edition, so a bad row
            // never leaves an empty/orphan edition behind.
            $preview = bulk_workshops::preview($token, $courseid);
            if (!$preview) throw new RuntimeException('La plantilla no contiene talleres.');
            foreach ($preview as $row) {
                if (empty($row['ok'])) {
                    throw new RuntimeException('Hay filas con errores. Vuelve a la previsualización y corrígelas antes de crear la edición.');
                }
                if ((int)$row['sessiondate'] < $seriesfrom || (int)$row['sessionenddate'] > $seriesto) {
                    throw new RuntimeException($row['code'] . ': el horario completo queda fuera de las fechas de la edición.');
                }
            }

            $seriesid = workshop_series::save((object)[
                'id' => 0,
                'courseid' => $courseid,
                'title' => $seriestitle,
                'datefrom' => $seriesfrom,
                'dateto' => $seriesto,
                'status' => 'active',
            ]);
            $result = bulk_workshops::import($token, $courseid, $seriesid, $quiztemplatecmid, $notestemplatecmid);
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
    html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php'), 'Ediciones de talleres', ['class' => 'btn btn-outline-secondary mr-2 mb-3']) .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typea']), 'Talleres', ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);

echo $OUTPUT->heading('Crear una edición de talleres desde Excel');
echo html_writer::tag('p', 'La importación crea una sección para la edición, una primera subsección con el calendario HTML y una subsección por cada taller, en el mismo orden del Excel.', ['class' => 'lead']);

echo html_writer::start_div('alert alert-info');
echo '<strong>Manual y automático comparten el mismo modelo:</strong> nombre, descripción, inicio, fin, horas, plazas, cierre de inscripción, profesorado y contenido Moodle. Los cambios posteriores regeneran el calendario desde esos mismos datos.';
echo html_writer::end_div();

if ($error !== '') echo $OUTPUT->notification(s($error), 'error');
if ($result) {
    echo $OUTPUT->notification('Edición creada: ' . (int)$result->created . ' taller(es); ' . (int)$result->skipped . ' omitidos; ' . (int)$result->quizcreated . ' cuestionario(s); ' . (int)$result->notescreated . ' recurso(s) de apuntes.', 'success');
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php', ['id' => $result->seriesid]), 'Abrir edición creada', ['class' => 'btn btn-primary']),
        'mb-4'
    );
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
echo html_writer::tag('h3', '1. Plantilla Excel de talleres', ['class' => 'h5']);
echo html_writer::tag('p', 'Cada fila corresponde a un taller y el orden de las filas será el orden de las subsecciones Taller 01, Taller 02, etc.');
echo html_writer::link(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php', ['action' => 'template', 'sesskey' => sesskey()]), 'Descargar plantilla XLSX', ['class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();

if ($token === '' && !$result) {
    $defaultfrom = time() + 7 * DAYSECS;
    $defaultto = $defaultfrom + 6 * DAYSECS;
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', '2. Datos de la edición y archivo Excel', ['class' => 'h5']);
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'upload']);
    echo html_writer::label('Curso donde se creará la edición', 'courseid');
    echo html_writer::select($courseoptions, 'courseid', $courseid, ['' => 'Selecciona un curso'], ['class' => 'form-control mb-3', 'required' => 'required']);
    echo html_writer::label('Título de la edición de talleres', 'seriestitle');
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'seriestitle', 'id' => 'seriestitle', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => $seriestitle, 'placeholder' => 'Edición de Talleres · Octubre 2026']);
    echo html_writer::label('Inicio de la edición', 'seriesfrom_text');
    echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'seriesfrom_text', 'id' => 'seriesfrom_text', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => $seriesfromtext !== '' ? $seriesfromtext : date('Y-m-d\TH:i', $defaultfrom)]);
    echo html_writer::label('Fin de la edición', 'seriesto_text');
    echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'seriesto_text', 'id' => 'seriesto_text', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => $seriestotext !== '' ? $seriestotext : date('Y-m-d\TH:i', $defaultto)]);
    echo html_writer::label('Plantilla XLSX cumplimentada', 'workshopfile');
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'workshopfile', 'id' => 'workshopfile', 'accept' => '.xlsx', 'class' => 'form-control mb-3', 'required' => 'required']);
    echo html_writer::tag('button', 'Previsualizar edición', ['type' => 'submit', 'class' => 'btn btn-primary']);
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
    $seriesfrom = strtotime(str_replace('T', ' ', $seriesfromtext)) ?: 0;
    $seriesto = strtotime(str_replace('T', ' ', $seriestotext)) ?: 0;

    echo html_writer::tag('h3', '3. Previsualización de ' . s($seriestitle), ['class' => 'h4']);
    echo html_writer::tag('p', 'Fechas de la edición: ' . ($seriesfrom ? userdate($seriesfrom, '%d/%m/%Y %H:%M') : '-') . ' – ' . ($seriesto ? userdate($seriesto, '%d/%m/%Y %H:%M') : '-'), ['class' => 'text-muted']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Orden', 'Taller', 'Tipo', 'Fecha y horario', 'Horas', 'Plazas', 'Cierre inscripción', 'Profesor', 'Apuntes', 'Cuestionario', 'Estado'];
    $valid = 0;
    $needsquiz = false;
    $order = 1;
    foreach ($preview as $row) {
        $rowvalid = !empty($row['ok']);
        $messages = [];
        foreach ($row['errors'] as $m) $messages[] = html_writer::span(s($m), 'text-danger d-block');
        foreach ($row['warnings'] as $m) $messages[] = html_writer::span(s($m), 'text-warning d-block');
        if ($rowvalid && $seriesfrom && $seriesto && ((int)$row['sessiondate'] < $seriesfrom || (int)$row['sessionenddate'] > $seriesto)) {
            $rowvalid = false;
            $messages[] = html_writer::span('El horario completo queda fuera de la edición.', 'text-danger d-block');
        }
        if ($rowvalid) $valid++;
        if (!empty($row['createquiz'])) $needsquiz = true;
        if (!$messages) $messages[] = html_writer::span('Preparado', 'badge badge-success');
        $schedule = '-';
        if (!empty($row['sessiondate'])) {
            $schedule = userdate((int)$row['sessiondate'], '%d/%m/%Y %H:%M');
            if (!empty($row['sessionenddate'])) {
                $schedule .= '–' . userdate((int)$row['sessionenddate'], '%H:%M');
            }
        }
        $table->data[] = [
            sprintf('%02d', $order++),
            '<strong>' . s($row['code']) . '</strong><br>' . s($row['name']),
            $row['type'] === 'typeb' ? 'B' : 'A',
            $schedule,
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
    echo html_writer::tag('p', 'El cuestionario se duplica desde un cuestionario Moodle modelo. Las preguntas se seguirán creando o importando después mediante las herramientas normales de Moodle.', ['class' => 'text-muted']);
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $token]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'seriestitle', 'value' => $seriestitle]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'seriesfrom_text', 'value' => $seriesfromtext]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'seriesto_text', 'value' => $seriestotext]);
    echo html_writer::label('Cuestionario Moodle modelo', 'quiztemplatecmid');
    echo html_writer::select($quizoptions, 'quiztemplatecmid', $quiztemplatecmid, false, ['class' => 'form-control mb-3', 'required' => $needsquiz ? 'required' : null]);
    echo html_writer::label('Recurso/carpeta modelo para Apuntes (opcional)', 'notestemplatecmid');
    echo html_writer::select($notesoptions, 'notestemplatecmid', $notestemplatecmid, false, ['class' => 'form-control mb-3']);
    echo html_writer::tag('div', '<strong>' . $valid . '</strong> de ' . count($preview) . ' fila(s) están preparadas para crear.', ['class' => 'alert alert-secondary']);
    echo html_writer::tag('button', 'Confirmar y crear edición completa', ['type' => 'submit', 'class' => 'btn btn-success', 'disabled' => $valid !== count($preview) || $valid <= 0 ? 'disabled' : null]);
    echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/workshop_bulk_import.php'), 'Cancelar', ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
