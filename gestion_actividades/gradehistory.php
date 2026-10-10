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
 * gradehistory.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$activity = manager::get_activity($id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/gradehistory.php', ['id' => $id]));
$PAGE->set_title(get_string('gradehistory', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

$records = method_exists(manager::class, 'get_grade_import_log') ? manager::get_grade_import_log($activity->activitykey, 2000) : [];
if (!$records) {
    $records = manager::get_grade_history($activity->activitykey, 2000);
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('gradehistory', 'local_gestion_actividades') . ': ' . format_string($activity->name));
echo html_writer::div(get_string('gradehistoryinfo', 'local_gestion_actividades'), 'alert alert-info');
echo html_writer::link(new moodle_url('/local/gestion_actividades/view.php', ['id' => $id]), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary mb-3']);

if (!$records) {
    echo $OUTPUT->notification(get_string('nogradehistory', 'local_gestion_actividades'), 'info');
    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('academicyearcol', 'local_gestion_actividades'),
    get_string('fullname', 'local_gestion_actividades'),
    'Email',
    get_string('grade', 'local_gestion_actividades'),
    'Fecha de importación/actualización',
];
$table->data = [];
foreach ($records as $r) {
    $table->data[] = [
        s($r->academicyear),
        s(fullname($r)),
        s($r->email),
        is_null($r->grade) ? '-' : format_float($r->grade, 2),
        userdate($r->timemodified),
    ];
}
echo html_writer::table($table);
if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
