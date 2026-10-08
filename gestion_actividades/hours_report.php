<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\academic_year;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/hours_report.php'));
$PAGE->set_title(get_string('hoursbystudent', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

function local_ga_hours_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

echo $OUTPUT->header();

echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), local_ga_hours_btn_icon('t/left', 'Volver al panel'), ['class' => 'btn local-ga-back-panel mb-3']),
    'mb-2'
);

echo $OUTPUT->heading(get_string('hoursbystudent', 'local_gestion_actividades'));
echo html_writer::tag('p', 'Resumen de horas reconocidas por alumno. Incluye horas Tipo A generadas por certificados/histórico y horas Tipo B validadas por el gestor.', ['class' => 'alert alert-info']);

$ay = academic_year::selected();
echo academic_year::selector(new moodle_url('/local/gestion_actividades/hours_report.php'), $ay,
    'Alumnos con actividad en ese curso; las horas son el total acumulado de la carrera.');
$rows = array_filter(manager::get_hours_summary_by_student(), static fn($r) => academic_year::user_in_year((int)$r->id, $ay));

if (!$rows) {
    echo $OUTPUT->notification('Todavía no hay horas reconocidas para ningún alumno. Cuando se generen certificados Tipo A o se validen certificados Tipo B aparecerán aquí.', 'info');
} else {
    $table = new html_table();
    $table->head = [
        'Cursos con actividad',
        get_string('student', 'local_gestion_actividades'),
        'Email',
        'Grupo',
        'Talleres Tipo A',
        'Horas Tipo A',
        'Certificados Tipo B validados',
        'Horas Tipo B validadas',
        'Total reconocido',
        'Pendiente hasta 54 h',
        get_string('actions'),
    ];

    foreach ($rows as $row) {
        $total = (float)($row->totalhours ?? 0);
        $pending = max(0, 54 - $total);
        $table->data[] = [
            academic_year::user_years_text((int)$row->id),
            fullname($row),
            s($row->email),
            s(local_gestion_actividades_student_group((int)$row->id)),
            (int)($row->completedworkshops ?? 0),
            round((float)($row->totaltypeahours ?? 0), 2) . ' h',
            (int)($row->validatedtypebcount ?? 0),
            round((float)($row->totaltypebhours ?? 0), 2) . ' h',
            html_writer::tag('strong', round($total, 2) . ' h'),
            round($pending, 2) . ' h',
            html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['userid' => $row->id]), local_ga_hours_btn_icon('i/report', 'Ver portafolio'), ['class' => 'btn btn-secondary btn-sm']),
        ];
    }

    echo html_writer::table($table);
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
