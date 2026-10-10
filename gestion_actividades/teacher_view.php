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
 * teacher_view.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\attendance_sync;
use local_gestion_actividades\local\typeb_certificate_policy;

$id = required_param('id', PARAM_INT);
$editionid = optional_param('editionid', 0, PARAM_INT);
$focususerid = optional_param('focususerid', 0, PARAM_INT);
$returnq = optional_param('returnq', '', PARAM_TEXT);
$workshop = manager::get_workshop($id);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
require_login($course);

if (function_exists('is_role_switched') && is_role_switched($course->id)) {
    throw new required_capability_exception(context_course::instance($course->id), 'moodle/course:update', 'nopermissions', '');
}

$coursecontext = context_course::instance($course->id);
if (!manager::can_manage_workshop_instance((int)$workshop->id, (int)$USER->id)) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}

$globalmanager = manager::can_manage_globally((int)$USER->id);
$editions = manager::list_workshop_editions($id);
if (!$globalmanager) {
    $editions = array_filter($editions, static function ($candidate) use ($USER): bool {
        return manager::is_teacher_assigned_to_edition((int)$candidate->id, (int)$USER->id);
    });
}

$edition = null;
if ($editionid > 0) {
    $edition = manager::get_workshop_edition($editionid);
    if ((int)$edition->workshopid !== $id) {
        throw new invalid_parameter_exception('La edición seleccionada no pertenece a este taller.');
    }
    if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
        throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
    }
} else if ($globalmanager) {
    $edition = manager::get_primary_workshop_edition($id);
    if (!$edition && $editions) {
        $edition = end($editions);
    }
} else if ($editions) {
    // Never fall back to the workshop's primary edition for Profesor HEE: it may
    // belong to a different teacher. Use only one of the editions already filtered
    // by the explicit assignment relation.
    $edition = reset($editions);
}

if (!$edition && !$globalmanager) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}

$editionid = $edition ? (int)$edition->id : 0;
$istypeb = manager::is_typeb_workshop($workshop);
if ($editionid > 0) {
    // Attendance shown here comes from the workshop attendance list.
    attendance_sync::sync_edition_safely($editionid);
    $edition = manager::get_workshop_edition($editionid);
}

$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/teacher_view.php', array_filter([
    'id' => $id,
    'editionid' => $editionid,
    'focususerid' => $focususerid,
    'returnq' => $returnq,
], static fn($v) => $v !== '' && $v !== 0)));
$PAGE->set_title(get_string('teacherworkshopview', 'local_gestion_actividades'));
$PAGE->set_heading(format_string($course->fullname));

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_valid_activity_cm(int $cmid, int $courseid, array $allowedmods = ['assign', 'quiz']): ?stdClass {
    global $DB;
    if ($cmid <= 0) {
        return null;
    }
    $sql = "SELECT cm.id, cm.course, cm.instance, cm.module, cm.deletioninprogress, m.name AS modname
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
             WHERE cm.id = :cmid";
    $cm = $DB->get_record_sql($sql, ['cmid' => $cmid], IGNORE_MISSING);
    if (!$cm || (int)$cm->course !== $courseid || !empty($cm->deletioninprogress) || !in_array((string)$cm->modname, $allowedmods, true)) {
        return null;
    }
    return $cm;
}

