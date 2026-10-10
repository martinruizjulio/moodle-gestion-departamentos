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
 * task_activity.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$id = required_param('id', PARAM_INT); // edition id
$go = optional_param('go', 0, PARAM_BOOL);
$linkcmid = optional_param('linkcmid', 0, PARAM_INT);
$clearinvalid = optional_param('clearinvalid', 0, PARAM_BOOL);
$typeparam = optional_param('type', '', PARAM_ALPHA);

require_login();

function local_ga_required_cmid_is_valid(int $cmid, int $courseid): bool {
    global $DB;
    if ($cmid <= 0) {
        return false;
    }
    $sql = "SELECT cm.id, cm.course, cm.instance, cm.module, cm.deletioninprogress, m.name AS modname
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
             WHERE cm.id = :cmid";
    $cm = $DB->get_record_sql($sql, ['cmid' => $cmid], IGNORE_MISSING);
    if (!$cm || (int)$cm->course !== (int)$courseid || !empty($cm->deletioninprogress)) {
        return false;
    }
    if (!in_array((string)$cm->modname, ['assign', 'quiz'], true)) {
        return false;
    }
    if (!$DB->get_manager()->table_exists(new xmldb_table($cm->modname))) {
        return false;
    }
    if (!$DB->record_exists($cm->modname, ['id' => (int)$cm->instance])) {
        return false;
    }
    try {
        get_coursemodule_from_id((string)$cm->modname, (int)$cm->id, (int)$courseid, false, MUST_EXIST);
    } catch (Throwable $e) {
        return false;
    }
    return true;
}

function local_ga_clear_required_activity(int $editionid): void {
    global $DB;
    $edition = $DB->get_record('local_ga_workshop_editions', ['id' => $editionid], '*', MUST_EXIST);
    $columns = $DB->get_columns('local_ga_workshop_editions');
    if (isset($columns['requiredcmid'])) {
        $edition->requiredcmid = 0;
    }
    if (isset($columns['requiredmodname'])) {
        $edition->requiredmodname = '';
    }
    $edition->timemodified = time();
    $DB->update_record('local_ga_workshop_editions', $edition);
}

$edition = manager::get_workshop_edition($id);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($course->id);

if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}

$allowedtypes = manager::get_required_activity_types($edition);
if (!$allowedtypes) {
    $allowedtypes = ['assign'];
}
$type = in_array($typeparam, ['assign', 'quiz'], true) ? $typeparam : reset($allowedtypes);
if (!in_array($type, ['assign', 'quiz'], true)) {
    $type = 'assign';
}

// Linking/unlinking the required activity changes the certification basis of
// the edition. Reserve it to global HEE managers or users who can already
// manage activities in the Moodle course, like creation below.
$canchangerequiredactivity = manager::can_manage_globally((int)$USER->id)
    || has_capability('moodle/course:manageactivities', $coursecontext);
if (($clearinvalid || !empty($linkcmid)) && !$canchangerequiredactivity) {
    redirect(
        new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]),
        'Tu permiso Profesor HEE no permite cambiar la actividad obligatoria vinculada a la edición.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

if ($clearinvalid && confirm_sesskey()) {
    local_ga_clear_required_activity((int)$id);
    redirect(new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type]));
}

if (!empty($linkcmid) && confirm_sesskey()) {
    if (!local_ga_required_cmid_is_valid((int)$linkcmid, (int)$course->id)) {
        redirect(new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type]), 'La actividad seleccionada no es válida o no pertenece a este curso.', null, \core\output\notification::NOTIFY_ERROR);
    }
    if (manager::link_required_activity_to_edition((int)$id, (int)$linkcmid)) {
        redirect(new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type]));
    }
}

