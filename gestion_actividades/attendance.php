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
 * attendance.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$activity = manager::get_activity($id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/attendance.php', ['id' => $id]));
$PAGE->set_title(get_string('syncattendance', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('syncattendance', 'local_gestion_actividades') . ': ' . format_string($activity->name));

echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/view.php', ['id' => $id]), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']),
    'mb-3'
);

if (!manager::attendance_tables_available()) {
    echo $OUTPUT->notification(get_string('attendancenotavailable', 'local_gestion_actividades'), 'warning');
    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();
    exit;
}

if (data_submitted() && confirm_sesskey()) {
    $sessionid = required_param('sessionid', PARAM_INT);
    $summary = manager::sync_attendance_session($id, $sessionid);

    $message = get_string(
        'attendancesyncdone',
        'local_gestion_actividades',
        $summary->attended . '/' . $summary->processed
    );
    echo $OUTPUT->notification($message, 'success');

    $table = new html_table();
    $table->data = [
        [get_string('att_processed', 'local_gestion_actividades'), $summary->processed],
        [get_string('att_attended', 'local_gestion_actividades'), $summary->attended],
        [get_string('att_notpresent', 'local_gestion_actividades'), $summary->notpresent],
        [get_string('att_nolog', 'local_gestion_actividades'), $summary->nolog],
        [get_string('att_alreadycompleted', 'local_gestion_actividades'), $summary->alreadycompleted],
    ];
    echo html_writer::table($table);
}

$sessions = manager::get_attendance_sessions((int)$activity->courseid);
if (!$sessions) {
    echo $OUTPUT->notification(get_string('noattendancesessions', 'local_gestion_actividades'), 'info');
    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();
    exit;
}

$options = [];
foreach ($sessions as $session) {
    $date = userdate($session->sessdate, get_string('strftimedatetime', 'langconfig'));
    $options[$session->id] = $session->attendancename . ' - ' . $date . ' - ID ' . $session->id;
}

echo html_writer::tag('p', get_string('syncattendance_help', 'local_gestion_actividades'));

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::label(get_string('attendancesession', 'local_gestion_actividades'), 'sessionid', false, ['class' => 'form-label']);
echo html_writer::select($options, 'sessionid', '', false, ['class' => 'form-control mb-3']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-success', 'value' => get_string('syncattendance', 'local_gestion_actividades')]);
echo html_writer::end_tag('form');

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