function local_ga_parse_task_grade_input($value): ?float {
    $value = trim(str_replace(',', '.', (string)$value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return min(10.0, max(0.0, (float)$value));
}

// Type B closed edition: the teacher allows (or withdraws) a late reflection
// for one student. Page access already required can_manage_edition().
$latereflectionaction = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($istypeb && $edition && in_array($latereflectionaction, ['allow_reflection', 'revoke_reflection'], true)) {
    require_sesskey();
    $targetuserid = required_param('userid', PARAM_INT);
    $targetenrolment = manager::get_edition_enrolment((int)$edition->id, $targetuserid);
    if (!manager::is_active_enrolment($targetenrolment)) {
        throw new invalid_parameter_exception('El alumno no pertenece actualmente a esta edición.');
    }
    $until = $latereflectionaction === 'allow_reflection'
        ? time() + typeb_certificate_policy::LATE_REFLECTION_DAYS * DAYSECS
        : 0;
    $ok = typeb_certificate_policy::set_late_reflection_permission((int)$edition->id, $targetuserid, $until);
    redirect(
        new moodle_url('/local/gestion_actividades/teacher_view.php', array_filter([
            'id' => $id, 'editionid' => $editionid, 'focususerid' => $focususerid, 'returnq' => $returnq,
        ], static fn($v) => $v !== '' && $v !== 0)),
        !$ok ? 'No se pudo actualizar el permiso de reflexión.'
            : ($until > 0 ? 'El alumno puede entregar la reflexión hasta el ' . userdate($until, get_string('strftimedatetimeshort', 'langconfig')) . '.'
                : 'Permiso de reflexión retirado.'),
        null,
        $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

if (!$istypeb && $edition && optional_param('action', '', PARAM_ALPHANUMEXT) === 'save_task_grades') {
    require_sesskey();
    $grades = optional_param_array('taskgrade', [], PARAM_RAW);
    $saved = 0;
    foreach ($grades as $userid => $gradevalue) {
        $grade = local_ga_parse_task_grade_input($gradevalue);
        if (manager::save_internal_task_grade((int)$edition->id, (int)$userid, $grade, (int)$USER->id, false)) {
            $saved++;
        }
    }
    if ($saved > 0) {
        \local_gestion_actividades\local\grade_manager::sync_course_safely((int)$course->id);
    }
    redirect(new moodle_url('/local/gestion_actividades/teacher_view.php', array_filter([
        'id' => $id, 'editionid' => $editionid, 'focususerid' => $focususerid, 'returnq' => $returnq,
    ], static fn($v) => $v !== '' && $v !== 0)), 'Notas de tarea guardadas: ' . $saved, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
$toplinks = html_writer::link(
    new moodle_url('/course/view.php', ['id' => $course->id]),
    local_ga_btn_icon('t/left', 'Volver al curso'),
    ['class' => 'btn local-ga-back-course me-2 mb-3']
);
if ($focususerid > 0) {
    $focususer = $DB->get_record('user', ['id' => $focususerid, 'deleted' => 0], 'id,firstname,lastname', IGNORE_MISSING);
    if ($focususer) {
        $toplinks .= html_writer::link(
            new moodle_url('/local/gestion_actividades/student_search.php', ['userid' => $focususerid, 'q' => $returnq]),
            local_ga_btn_icon('t/left', 'Volver al historial de ' . fullname($focususer)),
            ['class' => 'btn btn-primary mb-3']
        );
    }
}
echo html_writer::div($toplinks, 'mb-2');
echo $OUTPUT->heading(get_string('teacherworkshopview', 'local_gestion_actividades') . ': ' . format_string($workshop->code . ' - ' . $workshop->name));

if ($editions) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', 'Edición que se está gestionando', ['class' => 'h5']);
    echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'd-flex flex-wrap align-items-center gap-2']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    if ($focususerid > 0) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'focususerid', 'value' => $focususerid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'returnq', 'value' => $returnq]);
    }
    echo html_writer::label('Edición', 'editionid', false, ['class' => 'me-2']);
    echo html_writer::start_tag('select', ['name' => 'editionid', 'id' => 'editionid', 'class' => 'form-select me-2 mb-2']);
    foreach ($editions as $availableedition) {
        $label = trim((string)($availableedition->editioncode ?? ''));
        if (!empty($availableedition->name)) {
            $label .= ($label !== '' ? ' · ' : '') . format_string($availableedition->name);
        }
        if (!empty($availableedition->sessiondate)) {
            $label .= ' · ' . manager::format_date_compact((int)$availableedition->sessiondate);
        }
        if (!empty($availableedition->archived) || (string)($availableedition->status ?? '') === 'archived') {
            $label .= ' · ARCHIVADA';
        }
        echo html_writer::tag('option', $label !== '' ? $label : ('Edición ' . (int)$availableedition->id), ['value' => (int)$availableedition->id, 'selected' => (int)$availableedition->id === $editionid ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Mostrar edición', 'class' => 'btn btn-secondary mb-2']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($edition && !empty($edition->completed)) {
    echo $OUTPUT->notification(get_string('workshopalreadycompleted', 'local_gestion_actividades'), 'success');
}

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('materialsfiles', 'local_gestion_actividades'));
echo html_writer::link(new moodle_url('/local/gestion_actividades/material_edit.php', ['workshopid' => $id, 'editionid' => $edition ? $edition->id : 0]), local_ga_btn_icon('t/add', get_string('addmaterial', 'local_gestion_actividades')), ['class' => 'btn btn-primary mb-2']);
$materials = manager::list_materials($id, $edition ? (int)$edition->id : 0, false);
if ($materials) {
    $table = new html_table();
    $table->head = [get_string('name'), get_string('description'), get_string('visible'), get_string('actions')];
    foreach ($materials as $m) {
        $editurl = new moodle_url('/local/gestion_actividades/material_edit.php', ['id' => $m->id, 'workshopid' => $id, 'editionid' => $edition ? $edition->id : 0]);
        $fileurl = manager::get_material_file_url($m, $coursecontext);
        $link = !empty($fileurl) ? html_writer::link($fileurl, s($m->name), ['target' => '_blank']) : (!empty($m->url) ? html_writer::link($m->url, s($m->name), ['target' => '_blank']) : s($m->name));
        $table->data[] = [$link, s($m->description), !empty($m->visible) ? get_string('yes') : get_string('no'), html_writer::link($editurl, local_ga_btn_icon('t/edit', get_string('edit')), ['class' => 'btn btn-secondary btn-sm'])];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nomaterialsyet', 'local_gestion_actividades'), 'info');
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
if ($istypeb) {
    echo html_writer::tag('h3', 'Tarea de reflexión del Taller Tipo B');
    echo html_writer::tag('p', 'La actividad es una Tarea Moodle sin nota numérica. El alumno escribe su valoración del taller como texto en línea (sin archivos adjuntos). Con asistencia confirmada y reflexión entregada queda Apto.', ['class' => 'text-muted']);
    $reflectioncm = $edition ? local_ga_valid_activity_cm((int)($edition->requiredcmid ?? 0), (int)$course->id, ['assign']) : null;
    if ($reflectioncm) {
        echo html_writer::link(new moodle_url('/mod/assign/view.php', ['id' => (int)$reflectioncm->id]), local_ga_btn_icon('t/edit', 'Abrir tarea de reflexión en Moodle'), ['class' => 'btn btn-primary']);
    } else {
        echo $OUTPUT->notification('La tarea de reflexión todavía no está creada o vinculada a esta edición.', 'warning');
    }
} else if ($edition && in_array('assign', manager::get_required_activity_types($edition), true)) {
    echo html_writer::tag('h3', 'Tarea del taller');
    echo html_writer::link(new moodle_url('/local/gestion_actividades/internal_task.php', ['id' => $edition->id]), local_ga_btn_icon('t/edit', 'Gestionar tarea'), ['class' => 'btn btn-primary']);
} else if ($edition && in_array('quiz', manager::get_required_activity_types($edition), true)) {
    echo html_writer::tag('h3', 'Cuestionario del taller');
    $quizcmid = !empty($edition->requiredquizcmid) ? (int)$edition->requiredquizcmid : (int)($edition->requiredcmid ?? 0);
    $quizcm = local_ga_valid_activity_cm($quizcmid, (int)$course->id, ['quiz']);
    if ($quizcm) {
        echo html_writer::link(new moodle_url('/mod/quiz/view.php', ['id' => (int)$quizcm->id]), local_ga_btn_icon('t/preview', 'Abrir cuestionario en Moodle'), ['class' => 'btn btn-primary']);
    } else {
        echo $OUTPUT->notification('El cuestionario todavía no está creado o vinculado a esta edición.', 'warning');
    }
} else {
    echo html_writer::tag('h3', 'Actividad obligatoria');
    echo html_writer::tag('p', 'Este taller no tiene tarea interna configurada desde esta pantalla.', ['class' => 'text-muted']);
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', $istypeb ? 'Asistencia y reflexión' : 'Asistencia y entrega de tarea');
if ($edition) {
    echo html_writer::start_div('mb-3');
    echo html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', array_filter([
        'id' => $edition->id, 'mode' => 'manual', 'focususerid' => $focususerid, 'returnq' => $returnq,
    ], static fn($v) => $v !== '' && $v !== 0)), local_ga_btn_icon('t/add', 'Matriculación manual'), ['class' => 'btn btn-primary me-2 mb-2']);
    echo html_writer::link(new moodle_url('/local/gestion_actividades/edition_students.php', array_filter([
        'id' => $edition->id, 'focususerid' => $focususerid, 'returnq' => $returnq,
    ], static fn($v) => $v !== '' && $v !== 0)), local_ga_btn_icon('i/users', 'Alumnos / asistencia'), ['class' => 'btn btn-secondary mb-2']);
    echo html_writer::end_div();
    if (!empty($edition->attendancecmid)) {
        $attcm = local_ga_valid_activity_cm((int)$edition->attendancecmid, (int)$course->id, ['attendance']);
        if ($attcm) {
            echo html_writer::link(new moodle_url('/mod/attendance/view.php', ['id' => (int)$attcm->id]), local_ga_btn_icon('i/checked', get_string('openattendance', 'local_gestion_actividades')), ['class' => 'btn btn-secondary mb-3']);
        }
    }

    $enrolledusers = manager::list_edition_enrolled_users_ultrasafe((int)$edition->id);
    if ($enrolledusers && $focususerid > 0) {
        $enrolledusers = array_values(array_filter($enrolledusers, static fn($eu) => (int)($eu->userid ?? 0) === $focususerid));
        if (!$enrolledusers) {
            echo $OUTPUT->notification('El alumno seleccionado no consta actualmente en esta edición.', 'warning');
        } else {
            echo html_writer::tag('p', 'Vista filtrada al alumno seleccionado desde «Buscar alumno».', ['class' => 'alert alert-info py-2']);
        }
    }
    if ($enrolledusers) {
        $atable = new html_table();
        if ($istypeb) {
            $reflectionclosed = typeb_certificate_policy::reflection_submissions_closed((int)$edition->id);
            $atable->head = [get_string('lastname'), get_string('firstname'), get_string('email'), 'Asistencia', 'Reflexión', 'Resultado', 'Certificado'];
            if ($reflectionclosed) {
                $atable->head[] = 'Reflexión fuera de plazo';
            }
            foreach ($enrolledusers as $eu) {
                $userid = (int)$eu->userid;
                $attended = manager::is_user_attended_edition((int)$edition->id, $userid);
                $submitted = typeb_certificate_policy::has_reflection((int)$edition->id, $userid);
                $permissioncell = '';
                if ($reflectionclosed) {
                    if ($submitted) {
                        $permissioncell = '-';
                    } else {
                        $until = typeb_certificate_policy::late_reflection_until((int)$edition->id, $userid);
                        $active = $until > time();
                        $permissioncell = ($active ? html_writer::div('Permitido hasta ' . userdate($until, get_string('strftimedatetimeshort', 'langconfig')), 'small mb-1') : '')
                            . html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $id, 'editionid' => $editionid]), 'class' => 'd-inline'])
                            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid])
                            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $active ? 'revoke_reflection' : 'allow_reflection'])
                            . html_writer::tag(
                                'button',
                                $active ? 'Retirar permiso' : 'Permitir entregar (' . typeb_certificate_policy::LATE_REFLECTION_DAYS . ' días)',
                                ['type' => 'submit', 'class' => 'btn btn-sm ' . ($active ? 'btn-outline-secondary' : 'btn-primary')]
                            )
                            . html_writer::end_tag('form');
                    }
                }
                $eligible = typeb_certificate_policy::is_eligible((int)$edition->id, $userid);
                $certificate = manager::get_user_certificate_for_edition((int)$edition->id, $userid);
                $atable->data[] = [
                    s($eu->lastname),
                    s($eu->firstname),
                    s($eu->email),
                    $attended ? html_writer::span('Presente', 'badge bg-success') : html_writer::span('Pendiente', 'badge bg-warning text-dark'),
                    $submitted ? html_writer::span('Entregada', 'badge bg-success') : html_writer::span('Pendiente', 'badge bg-warning text-dark'),
                    $eligible ? html_writer::span('Apto', 'badge bg-success') : html_writer::span('Pendiente', 'badge bg-warning text-dark'),
                    $certificate ? html_writer::span('Generado', 'badge bg-success') : html_writer::span('Pendiente', 'badge bg-secondary'),
                ];
                if ($reflectionclosed) {
                    $atable->data[count($atable->data) - 1][] = $permissioncell;
                }
            }
            echo html_writer::table($atable);
            echo html_writer::tag('p', 'Criterio Tipo B: asistencia + entrega de la reflexión = Apto. No existe nota numérica.', ['class' => 'text-muted']);
            if ($reflectionclosed) {
                echo html_writer::tag('p', 'La edición está cerrada: un alumno solo puede entregar la reflexión si le das permiso. Cuando la entregue, vuelve a «Generar certificados» para emitir el suyo.', ['class' => 'text-muted']);
            }
        } else {
            $requiredtypes = manager::get_required_activity_types($edition);
            $hasquiz = in_array('quiz', $requiredtypes, true);
            if ($hasquiz) {
                $quizrequirement = manager::get_quiz_requirement($edition);
                $quizcmid = (int)$quizrequirement->cmid;
                $atable->head = [get_string('lastname'), get_string('firstname'), get_string('email'), 'Asistencia', 'Cuestionario', 'Nota', 'Resultado', 'Certificado'];
                foreach ($enrolledusers as $eu) {
                    $userid = (int)$eu->userid;
                    // Same sources as manager::user_is_certificate_eligible():
                    // attendance + quiz finished + at least 5/10.
                    $attended = manager::is_user_attended_edition((int)$edition->id, $userid);
                    $submitted = $quizcmid > 0 && manager::user_submitted_required_activity($userid, $quizcmid);
                    $grade = $quizcmid > 0 ? manager::get_user_quiz_grade_out_of_10($userid, $quizcmid) : null;
                    $passed = $submitted && $grade !== null && $grade >= (float)$quizrequirement->minimum;
                    $certificate = manager::get_user_certificate_for_edition((int)$edition->id, $userid);

                    $reasons = [];
                    if (!$attended) {
                        $reasons[] = 'Falta asistencia';
                    }
                    if (!$submitted) {
                        $reasons[] = 'Cuestionario no finalizado';
                    } else if ($grade === null) {
                        $reasons[] = 'Cuestionario pendiente de calificar';
                    } else if (!$passed) {
                        $reasons[] = 'No apto: nota inferior a 5/10';
                    }
                    if (!$reasons) {
                        $resultbadge = html_writer::span('Apto', 'badge bg-success');
                    } else if ($submitted && $grade !== null && !$passed) {
                        $resultbadge = html_writer::span(implode(' · ', $reasons), 'badge bg-danger');
                    } else {
                        $resultbadge = html_writer::span(implode(' · ', $reasons), 'badge bg-warning text-dark');
                    }

                    $atable->data[] = [
                        s($eu->lastname), s($eu->firstname), s($eu->email),
                        $attended ? html_writer::span('Asiste', 'badge bg-success') : html_writer::span('No asiste', 'badge bg-warning text-dark'),
                        $submitted ? html_writer::span('Finalizado', 'badge bg-success') : html_writer::span('Pendiente', 'badge bg-warning text-dark'),
                        $grade === null ? '-' : format_float($grade, 2, true) . ' / 10',
                        $resultbadge,
                        $certificate ? html_writer::span('Generado', 'badge bg-success') : html_writer::span('Pendiente', 'badge bg-secondary'),
                    ];
                }
                echo html_writer::table($atable);
                echo html_writer::tag('p', 'Criterio: asistencia + cuestionario finalizado con nota mínima de 5 sobre 10 (la nota del cuestionario se reescala a 10).', ['class' => 'text-muted']);
            } else {
                $hasinternaltask = in_array('assign', $requiredtypes, true);
                if ($hasinternaltask) {
                    echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $id, 'editionid' => $editionid])]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_task_grades']);
                }
                $atable->head = [get_string('lastname'), get_string('firstname'), get_string('email'), 'Asistencia', 'Tarea entregada', 'Archivo tarea', 'Nota tarea', 'Resultado tarea'];
                foreach ($enrolledusers as $eu) {
                    $submission = manager::get_internal_task_submission((int)$edition->id, (int)$eu->userid);
                    $submissionurl = ($submission && !empty($submission->fileitemid)) ? manager::get_filearea_url($coursecontext, 'tasksubmission', (int)$submission->fileitemid) : '';
                    $grade = ($submission && property_exists($submission, 'grade') && $submission->grade !== null && $submission->grade !== '') ? (float)$submission->grade : null;
                    $gradeinput = ($hasinternaltask && $submissionurl !== '') ? html_writer::empty_tag('input', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '10', 'name' => 'taskgrade[' . (int)$eu->userid . ']', 'value' => $grade !== null ? rtrim(rtrim(number_format($grade, 2, '.', ''), '0'), '.') : '', 'class' => 'form-control form-control-sm', 'style' => 'max-width:95px;']) : '-';
                    $resultbadge = !$hasinternaltask ? html_writer::span('No procede', 'badge bg-secondary') : ($submissionurl === '' ? html_writer::span('Pendiente entrega', 'badge bg-warning text-dark') : ($grade === null ? html_writer::span('Pendiente nota', 'badge bg-warning text-dark') : ($grade >= 5.0 ? html_writer::span('Apto', 'badge bg-success') : html_writer::span('No apto', 'badge bg-danger'))));
                    $atable->data[] = [s($eu->lastname), s($eu->firstname), s($eu->email), !empty($eu->attended) ? html_writer::span('Asiste', 'badge bg-success') : html_writer::span('No asiste', 'badge bg-warning text-dark'), $submissionurl !== '' ? html_writer::span('Entregada', 'badge bg-success') : html_writer::span('No entregada', 'badge bg-warning text-dark'), $submissionurl !== '' ? html_writer::link($submissionurl, 'Ver/descargar', ['class' => 'btn btn-secondary btn-sm', 'target' => '_blank']) : '-', $gradeinput, $resultbadge];
                }
                echo html_writer::table($atable);
                if ($hasinternaltask) {
                    echo html_writer::tag('p', 'La nota mínima para poder generar certificado es 5 sobre 10.', ['class' => 'text-muted']);
                    echo html_writer::tag('button', local_ga_btn_icon('t/save', 'Guardar notas de tarea'), ['type' => 'submit', 'class' => 'btn btn-primary']);
                    echo html_writer::end_tag('form');
                }
            }
        }
    } else {
        echo $OUTPUT->notification(get_string('noenrolledstudentsyet', 'local_gestion_actividades'), 'info');
    }
} else {
    echo $OUTPUT->notification(get_string('noeditionavailable', 'local_gestion_actividades'), 'warning');
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('certificates', 'local_gestion_actividades'));
if ($edition) {
    echo html_writer::link(new moodle_url('/local/gestion_actividades/generate_certificates.php', ['id' => $edition->id, 'sesskey' => sesskey()]), local_ga_btn_icon('i/report', get_string('generatecertificates', 'local_gestion_actividades')), ['class' => 'btn btn-primary']);
    echo ' ';
    echo html_writer::link(new moodle_url('/local/gestion_actividades/certificates.php', ['editionid' => $edition->id]), local_ga_btn_icon('t/preview', get_string('viewgeneratedcertificates', 'local_gestion_actividades')), ['class' => 'btn btn-secondary']);
    echo html_writer::tag('p', $istypeb ? 'Solo se generan certificados para alumnos con asistencia confirmada y reflexión entregada.' : get_string('certificates_help', 'local_gestion_actividades'), ['class' => 'text-muted mt-2']);
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('finishworkshop', 'local_gestion_actividades'));
if ($edition) {
    if (!empty($edition->archived) || (string)($edition->status ?? '') === 'archived') {
        echo html_writer::tag('p', 'Esta edición está archivada.', ['class' => 'alert alert-info']);
    } else {
        $certcount = manager::count_edition_certificates((int)$edition->id);
        $enrolledcount = count(manager::list_edition_enrolled_users_ultrasafe((int)$edition->id));
        if ($certcount > 0 || $enrolledcount === 0) {
            echo html_writer::link(new moodle_url('/local/gestion_actividades/finish_workshop.php', ['id' => $edition->id, 'sesskey' => sesskey()]), get_string('finishandarchiveworkshop', 'local_gestion_actividades'), ['class' => 'btn btn-danger']);
        } else {
            echo html_writer::tag('p', 'Antes de terminar y archivar debes generar los certificados de los alumnos que cumplan los requisitos.', ['class' => 'alert alert-warning']);
        }
    }
}
echo html_writer::tag('p', get_string('finishworkshop_help', 'local_gestion_actividades'));
echo html_writer::end_div();
echo html_writer::end_div();

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
