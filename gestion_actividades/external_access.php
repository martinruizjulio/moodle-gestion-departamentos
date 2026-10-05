<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\exception_access;
use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\portfolio_pdf;
use local_gestion_actividades\local\portfolio_typeb;

exception_access::ensure_tables();

function local_ga_exception_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function local_ga_exception_page_start(string $title, ?stdClass $user = null): void {
    $name = $user ? trim((string)$user->firstname . ' ' . (string)$user->lastname) : '';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . local_ga_exception_e($title) . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f4f6f3;margin:0;color:#253126}.wrap{max-width:1180px;margin:0 auto;padding:24px}.card{background:#fff;border:1px solid #dfe4db;border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 2px 8px rgba(0,0,0,.04)}h1,h2,h3{color:#375d25}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.btn{display:inline-block;padding:9px 13px;border-radius:7px;border:0;text-decoration:none;cursor:pointer}.primary{background:#557b2f;color:#fff}.secondary{background:#e7ece3;color:#253126}.danger{background:#a63d32;color:#fff}.success{background:#36743e;color:#fff}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.metric{font-size:28px;font-weight:700;color:#375d25}.muted{color:#657064}.alert{padding:12px;border-radius:8px;margin:12px 0}.error{background:#f9d9d6}.ok{background:#dcefdc}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #e4e7e1;padding:9px;text-align:left;vertical-align:top}input,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #bcc5b7;border-radius:7px}.tabs a{margin-right:8px;margin-bottom:8px}.small{font-size:.9rem}.login{max-width:440px;margin:7vh auto}.nowrap{white-space:nowrap}</style></head><body><div class="wrap">';
    if ($user) {
        echo '<div class="card top"><div><strong>Gestión HEE · Excepcionalidad</strong><br><span class="muted small">' . local_ga_exception_e($name) . ' · ' . local_ga_exception_e((string)$user->email) . '</span></div><a class="btn secondary" href="?action=logout">Cerrar sesión</a></div>';
    }
}

function local_ga_exception_page_end(): void {
    echo '</div></body></html>';
}

$action = optional_param('action', '', PARAM_ALPHAEXT);
if ($action === 'logout') {
    exception_access::logout();
    redirect(new moodle_url('/local/gestion_actividades/external_access.php'));
}

$user = exception_access::current_user();
$loginerror = '';
if (!$user && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $identifier = required_param('identifier', PARAM_RAW_TRIMMED);
    $password = required_param('password', PARAM_RAW);
    if (exception_access::authenticate($identifier, $password)) {
        redirect(new moodle_url('/local/gestion_actividades/external_access.php'));
    }
    $loginerror = 'No se ha podido iniciar sesión. Comprueba las credenciales o espera unos minutos si hubo varios intentos fallidos.';
}

if (!$user) {
    local_ga_exception_page_start('Acceso excepcional HEE');
    echo '<div class="card login"><h1>Acceso excepcional HEE</h1><p class="muted">Acceso independiente del Campus Virtual institucional y limitado exclusivamente a Gestión HEE.</p>';
    if ($loginerror !== '') {
        echo '<div class="alert error">' . local_ga_exception_e($loginerror) . '</div>';
    }
    echo '<form method="post"><input type="hidden" name="action" value="login"><p><label>Correo o usuario</label><input name="identifier" autocomplete="username" required></p><p><label>Contraseña</label><input type="password" name="password" autocomplete="current-password" required></p><button class="btn primary" type="submit">Entrar</button></form></div>';
    local_ga_exception_page_end();
    exit;
}

