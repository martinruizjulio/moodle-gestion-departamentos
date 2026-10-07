<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\portfolio_pdf;

$userid = optional_param('userid', 0, PARAM_INT);
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$canmanage = \local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id);

if ($userid <= 0) {
    $userid = (int)$USER->id;
}

if ((int)$userid !== (int)$USER->id && !$canmanage) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$pdf = portfolio_pdf::render_pdf_string((int)$userid);
$filename = portfolio_pdf::filename_for_user($user);

\core\session\manager::write_close();

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdf;
exit;
