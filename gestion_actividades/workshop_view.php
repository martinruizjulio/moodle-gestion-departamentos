<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\typeb_certificate_policy;
use local_gestion_actividades\local\workshop_series;

$id = required_param('id', PARAM_INT);
$workshop = manager::get_workshop($id);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);
$requestededitionid = optional_param('editionid', 0, PARAM_INT);
$edition = null;
if ($requestededitionid > 0) {
    $candidate = manager::get_workshop_edition($requestededitionid);
    if ((int)$candidate->workshopid !== (int)$workshop->id
            || !empty($candidate->archived) || manager::is_edition_finished($candidate)) {
        throw new invalid_parameter_exception('La edición solicitada no está disponible para este taller.');
    }
    $series = workshop_series::series_for_edition((int)$candidate->id);
    if ($series && (string)($series->status ?? '') === 'finished') {
        throw new invalid_parameter_exception('La edición de talleres ya está finalizada.');
    }
    $edition = $candidate;
} else {
    $activeeditions = manager::get_active_editions_for_workshop((int)$workshop->id);
    $now = time();
    foreach ($activeeditions as $candidate) {
        $series = workshop_series::series_for_edition((int)$candidate->id);
        if ($series && (string)($series->status ?? '') === 'active'
                && (int)$series->datefrom <= $now && (int)$series->dateto >= $now) {
            $edition = $candidate;
            break;
        }
    }
    if (!$edition && $activeeditions) {
        $future = array_values(array_filter($activeeditions, static function($candidate) use ($now): bool {
            return (int)($candidate->sessiondate ?? 0) >= $now;
        }));
        usort($future, static function($a, $b): int {
            return ((int)$a->sessiondate <=> (int)$b->sessiondate) ?: ((int)$a->id <=> (int)$b->id);
        });
        if ($future) {
            $edition = reset($future);
        } else {
            $ordered = array_values($activeeditions);
            usort($ordered, static function($a, $b): int {
                return ((int)$b->sessiondate <=> (int)$a->sessiondate) ?: ((int)$b->id <=> (int)$a->id);
            });
            $edition = reset($ordered);
        }
    }
}
$editionid = $edition ? (int)$edition->id : 0;
$canmanage = $edition ? manager::can_manage_edition($editionid, (int)$USER->id) : manager::can_manage_globally((int)$USER->id);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$istypeb = manager::is_typeb_workshop($workshop);

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $id, 'editionid' => $editionid]));
$PAGE->set_title(format_string($workshop->name));
$PAGE->set_heading(format_string($course->fullname));

$message = null;
$messagetype = 'info';

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall mr-1']) . ' ' . $label;
}

if ($action === 'enrol' && confirm_sesskey()) {
    if (!$edition) {
        $message = get_string('noeditionavailable', 'local_gestion_actividades');
        $messagetype = 'warning';
    } else {
        $result = manager::enrol_user_in_edition((int)$edition->id, (int)$USER->id, 'self');
        $message = $result->message;
        $messagetype = $result->success ? 'success' : 'warning';
    }
}

echo $OUTPUT->header();

$topbuttons = html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]), local_ga_btn_icon('t/left', get_string('backtocourse', 'local_gestion_actividades')), ['class' => 'btn btn-outline-secondary mr-2 mb-2']);
if ($canmanage) {
    $topbuttons .= html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $id, 'editionid' => $editionid]), local_ga_btn_icon('t/edit', 'Gestionar este taller'), ['class' => 'btn btn-primary mb-2']);
}
echo html_writer::div($topbuttons, 'mb-2');
echo $OUTPUT->heading(format_string($workshop->code . ' - ' . $workshop->name));
if ($message) { echo $OUTPUT->notification($message, $messagetype); }

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', get_string('workshopinfo', 'local_gestion_actividades'));
$description = trim((string)($workshop->description ?? ''));
if ($description !== '') { echo html_writer::tag('p', s($description)); }
$hours = isset($workshop->hours) && $workshop->hours !== null ? round((float)$workshop->hours, 2) . ' h' : '-';
$date = $edition ? manager::format_workshop_date((int)$edition->sessiondate) : '-';
$enrolend = $edition ? manager::format_workshop_date((int)$edition->enrolenddate) : '-';
$places = $edition ? (int)($edition->places ?? 0) : 0;
$enrolled = $edition ? manager::get_edition_enrolment_count((int)$edition->id) : 0;
$table = new html_table();
$table->data = [
    [get_string('date'), $date],
    [get_string('enrolenddate', 'local_gestion_actividades'), $enrolend],
    [get_string('workshophours', 'local_gestion_actividades'), $hours],
    [get_string('places', 'local_gestion_actividades'), $places > 0 ? $enrolled . ' / ' . $places : $enrolled],
];
echo html_writer::table($table);