if ($go && confirm_sesskey()) {
    // Creating/editing a native Moodle activity needs the institutional course capability.
    // Professor HEE alone never grants general course editing rights.
    if (!has_capability('moodle/course:manageactivities', $coursecontext)) {
        redirect(
            new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]),
            'Tu permiso Profesor HEE permite gestionar el taller, pero no crear o reconfigurar actividades Moodle del curso.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    if (!$DB->record_exists('modules', ['name' => $type])) {
        redirect(new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type]), get_string('requiredmodnotavailable', 'local_gestion_actividades') . ': ' . $type, null, \core\output\notification::NOTIFY_ERROR);
    }
    $created = manager::create_required_activity_for_edition((int)$id, (int)$USER->id, $type);
    if (!empty($created->success) && !empty($created->cmid) && local_ga_required_cmid_is_valid((int)$created->cmid, (int)$course->id)) {
        redirect(new moodle_url('/course/modedit.php', ['update' => (int)$created->cmid, 'return' => 1]));
    }
    redirect(new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type]), $created->message ?: 'No se pudo crear la actividad.', null, \core\output\notification::NOTIFY_ERROR);
}

$candidates = array_values(array_filter(manager::find_candidate_required_activities_by_type($edition, $type), function ($candidate) use ($course) {
    return !empty($candidate->cmid) && local_ga_required_cmid_is_valid((int)$candidate->cmid, (int)$course->id);
}));

$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type]));
$PAGE->set_title(get_string('configuretaskquiz', 'local_gestion_actividades'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al taller', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');
echo $OUTPUT->heading(get_string('configuretaskquiz', 'local_gestion_actividades') . ': ' . format_string($workshop->code . ' - ' . $workshop->name));

$typename = $type === 'quiz' ? 'Cuestionario' : 'Tarea';
$currentactivity = manager::get_required_activity_for_edition_by_type((int)$id, $type);
$caneditnative = has_capability('moodle/course:manageactivities', $coursecontext);

echo html_writer::tag('h3', 'Gestionar ' . strtolower(s($typename)));
if (!$caneditnative) {
    echo $OUTPUT->notification('Como Profesor HEE puedes gestionar alumnado, asistencia y materiales de esta edición. La edición estructural de la actividad Moodle requiere permiso institucional de edición del curso.', 'info');
}

if ($currentactivity && !empty($currentactivity->cmid) && local_ga_required_cmid_is_valid((int)$currentactivity->cmid, (int)$course->id)) {
    echo html_writer::link(new moodle_url('/mod/' . $type . '/view.php', ['id' => (int)$currentactivity->cmid]), 'Abrir ' . strtolower(s($typename)), ['class' => 'btn btn-outline-secondary me-2 mb-2']);
    if ($caneditnative) {
        echo html_writer::link(new moodle_url('/course/modedit.php', ['update' => (int)$currentactivity->cmid, 'return' => 1]), 'Editar ' . strtolower(s($typename)), ['class' => 'btn btn-primary me-2 mb-2']);
    }
}

if ($caneditnative) {
    $createurl = new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type, 'go' => 1, 'sesskey' => sesskey()]);
    echo html_writer::link($createurl, 'Gestionar ' . strtolower(s($typename)), ['class' => 'btn btn-secondary me-2 mb-2']);
    if ($currentactivity && !empty($currentactivity->cmid)) {
        echo html_writer::link(new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type, 'clearinvalid' => 1, 'sesskey' => sesskey()]), 'Desvincular y elegir otra', ['class' => 'btn btn-warning mb-2']);
    }
    if ($candidates) {
        echo html_writer::start_tag('div', ['class' => 'card mt-3']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('h3', get_string('linkexistingrequiredactivity', 'local_gestion_actividades'));
        $table = new html_table();
        $table->head = [get_string('name'), get_string('type'), get_string('actions')];
        foreach ($candidates as $candidate) {
            $linkurl = new moodle_url('/local/gestion_actividades/task_activity.php', ['id' => $id, 'type' => $type, 'linkcmid' => $candidate->cmid, 'sesskey' => sesskey()]);
            $table->data[] = [format_string($candidate->activityname), s($candidate->modname), html_writer::link($linkurl, get_string('linkthisactivity', 'local_gestion_actividades'), ['class' => 'btn btn-secondary btn-sm'])];
        }
        echo html_writer::table($table);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
