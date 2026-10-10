<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\academic_year;
use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\pat_typeb;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$action = optional_param('action', '', PARAM_ALPHA);
$token = optional_param('token', '', PARAM_ALPHANUM);
$academicyear = optional_param('academicyear', academic_year::current(), PARAM_RAW_TRIMMED);
$filename = optional_param('filename', '', PARAM_FILE);
$error = '';
$result = null;
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if ($action === 'upload') {
            $token = pat_typeb::save_uploaded_file($_FILES['patfile'] ?? []);
            $filename = clean_param((string)($_FILES['patfile']['name'] ?? ''), PARAM_FILE);
            $preview = pat_typeb::preview($token);
        } else if ($action === 'confirm') {
            $hours = optional_param_array('hours', [], PARAM_FLOAT);
            $maxgrades = optional_param_array('grademax', [], PARAM_FLOAT);
            $result = pat_typeb::import($token, $academicyear, $hours, $filename, $maxgrades);
            $token = '';
        } else if ($action === 'annul') {
            $importid = required_param('importid', PARAM_INT);
            $deleted = pat_typeb::annul_import($importid);
            redirect(new moodle_url('/local/gestion_actividades/pat_typeb_import.php'),
                'Importación anulada: se han eliminado ' . $deleted . ' registro(s) PAT y las horas se han recalculado.',
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
if ($token !== '' && !$preview && !$result) {
    try { $preview = pat_typeb::preview($token); } catch (Throwable $e) { $error = $e->getMessage(); }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/pat_typeb_import.php'));
$PAGE->set_title('Importar talleres Tipo B PAT');
$PAGE->set_heading('Gestión HEE');

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), '← Volver al panel',
        ['class' => 'btn local-ga-back-panel mb-3']),
    'mb-2'
);
echo $OUTPUT->heading('Importar talleres Tipo B PAT');
echo html_writer::tag('p',
    'Importa directamente el Excel exportado por la plataforma de 1.º. Estos talleres PAT son distintos de los Tipo B internos: '
    . 'computan horas cuando el alumno tiene asistencia completa (100) y una nota de cuestionario igual o superior a la mitad de la nota máxima (5 sobre 10, 2,5 sobre 5…). '
    . 'Si el alumno todavía no existe en este Moodle, sus datos quedan guardados y se vincularán cuando aparezca en cursos posteriores.',
    ['class' => 'alert alert-info']
);

if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
if ($result) {
    echo $OUTPUT->notification(
        'Importación completada: ' . (int)$result->created . ' registro(s) nuevos, '
        . (int)$result->updated . ' actualizado(s). '
        . (int)$result->linked . ' coincidencia(s) con usuarios actuales y '
        . (int)$result->pending . ' alumno(s) todavía pendientes de vincular.',
        'success'
    );
}

