<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\form\typeb_upload_form;
use local_gestion_actividades\local\portfolio_typeb;
use local_gestion_actividades\local\grade_manager;
use local_gestion_actividades\local\typeb_catalog;

require_login(null, false);
$courseid = optional_param('courseid', 0, PARAM_INT);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/typeb_upload.php', $courseid > 0 ? ['courseid' => $courseid] : []));
$PAGE->set_title('Solicitar validación como Taller Tipo B');
$PAGE->set_heading('Gestión HEE');

function local_ga_typeb_status_badge(string $status): string {
    if ($status === 'validated') {
        return html_writer::span('Validado y completado', 'badge bg-success');
    }
    if ($status === portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION) {
        return html_writer::span('Validado · falta reflexión', 'badge bg-warning text-dark');
    }
    if ($status === 'rejected') {
        return html_writer::span('Rechazado', 'badge bg-danger');
    }
    return html_writer::span('Pendiente', 'badge bg-warning text-dark');
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'save_reflection' && data_submitted()) {
    require_sesskey();
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
        'Reflexión guardada. Las horas de esta formación ya se incorporan al cómputo Tipo B.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$baseurl = new moodle_url('/local/gestion_actividades/typeb_upload.php', $courseid > 0 ? ['courseid' => $courseid] : []);
// Editing a request that is still pending or was rejected.
$editid = optional_param('edit', 0, PARAM_INT) ?: optional_param('requestid', 0, PARAM_INT);
$editing = null;
if ($editid > 0) {
    $candidate = portfolio_typeb::get($editid);
    if ((int)$candidate->userid === (int)$USER->id && portfolio_typeb::is_editable($candidate)) {
        $editing = $candidate;
    }
}
$options = typeb_catalog::options_for_user((int)$USER->id, $editing ? (int)$editing->id : 0, $editing ? (int)$editing->catalogid : 0);
$formurl = new moodle_url($baseurl, $editing ? ['edit' => (int)$editing->id] : []);
$uploadform = new typeb_upload_form($formurl, ['courseid' => $courseid, 'options' => $options, 'requestid' => $editing ? (int)$editing->id : 0]);
if ($editing) {
    $uploadform->set_data(['catalogid' => (int)$editing->catalogid ?: '', 'activitydate' => (int)$editing->activitydate ?: time(),
        'requestid' => (int)$editing->id, 'courseid' => $courseid]);
}
if ($uploadform->is_cancelled()) {
    redirect($baseurl);
}
if ($data = $uploadform->get_data()) {
    $tmpfilepath = $uploadform->save_temp_file('evidencefile');
    $filename = clean_filename((string)$uploadform->get_new_filename('evidencefile'));
    try {
        if ($editing) {
            portfolio_typeb::update_request((int)$editing->id, (int)$USER->id, (int)$data->catalogid, (int)$data->activitydate,
                $tmpfilepath ? $filename : '', $tmpfilepath ?: '');
            $message = 'Solicitud corregida y enviada de nuevo para su validación.';
        } else {
            if (!$tmpfilepath || !is_readable($tmpfilepath) || $filename === '') {
                throw new moodle_exception('uploadproblem', 'moodle');
            }
            portfolio_typeb::create_from_catalog((int)$USER->id, (int)$data->catalogid, (int)$data->activitydate, $filename, $tmpfilepath);
            $message = 'Certificado enviado. La solicitud queda pendiente hasta que un gestor confirme si puede reconocerse como Taller Tipo B.';
        }
    } catch (invalid_parameter_exception $e) {
        redirect($formurl, $e->debuginfo ?: $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    } finally {
        if ($tmpfilepath && is_file($tmpfilepath)) {
            @unlink($tmpfilepath);
        }
    }
    grade_manager::sync_user_safely((int)$USER->id);
    redirect($baseurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/gestion_actividades/portfolio.php', $courseid > 0 ? ['courseid' => $courseid] : []),
        $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al portafolio',
        ['class' => 'btn btn-outline-secondary mb-3']
    )
);

echo $OUTPUT->heading('Solicitar validación como Taller Tipo B');
echo html_writer::tag(
    'p',
    'Elige el taller Tipo B externo que has realizado, indica la fecha y adjunta su certificado. Cuando un gestor lo valide, deberás escribir una breve reflexión sobre la actividad. Las horas (las del catálogo) se incorporarán al cómputo Tipo B cuando estén cumplidos los dos pasos: validación y reflexión. Mientras no esté validada puedes corregir tu solicitud con «Editar».',
    ['class' => 'alert alert-info']
);
if ($editing) {
    echo html_writer::tag('h3', 'Editar solicitud: ' . s($editing->activityname), ['class' => 'h4']);
}
if (!$options && !$editing) {
    echo $OUTPUT->notification(typeb_catalog::all(true)
        ? 'Ya has presentado solicitud para todos los talleres Tipo B del catálogo. Puedes revisarlas o editarlas abajo.'
        : 'Todavía no hay talleres Tipo B externos en el catálogo. Consulta con la coordinación de Gestión HEE.', 'info');
} else {
    $uploadform->display();
}

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
        echo html_writer::start_div('me-3');
        echo html_writer::tag('h4', s($request->activityname), ['class' => 'h5 mb-1']);
        echo html_writer::tag(
            'div',
            (!empty($request->activitydate) ? userdate((int)$request->activitydate, get_string('strftimedatefullshort', 'langconfig')) : '-') .
            ' · ' . format_float((float)$request->hours, 2, true) . ' h · ' . local_ga_typeb_status_badge((string)$request->status),
            ['class' => 'text-muted']
        );
        echo html_writer::end_div();
        echo html_writer::start_div();
        if (portfolio_typeb::is_editable($request)) {
            echo html_writer::link(new moodle_url($baseurl, ['edit' => (int)$request->id]), 'Editar',
                ['class' => 'btn btn-primary btn-sm me-1']);
        }
        echo html_writer::link(
            new moodle_url('/local/gestion_actividades/typeb_view.php', ['id' => (int)$request->id]),
            'Ver certificado',
            ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']
        );
        echo html_writer::end_div();
        echo html_writer::end_div();

        if (trim((string)($request->reviewcomment ?? '')) !== '') {
            echo html_writer::tag('p', '<strong>Comentario del gestor:</strong> ' . s($request->reviewcomment), ['class' => 'mt-3 mb-2']);
        }

        if ($countable) {
            echo html_writer::div('Reflexión entregada · Apto · Las horas computan como Tipo B.', 'alert alert-success mt-3 mb-0');
            echo html_writer::tag('p', nl2br(s((string)$request->reflectiontext)), ['class' => 'mt-3 mb-0']);
        } else if (in_array((string)$request->status, [portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION, 'validated'], true)) {
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
        } else if ((string)$request->status === 'pending') {
            echo html_writer::div('Pendiente de revisión. Puedes corregirla con «Editar» mientras no esté validada. La reflexión se habilitará cuando la formación sea validada.', 'alert alert-info mt-3 mb-0');
        } else if ((string)$request->status === 'rejected') {
            echo html_writer::div('Rechazada. Si fue un error (taller, fecha o certificado), corrígela con «Editar» y se enviará de nuevo.', 'alert alert-danger mt-3 mb-0');
        }

        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

echo $OUTPUT->footer();
