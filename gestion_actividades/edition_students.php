<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\attendance_sync;

require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$syscontext = context_system::instance();
$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);
$studentq = optional_param('studentq', '', PARAM_TEXT);
$manualadd = optional_param('manualadd', 0, PARAM_INT);
$markattendance = optional_param('markattendance', 0, PARAM_INT);
$attended = optional_param('attended', 0, PARAM_BOOL);
$unenrol = optional_param('unenrol', 0, PARAM_INT);

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall me-1']) . ' ' . $label;
}

function local_ga_is_course_student(int $courseid, int $userid): bool {
    global $DB;
    if ($courseid <= 0 || $userid <= 0) {
        return false;
    }
    $context = context_course::instance($courseid, IGNORE_MISSING);
    if (!$context || !is_enrolled($context, $userid, '', true)) {
        return false;
    }
    $sql = "SELECT 1
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :userid
               AND ra.contextid = :contextid
               AND (r.shortname = 'student' OR r.archetype = 'student')";
    return $DB->record_exists_sql($sql, ['userid' => $userid, 'contextid' => $context->id]);
}

try {
    $edition = manager::get_workshop_edition($id);
    $workshop = manager::get_workshop((int)$edition->workshopid);
    $course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
    $coursecontext = context_course::instance($course->id);

    if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
        throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
    }

    if (!empty($manualadd) && confirm_sesskey()) {
        if (!local_ga_is_course_student((int)$course->id, (int)$manualadd)) {
            throw new invalid_parameter_exception('El usuario seleccionado no es un estudiante matriculado en este curso.');
        }
        $enrolresult = manager::enrol_user_in_edition((int)$id, (int)$manualadd, 'manual');
        redirect(
            new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id]),
            $enrolresult->message,
            null,
            $enrolresult->success ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }

    if (!empty($unenrol) && data_submitted() && confirm_sesskey()) {
        $target = $DB->get_record('local_ga_edition_enrolments', ['id' => $unenrol], 'id,editionid,userid', MUST_EXIST);
        if ((int)$target->editionid !== (int)$id) {
            throw new invalid_parameter_exception('La inscripción indicada no pertenece a esta edición.');
        }
        $unenrolresult = manager::unenrol_user_from_edition((int)$id, (int)$target->userid);
        redirect(
            new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id, 't' => time()]),
            $unenrolresult->message,
            null,
            $unenrolresult->success ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }

    if (!empty($markattendance) && confirm_sesskey()) {
        $enrolment = $DB->get_record('local_ga_edition_enrolments', ['id' => (int)$markattendance], 'id,editionid,userid', MUST_EXIST);
        if ((int)$enrolment->editionid !== (int)$id) {
            throw new invalid_parameter_exception('La inscripción indicada no pertenece a esta edición.');
        }
        // The workshop attendance list is the source of truth: a student who
        // appears in it must be corrected there, not here.
        if (array_key_exists((int)$enrolment->userid, attendance_sync::list_map((int)$id))) {
            redirect(
                new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id, 't' => time()]),
                'Este alumno figura en la lista de asistencia del taller: corrige su estado allí (solo cuenta «Presente»).',
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        manager::set_enrolment_attendance((int)$markattendance, (bool)$attended, (int)$USER->id);
        redirect(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id, 't' => time()]), get_string('attendancesaved', 'local_gestion_actividades'));
    }

    // Bring HEE attendance in line with the workshop attendance list.
    attendance_sync::sync_edition_safely((int)$id);
    $listmap = [];
    try {
        $listmap = attendance_sync::list_map((int)$id);
    } catch (\Throwable $e) {
        $listmap = [];
    }

    $PAGE->set_context($coursecontext);
    $PAGE->set_course($course);
    $PAGE->set_url(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id]));
    $PAGE->set_title(get_string('enrolledstudentsattendance', 'local_gestion_actividades'));
    $PAGE->set_heading(format_string($course->fullname));

    echo $OUTPUT->header();
    echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al taller', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');

    echo $OUTPUT->heading(get_string('enrolledstudentsattendance', 'local_gestion_actividades') . ': ' . format_string($workshop->code . ' - ' . $workshop->name));

    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', get_string('clickattendance', 'local_gestion_actividades'));
    if (!empty($edition->attendancecmid)) {
        echo html_writer::tag('p',
            'La asistencia se pasa en la lista de asistencia del taller y se aplica aquí automáticamente: solo cuenta «Presente». '
            . 'El marcado manual de esta pantalla solo sirve para alumnos que no figuran en esa lista.',
            ['class' => 'alert alert-info']
        );
    }

    $students = [];
    try {
        $students = manager::list_edition_enrolled_users_ultrasafe((int)$id);
    } catch (\Throwable $e) {
        echo $OUTPUT->notification(get_string('attendance_read_error', 'local_gestion_actividades') . ': ' . s($e->getMessage()), 'warning');
    }

    if ($students) {
        $table = new html_table();
        $table->head = [get_string('lastname'), get_string('firstname'), get_string('email'), get_string('attendance', 'local_gestion_actividades'), get_string('actions')];
        foreach ($students as $s) {
            $isattended = !empty($s->attended);
            $toggleurl = new moodle_url('/local/gestion_actividades/edition_students.php', [
                'id' => $id,
                'markattendance' => $s->enrolmentid,
                'attended' => $isattended ? 0 : 1,
                'sesskey' => sesskey(),
            ]);
            $status = $isattended
                ? html_writer::span(get_string('attended', 'local_gestion_actividades'), 'badge bg-success')
                : html_writer::span(get_string('notattended', 'local_gestion_actividades'), 'badge bg-secondary');
            if (array_key_exists((int)($s->userid ?? 0), $listmap)) {
                $button = html_writer::span('Según lista de asistencia', 'badge bg-info text-dark me-1');
            } else {
                $button = html_writer::link($toggleurl, $isattended ? get_string('marknotattended', 'local_gestion_actividades') : get_string('markattended', 'local_gestion_actividades'), ['class' => $isattended ? 'btn btn-warning btn-sm me-1' : 'btn btn-success btn-sm me-1']);
            }
            $button .= html_writer::start_tag('form', [
                'method' => 'post',
                'action' => new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id]),
                'class' => 'd-inline',
                'onsubmit' => 'return window.confirm(' . json_encode('¿Dar de baja a ' . trim($s->firstname . ' ' . $s->lastname) . ' de esta edición?') . ');',
            ]);
            $button .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $button .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'unenrol', 'value' => (int)$s->enrolmentid]);
            $button .= html_writer::tag('button', 'Dar de baja', ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm']);
            $button .= html_writer::end_tag('form');
            $table->data[] = [s($s->lastname), s($s->firstname), s($s->email), $status, $button];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('noenrolledstudentsyet', 'local_gestion_actividades'), 'info');
    }

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', get_string('addmanualstudent', 'local_gestion_actividades'));
    echo html_writer::tag('p', get_string('manualstudent_rolefilter_help', 'local_gestion_actividades'), ['class' => 'text-muted']);

    echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    echo html_writer::label(get_string('studentautocomplete', 'local_gestion_actividades'), 'studentq');
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'studentq', 'id' => 'studentq', 'value' => s($studentq), 'class' => 'form-control', 'style' => 'max-width:520px', 'list' => 'student-suggestions', 'autocomplete' => 'on']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'), 'class' => 'btn btn-primary mt-2']);
    echo html_writer::end_tag('form');

    try {
        $suggestions = manager::search_course_students((int)$course->id, '');
        echo html_writer::start_tag('datalist', ['id' => 'student-suggestions']);
        foreach ($suggestions as $su) {
            echo html_writer::tag('option', '', ['value' => fullname($su) . ' <' . $su->email . '>']);
        }
        echo html_writer::end_tag('datalist');
    } catch (\Throwable $e) {
        // Autocomplete suggestions are optional.
    }

    if (trim($studentq) !== '') {
        try {
            $results = manager::search_course_students((int)$course->id, $studentq);
            if ($results) {
                $stable = new html_table();
                $stable->head = [get_string('user'), get_string('email'), get_string('actions')];
                foreach ($results as $su) {
                    $addurl = new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id, 'manualadd' => $su->id, 'sesskey' => sesskey()]);
                    $stable->data[] = [fullname($su), s($su->email), html_writer::link($addurl, local_ga_btn_icon('t/add', get_string('addstudenttoworkshop', 'local_gestion_actividades')), ['class' => 'btn btn-secondary btn-sm'])];
                }
                echo html_writer::table($stable);
            } else {
                echo $OUTPUT->notification(get_string('nostudentsfoundcourse', 'local_gestion_actividades'), 'info');
            }
        } catch (\Throwable $e) {
            echo $OUTPUT->notification(get_string('student_search_error', 'local_gestion_actividades') . ': ' . s($e->getMessage()), 'warning');
        }
    }

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();

} catch (\Throwable $e) {
    $PAGE->set_context($syscontext);
    $PAGE->set_url(new moodle_url('/local/gestion_actividades/edition_students.php', ['id' => $id]));
    $PAGE->set_title(get_string('enrolledstudentsattendance', 'local_gestion_actividades'));
    $PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('enrolledstudentsattendance', 'local_gestion_actividades'));
    echo $OUTPUT->notification(get_string('attendance_page_error', 'local_gestion_actividades') . ': ' . s($e->getMessage()), 'error');
    echo html_writer::link(new moodle_url('/local/gestion_actividades/my_workshops.php'), local_ga_btn_icon('i/course', 'Mis talleres HEE'), ['class' => 'btn btn-secondary']);
    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();
}