if ($token === '' && !$result) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'upload']);
    echo html_writer::label('Curso académico', 'academicyear');
    $opts = [];
    foreach (academic_year::options([$academicyear]) as $year) {
        $opts[$year] = $year . ($year === academic_year::current() ? ' (actual)' : '');
    }
    echo html_writer::select($opts, 'academicyear', academic_year::normalise($academicyear) ?: academic_year::current(),
        false, ['class' => 'form-select mb-3', 'required' => 'required']);
    echo html_writer::label('Excel exportado por la plataforma', 'patfile');
    echo html_writer::empty_tag('input', [
        'type' => 'file', 'name' => 'patfile', 'id' => 'patfile', 'accept' => '.xlsx',
        'class' => 'form-control mb-3', 'required' => 'required',
    ]);
    echo html_writer::tag('button', 'Previsualizar importación', ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($preview) {
    echo html_writer::tag('h3', 'Talleres detectados', ['class' => 'h4']);
    echo html_writer::tag('p',
        'La asistencia del archivo se aplicará a todos los talleres detectados. Indica las horas que corresponde reconocer por cada taller '
        . 'y la nota máxima de su cuestionario. Es apto quien tiene asistencia 100 y al menos la mitad de la nota máxima.',
        ['class' => 'text-muted']
    );
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $token]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filename', 'value' => $filename]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'academicyear', 'value' => $academicyear]);

    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Taller PAT detectado', 'Nota más alta en el archivo', 'Nota máxima del cuestionario', 'Horas a reconocer'];
    $highest = pat_typeb::max_grades($preview);
    foreach ($preview['quizcols'] as $col => $name) {
        $top = $highest[$col] ?? null;
        $table->data[] = [
            s($name),
            $top === null ? '-' : format_float($top, 2, true),
            html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'grademax[' . (int)$col . ']', 'min' => '0.5', 'step' => '0.5', 'value' => '5',
                'class' => 'form-control form-control-sm', 'required' => 'required', 'style' => 'max-width:120px',
            ]) . ($top !== null && $top > 5.0 ? html_writer::div('Hay notas por encima de 5 (' . format_float($top, 2, true)
                . '): comprueba la nota máxima de este cuestionario.', 'small text-warning') : ''),
            html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'hours[' . (int)$col . ']', 'min' => '0.25', 'step' => '0.25',
                'class' => 'form-control form-control-sm', 'required' => 'required', 'style' => 'max-width:140px',
            ]),
        ];
    }
    echo html_writer::table($table);

    $sample = array_slice($preview['rows'], 0, 8);
    if ($sample) {
        echo html_writer::tag('h4', 'Previsualización de alumnos', ['class' => 'h5 mt-4']);
        $t = new html_table();
        $t->attributes['class'] = 'generaltable table-sm';
        $t->head = ['Nombre', 'Apellidos', 'Número ID', 'DNI / Departamento', 'Correo', 'Asistencia'];
        $headers = [];
        foreach ($preview['headers'] as $i => $h) { $headers[core_text::strtolower(trim($h))] = $i; }
        $idx = static function(array $names) use ($headers): int {
            foreach ($names as $name) {
                $k = core_text::strtolower(trim($name));
                if (isset($headers[$k])) { return (int)$headers[$k]; }
            }
            return -1;
        };
        $ni=$idx(['Nombre']); $ai=$idx(['Apellido(s)','Apellidos']); $idi=$idx(['Número de ID','Numero de ID']);
        $dni=$idx(['Departamento']); $emi=$idx(['Dirección de correo','Direccion de correo']);
        foreach ($sample as $row) {
            $t->data[] = [
                $ni >= 0 ? s($row[$ni] ?? '') : '-',
                $ai >= 0 ? s($row[$ai] ?? '') : '-',
                $idi >= 0 ? s($row[$idi] ?? '') : '-',
                $dni >= 0 ? s($row[$dni] ?? '') : '-',
                $emi >= 0 ? s($row[$emi] ?? '') : '-',
                s($row[$preview['attendancecol']] ?? '-'),
            ];
        }
        echo html_writer::table($t);
    }

    echo html_writer::tag('button', 'Confirmar e importar PAT', ['type' => 'submit', 'class' => 'btn btn-success']);
    echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/pat_typeb_import.php'), 'Cancelar', ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
}

if (!$preview) {
    $imports = pat_typeb::list_imports();
    echo html_writer::tag('h3', 'Importaciones realizadas', ['class' => 'h4 mt-4']);
    if (!$imports) {
        echo $OUTPUT->notification('Todavía no hay importaciones PAT.', 'info');
    } else {
        echo html_writer::tag('p', '«Anular» elimina los registros PAT de esa importación y recalcula las horas de los alumnos. '
            . 'Si un alumno y taller se volvieron a importar después, se conserva el dato de la importación posterior.', ['class' => 'text-muted']);
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Fecha', 'Archivo', 'Curso académico', 'Talleres', 'Alumnos', 'Registros (aptos)', 'Acción'];
        foreach ($imports as $imp) {
            $form = html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline',
                'onsubmit' => 'return confirm(' . json_encode('¿Anular esta importación PAT? Se eliminarán ' . (int)$imp->currentrows
                    . ' registro(s) y las horas de los alumnos se recalcularán. No se puede deshacer (habría que volver a importar el Excel).') . ');']);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'annul']);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'importid', 'value' => (int)$imp->id]);
            $form .= html_writer::tag('button', 'Anular importación', ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
            $form .= html_writer::end_tag('form');
            $table->data[] = [
                userdate((int)$imp->timecreated, '%d/%m/%Y %H:%M'),
                s($imp->filename ?: '-'),
                s($imp->academicyear ?: '-'),
                nl2br(s((string)$imp->workshops)),
                (int)$imp->students,
                (int)$imp->currentrows . ' (' . (int)$imp->currentpassed . ')',
                $imp->currentrows > 0 ? $form : html_writer::span('Sin registros vigentes', 'text-muted small') . ' ' . $form,
            ];
        }
        echo html_writer::table($table);
    }
}
echo $OUTPUT->footer();
