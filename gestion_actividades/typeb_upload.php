<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\portfolio_typeb;
use local_gestion_actividades\local\grade_manager;

require_login();
$courseid = optional_param('courseid', 0, PARAM_INT);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/typeb_upload.php', $courseid > 0 ? ['courseid' => $courseid] : []));
$PAGE->set_title('Solicitar validación como Taller Tipo B');
$PAGE->set_heading('Gestión HEE');

function local_ga_typeb_status_badge(string $status): string {
    if ($status === 'validated') {
        return html_writer::span('Validado', 'badge badge-success');
    }
    if ($status === 'rejected') {
        return html_writer::span('Rechazado', 'badge badge-danger');
    }
    return html_writer::span('Pendiente', 'badge badge-warning');
}

if (data_submitted()) {
    require_sesskey();
    $activityname = required_param('activityname', PARAM_TEXT);
    $activitydescription = required_param('activitydescription', PARAM_TEXT);
    $hours = required_param('hours', PARAM_FLOAT);
    $activitydateinput = required_param('activitydate', PARAM_RAW_TRIMMED);
    $activitydate = strtotime($activitydateinput . ' 12:00:00');
    if ($activitydate === false) {
        throw new moodle_exception('invaliddate');
    }
    if ($hours <= 0 || $hours > 500) {
        throw new moodle_exception('invaliddata', 'error', '', 'Las horas deben ser superiores a 0.');
    }
    if (empty($_FILES['evidencefile']) || !is_uploaded_file($_FILES['evidencefile']['tmp_name']) || $_FILES['evidencefile']['error'] !== UPLOAD_ERR_OK) {
        throw new moodle_exception('uploadproblem', 'moodle');
    }
    $maxbytes = 20 * 1024 * 1024;
    if ((int)$_FILES['evidencefile']['size'] > $maxbytes) {
        throw new moodle_exception('maxbytes', 'error', '', display_size($maxbytes));
    }
    $filename = clean_filename((string)$_FILES['evidencefile']['name']);
    if ($filename === '') {
        throw new moodle_exception('invalidfilename', 'error');
    }
    portfolio_typeb::create_upload(
        (int)$USER->id,
        $activityname,
        (int)$activitydate,
        (float)$hours,
        $activitydescription,
        $filename,
        (string)$_FILES['evidencefile']['tmp_name']
    );
    grade_manager::sync_user_safely((int)$USER->id);
    redirect(
        new moodle_url('/local/gestion_actividades/typeb_upload.php', $courseid > 0 ? ['courseid' => $courseid] : []),
        'Certificado enviado. La solicitud queda pendiente hasta que un gestor confirme si puede reconocerse como Taller Tipo B.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/gestion_actividades/portfolio.php', $courseid > 0 ? ['courseid' => $courseid] : []),
        $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall mr-1']) . ' Volver al portafolio',
        ['class' => 'btn btn-outline-secondary mb-3']
    )
);

echo $OUTPUT->heading('Solicitar validación como Taller Tipo B');
echo html_writer::tag(
    'p',
    'Si has realizado una formación externa, puedes adjuntar su certificado para que sea revisado. Las horas solo se incorporarán al cómputo Tipo B y a tu portafolio cuando la solicitud sea validada por un gestor.',
    ['class' => 'alert alert-info']
);

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'card mb-4']);
echo html_writer::start_div('card-body');
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
if ($courseid > 0) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
}
$fields = [
    ['Nombre de la formación', 'activityname', 'text'],
    ['Fecha de realización', 'activitydate', 'date'],
    ['Horas acreditadas', 'hours', 'number'],
];
foreach ($fields as [$label, $name, $type]) {
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', $label, ['for' => $name]);
    $attrs = ['type' => $type, 'name' => $name, 'id' => $name, 'class' => 'form-control', 'required' => 'required'];
    if ($type === 'number') {
        $attrs['min'] = '0.5';
        $attrs['max'] = '500';
        $attrs['step'] = '0.5';
    }
    echo html_writer::empty_tag('input', $attrs);
    echo html_writer::end_div();
}
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Descripción o justificación de la formación', ['for' => 'activitydescription']);
echo html_writer::tag('textarea', '', ['name' => 'activitydescription', 'id' => 'activitydescription', 'class' => 'form-control', 'rows' => 5, 'required' => 'required']);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Certificado acreditativo', ['for' => 'evidencefile']);
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'evidencefile', 'id' => 'evidencefile', 'class' => 'form-control', 'required' => 'required', 'accept' => '.pdf,.jpg,.jpeg,.png']);
echo html_writer::tag('small', 'PDF o imagen. Tamaño máximo: 20 MB.', ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::tag('button', 'Enviar para validación', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$requests = portfolio_typeb::list_for_user((int)$USER->id);
echo html_writer::tag('h3', 'Mis solicitudes de validación', ['class' => 'h4 mt-4']);
if (!$requests) {
    echo $OUTPUT->notification('Todavía no has enviado certificados externos para su validación como Tipo B.', 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Formación', 'Fecha', 'Horas', 'Estado', 'Comentario del gestor', 'Certificado'];
    foreach ($requests as $request) {
        $fileactions = html_writer::link(
            new moodle_url('/local/gestion_actividades/typeb_view.php', ['id' => (int)$request->id]),
            'Ver',
            ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']
        );
        $table->data[] = [
            s($request->activityname),
            !empty($request->activitydate) ? userdate((int)$request->activitydate, get_string('strftimedatefullshort', 'langconfig')) : '-',
            format_float((float)$request->hours, 2, true) . ' h',
            local_ga_typeb_status_badge((string)$request->status),
            trim((string)($request->reviewcomment ?? '')) !== '' ? s($request->reviewcomment) : '-',
            $fileactions,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
