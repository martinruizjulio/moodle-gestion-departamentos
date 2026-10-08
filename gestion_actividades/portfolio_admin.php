<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\portfolio_typeb;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$q = optional_param('q', '', PARAM_TEXT);
$userid = optional_param('userid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHANUMEXT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['q' => $q, 'userid' => $userid, 'status' => $status]));
$PAGE->set_title('Portafolio de certificados - gestor');
$PAGE->set_heading('Portafolio de certificados - gestor');

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_admin_badge(string $status): string {
    if ($status === 'generated' || $status === 'validated') {
        return html_writer::span($status === 'generated' ? 'Generado' : 'Validado y completado', 'badge bg-success', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
    }
    if ($status === portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION) {
        return html_writer::span('Validado · falta reflexión', 'badge bg-warning text-dark', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
    }
    if ($status === 'pending') {
        return html_writer::span('Pendiente de validar', 'badge bg-warning text-dark', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
    }
    if ($status === 'rejected') {
        return html_writer::span('Rechazado', 'badge bg-danger', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
    }
    return html_writer::span(s($status), 'badge bg-secondary', ['style' => 'font-size:0.85rem;padding:6px 9px;']);
}

function local_ga_admin_typea_hours_from_certificates(array $certificates): float {
    $total = 0.0;
    foreach ($certificates as $c) {
        if (isset($c->hours) && $c->hours !== null && $c->hours !== '') {
            $total += (float)$c->hours;
        }
    }
    return $total;
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');
echo $OUTPUT->heading('Portafolio de certificados - gestor');
echo html_writer::tag('p', 'En los reconocimientos externos Tipo B el alumno elige un taller del catálogo y sube su certificado. Hay dos pasos: el gestor valida el certificado y, después, el alumno entrega una breve reflexión. Las horas (las del catálogo) solo computan cuando ambos pasos están completados.', ['class' => 'alert alert-info']);

$pendingcount = 0;
try {
    $pendingcount = count(portfolio_typeb::list_all(0, 'pending'));
} catch (Throwable $e) {
    $pendingcount = 0;
}
if ($pendingcount > 0) {
    echo $OUTPUT->notification('Hay ' . $pendingcount . ' certificado(s) Tipo B pendiente(s) de validar. Revisa el PDF antes de validar o rechazar.', 'warning');
}

echo html_writer::start_div('mb-3');
echo html_writer::link(new moodle_url('/local/gestion_actividades/manager_downloads.php'), local_ga_btn_icon('t/download', 'Listados y descargas'), ['class' => 'btn btn-primary']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/typeb_catalog.php'), local_ga_btn_icon('t/edit', 'Catálogo de talleres B'), ['class' => 'btn btn-warning']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_cover_template.php'), local_ga_btn_icon('t/edit', 'Editar portada PDF'), ['class' => 'btn btn-secondary']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_pdf_all.php', ['sesskey' => sesskey()]), local_ga_btn_icon('t/download', 'Descargar todos los portafolios'), ['class' => 'btn btn-primary']);
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
echo html_writer::start_div('input-group');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => s($q), 'placeholder' => 'Buscar alumno por nombre o email', 'class' => 'form-control']);
echo html_writer::select([
    '' => 'Todos los estados Tipo B',
    'pending' => 'Pendientes de validar',
    portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION => 'Validados · esperando reflexión',
    'validated' => 'Validados y completados',
    'rejected' => 'Rechazados',
], 'status', $status, false, ['class' => 'custom-select']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Buscar', 'class' => 'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$selecteduser = null;
if ($userid <= 0 && trim($q) !== '') {
    $like = '%' . $DB->sql_like_escape(trim($q)) . '%';
    $users = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE deleted = 0 AND (" . $DB->sql_like('firstname', ':q1', false) . " OR " . $DB->sql_like('lastname', ':q2', false) . " OR " . $DB->sql_like('email', ':q3', false) . ") ORDER BY lastname, firstname", ['q1' => $like, 'q2' => $like, 'q3' => $like], 0, 30);
    if (count($users) === 1) {
        $u = reset($users);
        $userid = (int)$u->id;
    } else if ($users) {
        echo html_writer::tag('h3', 'Resultados de búsqueda');
        $table = new html_table();
        $table->head = ['Alumno', 'Email', 'Acción'];
        foreach ($users as $u) {
            $table->data[] = [fullname($u), s($u->email), html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['userid' => $u->id]), local_ga_btn_icon('i/report', 'Ver portafolio'), ['class' => 'btn btn-secondary btn-sm'])];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('No se han encontrado alumnos.', 'info');
    }
}

if ($userid > 0) {
    $selecteduser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
}

if ($selecteduser) {
    echo html_writer::tag('h2', 'Portafolio de ' . fullname($selecteduser));
    $typeacerts = method_exists(manager::class, 'list_user_certificates') ? manager::list_user_certificates((int)$selecteduser->id) : [];
    $hours = \local_gestion_actividades\local\hours_calculator::for_user((int)$selecteduser->id);
    echo html_writer::tag('p', 'Horas Tipo A: ' . round((float)$hours->typeahours, 2) . ' h · Horas Tipo B: ' . round((float)$hours->typebhours, 2)
        . ' h (externas completadas: ' . round((float)$hours->externaltypeb, 2) . ' h) · Total reconocido: ' . round((float)$hours->totalhours, 2) . ' h', ['class' => 'alert alert-info']);
    echo html_writer::start_div('mb-3');
    echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_pdf_download.php', ['userid' => $selecteduser->id]), local_ga_btn_icon('t/download', 'Descargar portafolio PDF de este alumno'), ['class' => 'btn btn-primary']);
    echo ' ';
    echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_package_download.php', ['userid' => $selecteduser->id]), local_ga_btn_icon('t/download', 'Descargar expediente completo ZIP'), ['class' => 'btn btn-primary']);
    echo html_writer::end_div();

    echo html_writer::tag('h3', 'Talleres Tipo A');
    if ($typeacerts) {
        $table = new html_table();
        $table->head = ['Taller', 'Horas', 'Fecha emisión', 'Estado', 'Acciones'];
        foreach ($typeacerts as $c) {
            $actions = html_writer::link(new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $c->id]), local_ga_btn_icon('t/download', 'Descargar'), ['class' => 'btn btn-secondary btn-sm']);
            if (file_exists(__DIR__ . '/regenerate_certificate.php')) {
                $actions .= ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/regenerate_certificate.php', ['id' => $c->id, 'sesskey' => sesskey()]), local_ga_btn_icon('t/reload', 'Regenerar'), ['class' => 'btn btn-warning btn-sm']);
            }
            $table->data[] = [s($c->workshopcode . ' - ' . $c->workshopname), !empty($c->hours) ? round((float)$c->hours, 2) . ' h' : '-', userdate((int)$c->timeissued), local_ga_admin_badge($c->status ?: 'generated'), $actions];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification('Este alumno no tiene certificados Tipo A generados.', 'info');
    }

    echo html_writer::tag('h3', 'Reconocimientos externos Tipo B');
    $typebcerts = portfolio_typeb::list_all((int)$selecteduser->id, $status);
} else {
    echo html_writer::tag('h2', 'Reconocimientos externos Tipo B');
    $typebcerts = portfolio_typeb::list_all(0, $status);
}

if (!empty($typebcerts)) {
    $table = new html_table();
    $table->head = ['Alumno', 'Taller', 'Fecha', 'Horas', 'Descripción', 'Estado', 'Reflexión', 'Comentario gestor', 'Certificado', 'Acción'];
    foreach ($typebcerts as $c) {
        $pdfactions = html_writer::link(new moodle_url('/local/gestion_actividades/typeb_view.php', ['id' => $c->id]), local_ga_btn_icon('t/preview', 'Ver'), ['class' => 'btn btn-primary btn-sm', 'target' => '_blank']) . ' ' .
                      html_writer::link(new moodle_url('/local/gestion_actividades/typeb_download.php', ['id' => $c->id]), local_ga_btn_icon('t/download', 'Descargar'), ['class' => 'btn btn-secondary btn-sm']);
        if ((string)$c->status === 'pending') {
            $reviewactions = html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/gestion_actividades/typeb_review.php'), 'style' => 'display:inline-block;min-width:240px;']);
            $reviewactions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $reviewactions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $c->id]);
            $reviewactions .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'comment', 'placeholder' => 'Comentario opcional', 'class' => 'form-control form-control-sm mb-1']);
            $reviewactions .= html_writer::tag('button', 'Validar', ['type' => 'submit', 'name' => 'action', 'value' => 'validate', 'class' => 'btn btn-success btn-sm']);
            $reviewactions .= ' ' . html_writer::tag('button', 'Rechazar', ['type' => 'submit', 'name' => 'action', 'value' => 'reject', 'class' => 'btn btn-danger btn-sm']);
            $reviewactions .= html_writer::end_tag('form');
        } else if ((string)$c->status === portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION) {
            $reviewactions = html_writer::span('Esperando reflexión del alumno', 'text-muted');
        } else {
            $reviewactions = '-';
        }
        $reflection = trim((string)($c->reflectiontext ?? ''));
        $table->data[] = [
            isset($c->firstname) ? fullname($c) . '<br><small>' . s($c->email) . '</small>' : '-',
            s($c->activityname),
            !empty($c->activitydate) ? userdate((int)$c->activitydate, get_string('strftimedatefullshort', 'langconfig')) : '-',
            round((float)$c->hours, 2) . ' h',
            !empty($c->activitydescription) ? format_text($c->activitydescription, FORMAT_PLAIN) : '-',
            local_ga_admin_badge((string)$c->status),
            $reflection !== '' ? s(\core_text::substr($reflection, 0, 220)) : '-',
            !empty($c->reviewcomment) ? s($c->reviewcomment) : '-',
            $pdfactions,
            $reviewactions,
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('No hay reconocimientos externos Tipo B con esos criterios.', 'info');
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
