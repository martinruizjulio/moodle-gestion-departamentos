<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$activity = manager::get_activity($id);

$filename = clean_filename('gestion_actividades_' . $activity->id . '_' . date('Ymd_His') . '.csv');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['rank', 'firstname', 'lastname', 'email', 'username', 'idnumber', 'identifier', 'grade', 'status', 'reason'], ';');
$candidates = $DB->get_records('local_ga_candidates', ['activityid' => $id], 'rank ASC, grade DESC, lastname ASC');
foreach ($candidates as $c) {
    fputcsv($out, [$c->rank, $c->firstname, $c->lastname, $c->email, $c->username, $c->idnumber, $c->identifier, $c->grade, $c->status, $c->reason], ';');
}
fclose($out);
exit;
