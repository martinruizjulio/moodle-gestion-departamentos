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
 * edit.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\form\edit_activity_form;
use local_gestion_actividades\local\manager;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$id = optional_param('id', 0, PARAM_INT);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/edit.php', ['id' => $id]));
$PAGE->set_title(get_string($id ? 'editactivity' : 'newactivity', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

$form = new edit_activity_form();
if ($id) {
    $activity = manager::get_activity($id);
    $form->set_data($activity);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/gestion_actividades/index.php'));
} else if ($data = $form->get_data()) {
    $activityid = manager::save_activity($data);
    redirect(new moodle_url('/local/gestion_actividades/view.php', ['id' => $activityid]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($id ? 'editactivity' : 'newactivity', 'local_gestion_actividades'));
$form->display();
echo $OUTPUT->footer();
