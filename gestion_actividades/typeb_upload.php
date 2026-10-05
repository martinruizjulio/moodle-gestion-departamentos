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

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if (data_submitted()) {
    require_sesskey();

    if ($action === 'save_reflection') {
        $requestid = required_param('requestid', PARAM_INT);
        $reflection = required_param('reflectiontext', PARAM_TEXT);
        if (!portfolio_typeb::save_reflection($requestid, (int)$USER->id, $reflection)) {
            redirect(
                new moodle_url('/local/gestion_actividades/typeb_upload.php', $courseid > 0 ? ['courseid' => $courseid] : []),
                'No se pudo guardar la reflexión. La formación debe estar validada y el texto no puede estar vacío.',
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        grade_manager::sync_user_safely((int)$USER->id);
        redirect(
            new moodle_url('/local/gestion_actividades/typeb_upload.php', $courseid > 0 ? ['courseid' => $courseid] : []),
            'Reflexión guardada. Las horas de esta formación ya pueden incorporarse al cómputo Tipo B.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

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
    'Si has realizado una formación externa, adjunta su certificado para que sea revisado. Cuando un gestor la valide, deberás escribir una breve reflexión sobre la actividad. Las horas solo se incorporarán al cómputo Tipo B cuando estén cumplidos los dos pasos: validación y reflexión.',
    ['class' => 'alert alert-info']
);

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'card mb-4']);
echo html_writer::start_div('card-body');
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'upload']);
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
    foreach ($requests as $request) {
        $countable = portfolio_typeb::is_countable($request);
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-body');
        echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start');
        echo html_writer::start_div('mr-3');
        echo html_writer::tag('h4', s($request->activityname), ['class' => 'h5 mb-1']);
        echo html_writer::tag(
            'div',
            (!empty($request->activitydate) ? userdate((int)$request->activitydate, get_string('strftimedatefullshort', 'langconfig')) : '-') .
            ' · ' . format_float((float)$request->hours, 2, true) . ' h · ' . local_ga_typeb_status_badge((string)$request->status),
            ['class' => 'text-muted']
        );
        echo html_writer::end_div();
        echo html_writer::link(
            new moodle_url('/local/gestion_actividades/typeb_view.php', ['id' => (int)$request->id]),
            'Ver certificado',
            ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']
        );
        echo html_writer::end_div();

        if (trim((string)($request->reviewcomment ?? '')) !== '') {
            echo html_writer::tag('p', '<strong>Comentario del gestor:</strong> ' . s($request->reviewcomment), ['class' => 'mt-3 mb-2']);
        }

        if ((string)$request->status === 'validated') {
            if ($countable) {
                echo html_writer::div('Reflexión entregada · Apto · Las horas computan como Tipo B.', 'alert alert-success mt-3 mb-0');
                echo html_writer::tag('p', nl2br(s((string)$request->reflectiontext)), ['class' => 'mt-3 mb-0']);
            } else {
                echo html_writer::div('Formación validada. Falta la reflexión para que las horas computen como Tipo B.', 'alert alert-warning mt-3');
                echo html_writer::start_tag('form', ['method' => 'post']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_reflection']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'requestid', 'value' => (int)$request->id]);
                if ($courseid > 0) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
                }
                echo html_writer::tag('label', 'Reflexión sobre la actividad', ['for' => 'reflectiontext_' . (int)$request->id]);
                echo html_writer::tag(
                    'textarea',
                    '',
                    [
                        'name' => 'reflectiontext',
                        'id' => 'reflectiontext_' . (int)$request->id,
                        'class' => 'form-control mb-2',
                        'rows' => 5,
                        'required' => 'required',
                        'placeholder' => 'Describe brevemente cómo ha sido la actividad y tus impresiones sobre ella.',
                    ]
                );
                echo html_writer::tag('button', 'Guardar reflexión y completar reconocimiento', ['type' => 'submit', 'class' => 'btn btn-primary']);
                echo html_writer::end_tag('form');
            }
        } else if ((string)$request->status === 'pending') {
            echo html_writer::div('Pendiente de revisión. La reflexión se habilitará cuando la formación sea validada.', 'alert alert-info mt-3 mb-0');
        }

        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

echo $OUTPUT->footer();
