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
 * edition_group.php page/script for local_gestion_actividades.
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
$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop($edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/edition_group.php', ['id' => $id]));
$PAGE->set_title(get_string('creategroupforedition', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('creategroupforedition', 'local_gestion_actividades') . ': ' . format_string($edition->name));

echo html_writer::tag('p', get_string('coursewherecreated', 'local_gestion_actividades') . ': ' .
    html_writer::tag('strong', format_string($course->fullname) . ' [' . s($course->shortname) . '] — ID ' . $course->id));

if (data_submitted() && confirm_sesskey()) {
    $groupid = manager::create_group_for_edition($id);
    $group = $DB->get_record('groups', ['id' => $groupid], '*', MUST_EXIST);
    echo $OUTPUT->notification(get_string('groupcreatedlinked', 'local_gestion_actividades', format_string($group->name)), 'success');

    echo html_writer::div(
        html_writer::link(new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $id, 'workshopid' => $workshop->id]), get_string('editedition', 'local_gestion_actividades'), ['class' => 'btn btn-primary']) . ' ' .
        html_writer::link(new moodle_url('/group/index.php', ['id' => $course->id]), get_string('opencoursegroups', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']) . ' ' .
        html_writer::link(new moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => $workshop->id]), get_string('return', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']),
        'mb-3'
    );

    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('creategroupforedition_help', 'local_gestion_actividades'), ['class' => 'alert alert-info']);

if (!empty($edition->groupid) && $DB->record_exists('groups', ['id' => $edition->groupid])) {
    $group = $DB->get_record('groups', ['id' => $edition->groupid]);
    echo $OUTPUT->notification(get_string('editionalreadyhasgroup', 'local_gestion_actividades', format_string($group->name)), 'warning');
}

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('creategroupforedition', 'local_gestion_actividades')]);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => $workshop->id]), get_string('cancel'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
