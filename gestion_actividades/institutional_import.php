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

// Empty template with the recommended columns (any layout using the accepted
// header names is also read; see the instructions on the page).
if ($action === 'template') {
    require_sesskey();
    require_once($CFG->libdir . '/filelib.php');
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        throw new moodle_exception('generalexceptionmessage', 'error', '', 'PhpSpreadsheet no está disponible.');
    }
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('TODOS');
    $headers = ['Apellidos', 'Nombre', 'Email', 'Curso', 'Grupo', 'Horas Tipo A', 'Horas Tipo B', 'Nota Tipo A'];
    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle('A1:H1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1F5F99');
    foreach (range('A', 'H') as $c) {
        $sheet->getColumnDimension($c)->setWidth($c === 'C' ? 34 : 16);
    }
    $sheet->freezePane('A2');
    $help = $book->createSheet();
    $help->setTitle('INSTRUCCIONES');
    $help->fromArray([
        ['PLANTILLA RECONOCIMIENTO INSTITUCIONAL · cómo rellenar la hoja TODOS'],
        ['Una fila por alumno. La columna Email es obligatoria: es el correo de su cuenta de Moodle.'],
        ['Horas Tipo A y Horas Tipo B: horas ya reconocidas antes de usar Gestión HEE (decimales con coma o punto: 4,5).'],
        ['Nota Tipo A: opcional, de 0 a 10.'],
        ['Apellidos, Nombre, Curso y Grupo: opcionales, solo para que la revisión previa sea más clara.'],
        ['Si vuelves a importar a un alumno, sus horas institucionales se SUSTITUYEN por las nuevas (no se suman).'],
        ['Las horas Tipo B quedan pendientes hasta que el alumno escriba su comentario en «Mi portafolio HEE».'],
        [''],
        ['Ejemplo de fila:'],
        $headers,
        ['García López', 'Lucía', 'lucia.garcia@ucv.es', '2º', 'A', 12, 4, 7.5],
    ], null, 'A1');
    $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $help->getStyle('A10:H10')->getFont()->setBold(true);
    $help->getColumnDimension('A')->setWidth(18);
    $book->setActiveSheetIndex(0);
    $path = make_request_directory() . '/plantilla.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
    send_temp_file($path, 'Plantilla_Reconocimiento_Institucional.xlsx');
    exit;
}

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
    $table->head = ['Fila', 'Alumno Excel', 'Email', 'Curso', 'Grupo', 'Horas A', 'Horas B', 'Nota Taller A', 'Estado'];
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

// How the Excel must be.
echo html_writer::start_tag('details', ['class' => 'card mb-4', 'open' => 'open']);
echo html_writer::tag('summary', html_writer::tag('strong', 'Cómo debe ser el Excel'), ['class' => 'card-header']);
echo html_writer::start_div('card-body');
echo html_writer::tag('p', 'No hace falta un formato fijo: el sistema busca la fila de encabezados y reconoce las columnas por su nombre, '
    . 'en cualquier orden. Puede haber títulos encima y columnas de más (se ignoran). Se lee la hoja llamada <strong>TODOS</strong> '
    . 'o, si no existe, la primera hoja. Si prefieres no pensarlo, descarga la plantilla y rellénala.');
echo html_writer::link(new moodle_url('/local/gestion_actividades/institutional_import.php', ['action' => 'template', 'sesskey' => sesskey()]),
    local_ga_inst_btn_icon('t/download', 'Descargar plantilla de reconocimiento'), ['class' => 'btn btn-outline-primary mb-3']);
$cols = new html_table();
$cols->attributes['class'] = 'generaltable table-sm';
$cols->head = ['Dato', '¿Obligatorio?', 'Encabezados que se reconocen', 'Contenido'];
$cols->data = [
    ['Email', html_writer::tag('strong', 'Sí'), 'Email · Correo · Correo electrónico', 'Correo de la cuenta Moodle del alumno. Es lo que se usa para encontrarlo.'],
    ['Horas Tipo A', 'Recomendado', 'Horas Tipo A · Total A · Tipo A · Horas A', 'Horas Tipo A ya reconocidas (4 o 4,5).'],
    ['Horas Tipo B', 'Recomendado', 'Horas Tipo B · Total B · Tipo B · Horas B (o «Pte Tipo B»: horas pendientes de 22)', 'Horas Tipo B ya reconocidas.'],
    ['Nota Tipo A', 'No', 'Nota · Nota Tipo A · Promedio · Calificación', 'Nota de 0 a 10.'],
    ['Nombre', 'No', 'Nombre y apellidos · Alumno · o bien Nombre + Apellidos', 'Solo para reconocerlo en la revisión.'],
    ['Curso / Grupo', 'No', 'Curso · Grupo · Clave grupo', 'Informativo.'],
];
echo html_writer::table($cols);
echo html_writer::alist([
    'Una fila por alumno. Las filas sin email se ignoran.',
    'Primero se muestra una <strong>revisión previa sin guardar</strong>: verás quién se encuentra, quién no y quién está repetido. Solo se importan los encontrados al confirmar.',
    'Si un alumno ya tenía horas institucionales, al volver a importarlo se <strong>sustituyen</strong> por las nuevas; no se suman.',
    'Estas horas se suman a las de los talleres de Gestión HEE en el bloque y el portafolio del alumno.',
    'Las horas Tipo B no cuentan hasta que el alumno escribe su comentario en «Mi portafolio HEE».',
]);
echo html_writer::end_div();
echo html_writer::end_tag('details');

if ($preview) {
    $summary = $preview['summary'];
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h2', '2. Revisión previa', ['class' => 'h4']);
    echo html_writer::div(
        'Filas leídas: ' . (int)$summary->total . ' · Encontrados: ' . (int)$summary->found . ' · No encontrados: ' . (int)$summary->notfound . ' · Duplicados: ' . (int)$summary->duplicate . ' · Inválidos: ' . (int)$summary->invalid . ' · Horas A encontradas en Moodle: ' . format_float((float)$summary->typeahours, 2, true) . ' h · Horas B encontradas en Moodle: ' . format_float((float)$summary->typebhours, 2, true) . ' h · Filas con nota Taller A: ' . (int)($summary->withgrade ?? 0),
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
