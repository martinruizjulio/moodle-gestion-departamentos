<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * export.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
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
