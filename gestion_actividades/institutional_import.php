<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\institutional_hours;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$action = optional_param('action', '', PARAM_ALPHA);
$token = optional_param('token', '', PARAM_ALPHANUM);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/institutional_import.php'));
$PAGE->set_title('Importar reconocimiento institucional');
$PAGE->set_heading('Gestión HEE');

function local_ga_inst_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_inst_status_badge(string $status): string {
    if ($status === 'found') {
        return html_writer::span('Encontrado', 'badge bg-success');
    }
    if ($status === 'notfound') {
        return html_writer::span('No encontrado', 'badge bg-warning text-dark');
    }
    if ($status === 'duplicate') {
        return html_writer::span('Duplicado', 'badge bg-danger');
    }
    return html_writer::span('Inválido', 'badge bg-secondary');
}

function local_ga_inst_render_preview(array $rows, bool $limit = true): string {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Fila', 'Alumno Excel', 'Email', 'Curso', 'Grupo', 'Horas A', 'Horas B', 'Nota Seminario A', 'Estado'];
    $max = $limit ? min(250, count($rows)) : count($rows);
    for ($i = 0; $i < $max; $i++) {
        $r = $rows[$i];
        $table->data[] = [
            (int)($r['rownum'] ?? 0),
            s($r['fullname'] ?? ''),
            s($r['email'] ?? ''),
            s($r['courselevel'] ?? ''),
            s($r['groupname'] ?? ''),
            format_float((float)($r['typeahours'] ?? 0), 2, true) . ' h',
            format_float((float)($r['typebhours'] ?? 0), 2, true) . ' h',
            (array_key_exists('taskgrade', $r) && $r['taskgrade'] !== null && $r['taskgrade'] !== '') ? format_float((float)$r['taskgrade'], 2, true) : '-',
            local_ga_inst_status_badge((string)($r['status'] ?? 'invalid')) . '<br><small>' . s($r['statuslabel'] ?? '') . '</small>',
        ];
    }
    $html = html_writer::table($table);
    if ($limit && count($rows) > $max) {
        $html .= html_writer::div('Mostrando las primeras ' . $max . ' filas de ' . count($rows) . '.', 'text-muted small');
    }
    return $html;
}

$message = '';
$error = '';
$preview = null;
$result = null;

try {
    institutional_hours::ensure_table();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_sesskey();
        if ($action === 'preview') {
            $token = institutional_hours::save_uploaded_file($_FILES['importfile'] ?? []);
            $preview = institutional_hours::preview_from_token($token);
        } else if ($action === 'confirm') {
            $result = institutional_hours::import_from_token($token, (int)$USER->id);
            $message = 'Importación completada. Creados: ' . (int)$result->created . '. Actualizados: ' . (int)$result->updated . '. Omitidos: ' . (int)$result->skipped . '.';
            $preview = institutional_hours::preview_from_token($token);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (function_exists('debugging')) {
        debugging('Error en importación de reconocimiento institucional: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), local_ga_inst_btn_icon('t/left', 'Volver al panel'), ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');
echo html_writer::tag('h1', 'Importar reconocimiento institucional');
echo html_writer::tag('p', 'Importa un Excel institucional con horas Tipo A y Tipo B ya reconocidas. El cruce con Moodle se realiza por email. Las horas Tipo B quedarán pendientes del comentario obligatorio del alumno en su portafolio. No se modifican alumnos no encontrados.', ['class' => 'lead']);

if ($message !== '') {
    echo $OUTPUT->notification($message, 'success');
}
if ($error !== '') {
    echo $OUTPUT->notification('No se ha podido procesar el archivo. Revisa el formato y vuelve a intentarlo.', 'error');
    echo html_writer::tag('details',
        html_writer::tag('summary', 'Ver detalle técnico') .
        html_writer::tag('pre', s($error), ['class' => 'small mb-0']),
        ['class' => 'alert alert-light border']
    );
}

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', '1. Subir Excel', ['class' => 'h4']);
echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'preview']);
echo html_writer::tag('label', 'Archivo Excel (.xlsx)', ['for' => 'importfile']);
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'importfile', 'id' => 'importfile', 'accept' => '.xlsx', 'required' => 'required', 'class' => 'form-control-file mb-3']);
echo html_writer::tag('button', local_ga_inst_btn_icon('i/import', 'Previsualizar sin guardar'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

if ($preview) {
    $summary = $preview['summary'];
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h2', '2. Revisión previa', ['class' => 'h4']);
    echo html_writer::div(
        'Filas leídas: ' . (int)$summary->total . ' · Encontrados: ' . (int)$summary->found . ' · No encontrados: ' . (int)$summary->notfound . ' · Duplicados: ' . (int)$summary->duplicate . ' · Inválidos: ' . (int)$summary->invalid . ' · Horas A encontradas en Moodle: ' . format_float((float)$summary->typeahours, 2, true) . ' h · Horas B encontradas en Moodle: ' . format_float((float)$summary->typebhours, 2, true) . ' h · Filas con nota Seminario A: ' . (int)($summary->withgrade ?? 0),
        'alert alert-info'
    );
    echo local_ga_inst_render_preview($preview['rows']);
    if ((int)$summary->found > 0 && $result === null) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => s($token)]);
        echo html_writer::tag('button', local_ga_inst_btn_icon('i/checked', 'Confirmar importación de alumnos encontrados'), ['type' => 'submit', 'class' => 'btn btn-success mt-3']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