if (!$edition) {
    echo $OUTPUT->notification(get_string('noeditionavailable', 'local_gestion_actividades'), 'warning');
} else {
    $enrolment = manager::get_edition_enrolment((int)$edition->id, (int)$USER->id);
    if ($enrolment && in_array((string)($enrolment->status ?? ''), ['enrolled', 'attended', 'manual'], true)) {
        echo html_writer::div(get_string('enrolledlabel', 'local_gestion_actividades'), 'local-ga-pill local-ga-pill-ok', ['style' => 'display:inline-block;background:#e9f7ef;border:1px solid #badbcc;border-radius:999px;padding:8px 14px;margin:10px 0;color:#0f5132;font-weight:600;']);
    } else {
        $url = new moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $id, 'editionid' => $editionid, 'action' => 'enrol', 'sesskey' => sesskey()]);
        echo html_writer::link($url, get_string('enrolme', 'local_gestion_actividades'), ['class' => 'btn btn-primary']);
    }
}
echo html_writer::end_div();
echo html_writer::end_div();

$canaccessresources = !empty($edition) && manager::user_can_access_workshop_resources((int)$edition->id, (int)$USER->id);
$resourceblockedmessage = '';
if (!$canaccessresources && !$canmanage) {
    $enrolmentforresources = $edition ? manager::get_edition_enrolment((int)$edition->id, (int)$USER->id) : null;
    if (!$enrolmentforresources || !in_array((string)($enrolmentforresources->status ?? ''), ['enrolled', 'attended', 'manual'], true)) {
        $resourceblockedmessage = 'Los materiales y la actividad estarán disponibles solo para alumnado inscrito.';
    } else if (!empty($edition->sessiondate) && time() < (int)$edition->sessiondate) {
        $resourceblockedmessage = 'Los materiales y la actividad estarán disponibles a partir del día y hora de comienzo del taller.';
    }
}

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', get_string('workshopresources', 'local_gestion_actividades'));
try {
    if (!$canaccessresources && !$canmanage) {
        echo html_writer::tag('p', s($resourceblockedmessage), ['class' => 'text-muted']);
        $materials = [];
    } else {
        $materials = manager::list_materials((int)$workshop->id, $edition ? (int)$edition->id : 0, true);
        if ($materials) {
            echo html_writer::start_tag('ul');
            foreach ($materials as $m) {
                $fileurl = manager::get_material_file_url($m, $context);
                $label = !empty($fileurl) ? html_writer::link($fileurl, s($m->name), ['target' => '_blank']) : (!empty($m->url) ? html_writer::link($m->url, s($m->name), ['target' => '_blank']) : s($m->name));
                echo html_writer::tag('li', $label . (!empty($m->description) ? ' — ' . s($m->description) : ''));
            }
            echo html_writer::end_tag('ul');
        }

        if ($edition && $istypeb) {
            echo html_writer::tag('h4', 'Tarea de reflexión', ['class' => 'mt-3']);
            echo html_writer::tag('p', 'Escribe un breve párrafo describiendo cómo ha sido el taller y tus impresiones. Puedes responder en línea o adjuntar un archivo. Con asistencia confirmada y reflexión entregada quedarás Apto.', ['class' => 'text-muted']);
            $cmid = (int)($edition->requiredcmid ?? 0);
            if ($cmid > 0) {
                $submitted = manager::user_submitted_required_activity((int)$USER->id, $cmid) || manager::user_completed_required_activity((int)$USER->id, $cmid);
                if ($submitted) {
                    echo html_writer::div('Reflexión entregada', 'alert alert-success');
                } else {
                    echo html_writer::div('Reflexión pendiente', 'alert alert-warning');
                }
                echo html_writer::link(new moodle_url('/mod/assign/view.php', ['id' => $cmid]), $submitted ? 'Ver mi reflexión' : 'Entregar reflexión', ['class' => 'btn btn-primary']);
            } else {
                echo $OUTPUT->notification('La tarea de reflexión todavía no está disponible.', 'warning');
            }
        } else if ($edition && in_array('assign', manager::get_required_activity_types($edition), true)) {
            echo html_writer::tag('h4', 'Tarea del taller', ['class' => 'mt-3']);
            if (!empty($edition->taskdescription)) { echo html_writer::tag('p', s($edition->taskdescription)); }
            $taskfile = manager::get_filearea_url($context, 'taskfile', (int)($edition->taskfileitemid ?? 0));
            if ($taskfile !== '') { echo html_writer::tag('p', html_writer::link($taskfile, 'Descargar archivo de la tarea', ['class' => 'btn btn-secondary btn-sm', 'target' => '_blank'])); }
            if (!empty($edition->taskurl)) { echo html_writer::tag('p', html_writer::link($edition->taskurl, 'Abrir enlace de la tarea', ['class' => 'btn btn-secondary btn-sm', 'target' => '_blank'])); }
            if (!empty($edition->taskduedate)) { echo html_writer::tag('p', 'Fecha límite: ' . userdate((int)$edition->taskduedate), ['class' => 'text-muted']); }
            $submission = manager::get_internal_task_submission((int)$edition->id, (int)$USER->id);
            if ($submission && !empty($submission->fileitemid)) { echo html_writer::div('Tarea entregada', 'alert alert-success'); }
            echo html_writer::tag('p', html_writer::link(new moodle_url('/local/gestion_actividades/task_submit.php', ['id' => $edition->id]), 'Entregar tarea', ['class' => 'btn btn-primary']));
        }

        if (!$materials && empty($edition)) {
            echo html_writer::tag('p', get_string('studentresourcespending', 'local_gestion_actividades'), ['class' => 'text-muted']);
        }
    }
} catch (Throwable $e) {
    echo html_writer::tag('p', get_string('studentresourcespending', 'local_gestion_actividades'), ['class' => 'text-muted']);
    if ($canmanage) { echo $OUTPUT->notification('Detalle materiales: ' . s($e->getMessage()), 'warning'); }
}
echo html_writer::end_div();
echo html_writer::end_div();