$csrf = exception_access::csrf_token($user);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'review_typeb') {
    $postedcsrf = required_param('csrf', PARAM_RAW);
    if (!exception_access::validate_csrf($user, $postedcsrf)) {
        $error = 'La sesión ha caducado o el formulario no es válido. Recarga la página.';
    } else {
        $id = required_param('id', PARAM_INT);
        $decision = required_param('decision', PARAM_ALPHA);
        $comment = optional_param('comment', '', PARAM_TEXT);
        if (!in_array($decision, ['validate', 'reject'], true)) {
            $error = 'Acción de revisión no válida.';
        } else {
            $status = $decision === 'validate' ? 'validated' : 'rejected';
            $label = trim((string)$user->firstname . ' ' . (string)$user->lastname);
            $auditcomment = '[Excepcionalidad: ' . $label . '] ' . trim($comment);
            portfolio_typeb::set_status($id, $status, $auditcomment, 0);
            exception_access::log((int)$user->id, $status === 'validated' ? 'typeb_validated' : 'typeb_rejected', 'typeb_certificate', $id, trim($comment));
            $message = $status === 'validated' ? 'Solicitud Tipo B validada.' : 'Solicitud Tipo B rechazada.';
        }
    }
}

if ($action === 'typebfile') {
    $id = required_param('id', PARAM_INT);
    $record = portfolio_typeb::get($id);
    $context = context_system::instance();
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$record->id, '/', (string)$record->filename);
    if (!$file || $file->is_directory()) {
        $area = $fs->get_area_files($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$record->id, 'filename', false);
        foreach ($area as $candidate) {
            if (!$candidate->is_directory()) {
                $file = $candidate;
                break;
            }
        }
    }
    if (!$file || $file->is_directory()) {
        throw new moodle_exception('filenotfound');
    }
    exception_access::log((int)$user->id, 'typeb_certificate_view', 'typeb_certificate', $id, 'Consulta de certificado externo Tipo B.');
    send_stored_file($file, 0, 0, true, ['filename' => (string)$record->filename]);
    exit;
}

