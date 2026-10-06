<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\grade_manager;
use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$courseid = required_param('courseid', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);
$managedcourses = grade_manager::get_managed_courses();
if (!isset($managedcourses[$courseid])) {
    throw new moodle_exception('invalidcourseid');
}
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$rows = grade_manager::get_course_grade_rows($courseid, true);
$filenamebase = clean_filename('Notas_HEE_' . $course->shortname . '_' . date('Ymd_His'));

function local_ga_export_grade($value) {
    return ($value === null || $value === '') ? '' : round((float)$value, 2);
}

if ($format === 'excel') {
    $columns = [
        'lastname' => 'Apellidos',
        'firstname' => 'Nombre',
        'email' => 'Email',
        'typeagrade' => 'Nota Talleres A',
        'portfoliograde' => 'Portafolio',
        'autoevaluationgrade' => 'Autoevaluación',
        'finalgrade' => 'Nota Final',
        'typeahours' => 'Horas Tipo A',
        'typebhours' => 'Horas Tipo B',
        'totalhours' => 'Horas totales',
        'missingtypebcomments' => 'Comentarios Tipo B pendientes',
    ];
    $iterator = new ArrayIterator(array_values($rows));
    \core\dataformat::download_data(
        $filenamebase,
        'excel',
        $columns,
        $iterator,
        static function($row, bool $supportshtml): array {
            return [
                'lastname' => (string)$row->lastname,
                'firstname' => (string)$row->firstname,
                'email' => (string)$row->email,
                'typeagrade' => local_ga_export_grade($row->typeagrade),
                'portfoliograde' => local_ga_export_grade($row->portfoliograde),
                'autoevaluationgrade' => local_ga_export_grade($row->autoevaluationgrade),
                'finalgrade' => local_ga_export_grade($row->finalgrade),
                'typeahours' => round((float)$row->typeahours, 2),
                'typebhours' => round((float)$row->typebhours, 2),
                'totalhours' => round((float)$row->totalhours, 2),
                'missingtypebcomments' => (int)$row->missingtypebcomments,
            ];
        }
    );
    exit;
}

if ($format === 'pdf') {
    require_once($CFG->libdir . '/pdflib.php');
    \core\session\manager::write_close();

    $pdf = new pdf('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Gestión HEE');
    $pdf->SetAuthor('Gestión HEE');
    $pdf->SetTitle('Notas de alumnos HEE');
    $pdf->SetMargins(10, 12, 10);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    $pdf->AddPage();

    $title = '<h1 style="font-size:16pt;">Notas de alumnos HEE</h1>'
        . '<p><strong>Curso:</strong> ' . s(format_string($course->fullname)) . '</p>'
        . '<p style="font-size:9pt;">Nota Final = Nota Talleres A × 60% + Portafolio × 30% + Autoevaluación × 10%. Las notas pendientes se muestran con un guion.</p>';
    $pdf->writeHTML($title, true, false, true, false, '');

    $html = '<table border="1" cellpadding="4" cellspacing="0" style="font-size:8pt;">';
    $html .= '<thead><tr style="font-weight:bold;background-color:#eeeeee;">'
        . '<th width="15%">Apellidos</th>'
        . '<th width="13%">Nombre</th>'
        . '<th width="19%">Email</th>'
        . '<th width="9%">Talleres A</th>'
        . '<th width="8%">Portafolio</th>'
        . '<th width="10%">Autoevaluación</th>'
        . '<th width="8%">Nota Final</th>'
        . '<th width="6%">H. A</th>'
        . '<th width="6%">H. B</th>'
        . '<th width="6%">H. total</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>'
            . '<td>' . s($row->lastname) . '</td>'
            . '<td>' . s($row->firstname) . '</td>'
            . '<td>' . s($row->email) . '</td>'
            . '<td align="center">' . ($row->typeagrade === null ? '-' : format_float((float)$row->typeagrade, 2, true)) . '</td>'
            . '<td align="center">' . ($row->portfoliograde === null ? '-' : format_float((float)$row->portfoliograde, 2, true)) . '</td>'
            . '<td align="center">' . ($row->autoevaluationgrade === null ? '-' : format_float((float)$row->autoevaluationgrade, 2, true)) . '</td>'
            . '<td align="center"><strong>' . ($row->finalgrade === null ? '-' : format_float((float)$row->finalgrade, 2, true)) . '</strong></td>'
            . '<td align="center">' . format_float((float)$row->typeahours, 2, true) . '</td>'
            . '<td align="center">' . format_float((float)$row->typebhours, 2, true) . '</td>'
            . '<td align="center">' . format_float((float)$row->totalhours, 2, true) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filenamebase . '.pdf', 'D');
    exit;
}

throw new invalid_parameter_exception('Formato de exportación no válido.');
