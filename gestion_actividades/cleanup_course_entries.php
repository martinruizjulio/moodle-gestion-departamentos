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
 * cleanup_course_entries.php page/script for local_gestion_actividades.
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

$courseid = optional_param('courseid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/cleanup_course_entries.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('cleanupcourseentries', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

if ($confirm && confirm_sesskey()) {
    $summary = manager::cleanup_all_generated_course_entries($courseid);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('cleanupcourseentries', 'local_gestion_actividades'));
    echo $OUTPUT->notification(get_string('cleanupcourseentriesdone', 'local_gestion_actividades', $summary), 'success');
    if (!empty($summary->messages)) {
        echo html_writer::start_tag('ul');
        foreach ($summary->messages as $m) {
            echo html_writer::tag('li', s($m));
        }
        echo html_writer::end_tag('ul');
    }
    echo html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php'), get_string('workshops', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cleanupcourseentries', 'local_gestion_actividades'));
echo $OUTPUT->notification(get_string('cleanupcourseentrieswarn', 'local_gestion_actividades'), 'warning');

$url = new moodle_url('/local/gestion_actividades/cleanup_course_entries.php', [
    'courseid' => $courseid,
    'confirm' => 1,
    'sesskey' => sesskey(),
]);
echo html_writer::link($url, get_string('confirmcleanupcourseentries', 'local_gestion_actividades'), ['class' => 'btn btn-danger']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php'), get_string('cancel'), ['class' => 'btn btn-secondary']);
echo $OUTPUT->footer();