if ($action === 'downloadportfolio') {
    $userid = required_param('userid', PARAM_INT);
    $target = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
    exception_access::log((int)$user->id, 'portfolio_download', 'user', $userid, 'Descarga de portafolio PDF.');
    $pdf = portfolio_pdf::render_pdf_string($userid);
    $filename = portfolio_pdf::filename_for_user($target);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . clean_filename($filename) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$view = optional_param('view', 'home', PARAM_ALPHA);
if (!in_array($view, ['home', 'typeb', 'workshops', 'hours'], true)) {
    $view = 'home';
}

$pending = portfolio_typeb::count_pending();
$workshops = [];
try {
    $workshops = manager::get_workshop_overview_rows();
} catch (Throwable $e) {
    $workshops = [];
}
$hoursrows = [];
try {
    $hoursrows = manager::get_hours_summary_by_student();
} catch (Throwable $e) {
    $hoursrows = [];
}

local_ga_exception_page_start('Gestión HEE - Excepcionalidad', $user);
echo '<div class="tabs"><a class="btn ' . ($view === 'home' ? 'primary' : 'secondary') . '" href="?view=home">Resumen</a><a class="btn ' . ($view === 'typeb' ? 'primary' : 'secondary') . '" href="?view=typeb">Tipo B pendientes</a><a class="btn ' . ($view === 'workshops' ? 'primary' : 'secondary') . '" href="?view=workshops">Talleres</a><a class="btn ' . ($view === 'hours' ? 'primary' : 'secondary') . '" href="?view=hours">Horas y portafolios</a></div>';
if ($message !== '') echo '<div class="alert ok">' . local_ga_exception_e($message) . '</div>';
if ($error !== '') echo '<div class="alert error">' . local_ga_exception_e($error) . '</div>';

if ($view === 'home') {
    $totals = 0.0;
    foreach ($hoursrows as $row) $totals += (float)($row->totalhours ?? 0);
    echo '<div class="grid"><div class="card"><div class="muted">Solicitudes Tipo B pendientes</div><div class="metric">' . (int)$pending . '</div></div><div class="card"><div class="muted">Talleres/ediciones</div><div class="metric">' . count($workshops) . '</div></div><div class="card"><div class="muted">Alumnos con horas</div><div class="metric">' . count($hoursrows) . '</div></div><div class="card"><div class="muted">Horas reconocidas acumuladas</div><div class="metric">' . local_ga_exception_e(format_float($totals, 2, true)) . '</div></div></div>';
    echo '<div class="card"><h2>Alcance de este acceso</h2><p>Este portal permite gestionar información HEE sin iniciar sesión en el Campus Virtual institucional. No concede acceso a otros cursos, participantes generales ni configuración de Moodle.</p></div>';
}

if ($view === 'typeb') {
    $rows = portfolio_typeb::list_all(0, 'pending');
    echo '<div class="card"><h2>Solicitudes externas Tipo B pendientes</h2>';
    if (!$rows) {
        echo '<p>No hay solicitudes pendientes.</p>';
    } else {
        echo '<table><thead><tr><th>Alumno</th><th>Actividad</th><th>Horas</th><th>Fecha</th><th>Justificación</th><th>Revisión</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . local_ga_exception_e(fullname($r)) . '<br><span class="muted small">' . local_ga_exception_e((string)$r->email) . '</span></td><td>' . local_ga_exception_e((string)$r->activityname) . '<br><a href="?action=typebfile&amp;id=' . (int)$r->id . '" target="_blank">Ver certificado</a></td><td class="nowrap">' . local_ga_exception_e(format_float((float)$r->hours, 2, true)) . ' h</td><td>' . (!empty($r->activitydate) ? local_ga_exception_e(userdate((int)$r->activitydate, '%d/%m/%Y')) : '-') . '</td><td>' . local_ga_exception_e((string)($r->activitydescription ?? '')) . '</td><td><form method="post"><input type="hidden" name="action" value="review_typeb"><input type="hidden" name="view" value="typeb"><input type="hidden" name="csrf" value="' . local_ga_exception_e($csrf) . '"><input type="hidden" name="id" value="' . (int)$r->id . '"><textarea name="comment" rows="2" placeholder="Comentario opcional"></textarea><div style="margin-top:6px"><button class="btn success" type="submit" name="decision" value="validate">Validar</button> <button class="btn danger" type="submit" name="decision" value="reject">Rechazar</button></div></form></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

if ($view === 'workshops') {
    echo '<div class="card"><h2>Talleres y ediciones</h2><table><thead><tr><th>Tipo</th><th>Taller</th><th>Edición</th><th>Horas</th><th>Fecha</th><th>Estado</th><th>Inscritos</th></tr></thead><tbody>';
    foreach ($workshops as $r) {
        $type = (($r->workshoptype ?? 'typea') === 'typeb') ? 'Tipo B' : 'Tipo A';
        echo '<tr><td>' . local_ga_exception_e($type) . '</td><td>' . local_ga_exception_e((string)($r->workshopname ?? '')) . '</td><td>' . local_ga_exception_e((string)($r->editioncode ?? $r->name ?? '')) . '</td><td>' . local_ga_exception_e(format_float((float)($r->workshophours ?? 0), 2, true)) . ' h</td><td>' . (!empty($r->sessiondate) ? local_ga_exception_e(userdate((int)$r->sessiondate, '%d/%m/%Y')) : '-') . '</td><td>' . local_ga_exception_e((string)($r->computedstatus ?? $r->status ?? '')) . '</td><td>' . (int)($r->enrolledcount ?? 0) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

if ($view === 'hours') {
    echo '<div class="card"><h2>Horas y portafolios</h2><table><thead><tr><th>Alumno</th><th>Email</th><th>Horas A</th><th>Horas B</th><th>Total</th><th>Portafolio</th></tr></thead><tbody>';
    foreach ($hoursrows as $r) {
        echo '<tr><td>' . local_ga_exception_e(fullname($r)) . '</td><td>' . local_ga_exception_e((string)($r->email ?? '')) . '</td><td>' . local_ga_exception_e(format_float((float)($r->totaltypeahours ?? 0), 2, true)) . '</td><td>' . local_ga_exception_e(format_float((float)($r->totaltypebhours ?? 0), 2, true)) . '</td><td>' . local_ga_exception_e(format_float((float)($r->totalhours ?? 0), 2, true)) . '</td><td><a class="btn secondary" href="?action=downloadportfolio&amp;userid=' . (int)$r->id . '">Descargar PDF</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

local_ga_exception_page_end();