if (!$canmanage) {
    $courseeditor = has_capability('moodle/course:update', $context, (int)$USER->id);
    if ($courseeditor) {
        echo $OUTPUT->notification('Tienes rol docente en el curso, pero no constas como profesor asignado a este taller. Un gestor debe asignarte en la edición del taller.', 'warning');
    }
}

try {
    if (!empty($edition) && $canmanage) {
        echo html_writer::start_div('card mb-3', ['style' => 'border:2px solid #d8e8d0;']);
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', get_string('certificates', 'local_gestion_actividades'));
        echo html_writer::tag('p', $istypeb ? 'El certificado Tipo B solo se genera cuando constan asistencia y reflexión entregada.' : get_string('certificates_visible_help', 'local_gestion_actividades'), ['class' => 'text-muted']);
        echo html_writer::link(new moodle_url('/local/gestion_actividades/generate_certificates.php', ['id' => $edition->id, 'sesskey' => sesskey()]), get_string('generatecertificates', 'local_gestion_actividades'), ['class' => 'btn btn-primary']);
        echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/certificates.php', ['editionid' => $edition->id]), get_string('viewgeneratedcertificates', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);
        if (manager::can_manage_globally((int)$USER->id)) {
            echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/certificate_template.php'), get_string('certificatetemplate', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
} catch (Throwable $e) {
}

if (!empty($edition)) {
    try {
        $attended = manager::is_user_attended_edition((int)$edition->id, (int)$USER->id);
        if ($attended) {
            echo html_writer::div(html_writer::tag('strong', get_string('attendance', 'local_gestion_actividades') . ': ') . get_string('studentattendanceconfirmed', 'local_gestion_actividades'), 'local-ga-status local-ga-status-ok', ['style' => 'display:inline-block;background:#e9f7ef;border:1px solid #badbcc;border-radius:999px;padding:8px 14px;margin:10px 0;color:#0f5132;font-weight:600;']);
        } else if (manager::get_user_edition_enrolment((int)$edition->id, (int)$USER->id)) {
            echo html_writer::div(html_writer::tag('strong', get_string('attendance', 'local_gestion_actividades') . ': ') . get_string('studentattendancepending', 'local_gestion_actividades'), 'local-ga-status local-ga-status-pending', ['style' => 'display:inline-block;background:#edf4ff;border:1px solid #b6d4fe;border-radius:999px;padding:8px 14px;margin:10px 0;color:#084298;font-weight:600;']);
        }
        if ($istypeb) {
            $eligible = typeb_certificate_policy::is_eligible((int)$edition->id, (int)$USER->id);
            echo html_writer::div('<strong>Resultado Tipo B:</strong> ' . ($eligible ? 'Apto' : 'Pendiente de completar asistencia y/o reflexión'), $eligible ? 'alert alert-success' : 'alert alert-warning');
        }
    } catch (Throwable $e) {
    }
}

if (!empty($edition)) {
    try {
        $cert = manager::get_user_certificate_for_edition((int)$edition->id, (int)$USER->id);
        if ($cert) {
            echo html_writer::div(html_writer::tag('strong', get_string('certificate', 'local_gestion_actividades') . ': ') . html_writer::link(new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $cert->id]), get_string('downloadcertificate', 'local_gestion_actividades'), ['class' => 'btn btn-primary btn-sm']), 'local-ga-certificate-link', ['style' => 'background:#f7f9fb;border:1px solid #d8dee9;border-radius:10px;padding:12px 14px;margin:12px 0;']);
        }
    } catch (Throwable $e) {
    }
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) { local_gestion_actividades_enable_interactive_tables(); }
echo $OUTPUT->footer();