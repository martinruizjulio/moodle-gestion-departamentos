<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$editionid = required_param('id', PARAM_INT);
$edition = manager::get_workshop_edition($editionid);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$context = context_course::instance((int)$course->id);

require_login($course);
if (!manager::can_manage_edition($editionid, (int)$USER->id)) {
    throw new required_capability_exception($context, 'moodle/course:update', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/edition_roster.php', ['id' => $editionid]));
$PAGE->set_title('Listado de apuntados');
$PAGE->set_heading(format_string($course->fullname));

$students = manager::list_edition_enrolled_users_ultrasafe($editionid);
$students = array_values($students);
usort($students, static function($a, $b): int {
    $alast = core_text::strtolower((string)($a->lastname ?? ''));
    $blast = core_text::strtolower((string)($b->lastname ?? ''));
    $cmp = $alast <=> $blast;
    if ($cmp !== 0) {
        return $cmp;
    }
    return core_text::strtolower((string)($a->firstname ?? '')) <=> core_text::strtolower((string)($b->firstname ?? ''));
});

$sessiondate = !empty($edition->sessiondate)
    ? userdate((int)$edition->sessiondate, get_string('strftimedatetimeshort', 'langconfig'))
    : '-';

$PAGE->requires->js_init_code("(function(){
    var button = document.getElementById('local-ga-print-roster');
    if (button) {
        button.addEventListener('click', function(){ window.print(); });
    }
})();");

echo $OUTPUT->header();

echo html_writer::start_div('local-ga-roster');
echo html_writer::start_div('local-ga-roster-actions mb-3');
echo html_writer::link(
    new moodle_url('/local/gestion_actividades/workshop_view.php', [
        'id' => (int)$workshop->id,
        'editionid' => $editionid,
    ]),
    $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al taller',
    ['class' => 'btn btn-outline-secondary me-2']
);
echo html_writer::tag(
    'button',
    $OUTPUT->pix_icon('t/print', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Imprimir listado',
    ['type' => 'button', 'id' => 'local-ga-print-roster', 'class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo $OUTPUT->heading('Listado de apuntados');
echo html_writer::tag('h3', format_string($workshop->code . ' - ' . $workshop->name), ['class' => 'h5']);
echo html_writer::tag('p', '<strong>Fecha:</strong> ' . s($sessiondate) . ' &nbsp; <strong>Inscritos:</strong> ' . count($students), ['class' => 'mb-3']);

if ($students) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-ga-roster-table';
    $table->head = ['N.º', get_string('lastname'), get_string('firstname'), 'Asistencia / firma', 'Observaciones'];
    foreach ($students as $index => $student) {
        $table->data[] = [
            (string)($index + 1),
            s((string)$student->lastname),
            s((string)$student->firstname),
            '',
            '',
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('Todavía no hay alumnado apuntado a este taller.', 'info');
}

echo html_writer::end_div();

echo html_writer::tag('style', '
@media print {
    #page-header, #page-footer, .navbar, .secondary-navigation, .drawer, .local-ga-roster-actions,
    [data-region="drawer"], .breadcrumb, .page-context-header { display: none !important; }
    body, #page, #page-content, #region-main, .local-ga-roster { background: #fff !important; }
    #page { margin: 0 !important; }
    #page-content { padding: 0 !important; }
    .local-ga-roster { font-size: 11pt; }
    .local-ga-roster-table { width: 100%; border-collapse: collapse; }
    .local-ga-roster-table th, .local-ga-roster-table td {
        border: 1px solid #000 !important;
        padding: 7px !important;
        height: 34px;
        color: #000 !important;
        background: #fff !important;
    }
    .local-ga-roster-table th:nth-child(1) { width: 6%; }
    .local-ga-roster-table th:nth-child(2) { width: 24%; }
    .local-ga-roster-table th:nth-child(3) { width: 22%; }
    .local-ga-roster-table th:nth-child(4) { width: 25%; }
    .local-ga-roster-table th:nth-child(5) { width: 23%; }
    a[href]:after { content: none !important; }
}
');

echo $OUTPUT->footer();
