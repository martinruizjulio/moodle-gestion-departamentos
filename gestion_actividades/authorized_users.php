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
 * authorized_users.php page/script for local_gestion_actividades.
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
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$q = optional_param('q', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$editionid = optional_param('editionid', 0, PARAM_INT);
$teacherid = optional_param('teacherid', 0, PARAM_INT);

$courses = $DB->get_records('course', null, 'fullname ASC', 'id, fullname, shortname', 0, 200);
if ($courseid <= 0) {
    $fw = $DB->get_record_sql("SELECT courseid FROM {local_ga_workshops} ORDER BY id DESC", [], IGNORE_MULTIPLE);
    $courseid = $fw ? (int)$fw->courseid : 0;
}

if ($action === 'add' && confirm_sesskey() && $userid > 0) {
    manager::add_authorized_user($userid, $USER->id);
    redirect(new moodle_url('/local/gestion_actividades/authorized_users.php', ['courseid' => $courseid]), get_string('authorizeduseradded', 'local_gestion_actividades'));
}
if ($action === 'remove' && confirm_sesskey() && $userid > 0) {
    manager::remove_authorized_user($userid);
    redirect(new moodle_url('/local/gestion_actividades/authorized_users.php', ['courseid' => $courseid]), get_string('authorizeduserremoved', 'local_gestion_actividades'));
}

function local_ga_auth_label(stdClass $user): string {
    return fullname($user) . ' <' . $user->email . '>';
}

function local_ga_auth_extract_email(string $text): string {
    if (preg_match('/<([^>]+)>/', $text, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches)) {
        return trim($matches[0]);
    }
    return '';
}

function local_ga_hee_edition_in_course(int $editionid, int $courseid): ?stdClass {
    global $DB;
    if ($editionid <= 0 || $courseid <= 0) {
        return null;
    }
    return $DB->get_record_sql(
        "SELECT e.id, e.workshopid, w.courseid
           FROM {local_ga_workshop_editions} e
           JOIN {local_ga_workshops} w ON w.id = e.workshopid
          WHERE e.id = :editionid AND w.courseid = :courseid",
        ['editionid' => $editionid, 'courseid' => $courseid],
        IGNORE_MISSING
    ) ?: null;
}

function local_ga_is_course_teacher_candidate(int $courseid, int $userid): bool {
    if ($courseid <= 0 || $userid <= 0) {
        return false;
    }
    $teachers = manager::search_course_teachers($courseid, '');
    return isset($teachers[$userid]);
}

if ($action === 'addheeteacher' && confirm_sesskey() && $courseid > 0 && $editionid > 0 && $teacherid > 0) {
    $edition = local_ga_hee_edition_in_course($editionid, $courseid);
    if (!$edition) {
        throw new invalid_parameter_exception('La edición seleccionada no pertenece al curso indicado.');
    }
    if (!local_ga_is_course_teacher_candidate($courseid, $teacherid)) {
        throw new invalid_parameter_exception('El usuario seleccionado no consta como profesor del curso.');
    }
    if (!$DB->record_exists('local_ga_edition_teachers', ['editionid' => $editionid, 'userid' => $teacherid])) {
        $DB->insert_record('local_ga_edition_teachers', (object)[
            'editionid' => $editionid,
            'userid' => $teacherid,
            'timecreated' => time(),
        ]);
    }
    manager::invalidate_teacher_block_cache([$teacherid], [$editionid]);
    redirect(
        new moodle_url('/local/gestion_actividades/authorized_users.php', ['courseid' => $courseid]),
        'Profesor HEE asignado al taller.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'removeheeteacher' && confirm_sesskey() && $courseid > 0 && $editionid > 0 && $teacherid > 0) {
    $edition = local_ga_hee_edition_in_course($editionid, $courseid);
    if (!$edition) {
        throw new invalid_parameter_exception('La edición seleccionada no pertenece al curso indicado.');
    }
    $DB->delete_records('local_ga_edition_teachers', ['editionid' => $editionid, 'userid' => $teacherid]);
    manager::invalidate_teacher_block_cache([$teacherid], [$editionid]);
    redirect(
        new moodle_url('/local/gestion_actividades/authorized_users.php', ['courseid' => $courseid]),
        'Profesor HEE retirado de este taller.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/authorized_users.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('authorizedusers', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();

$course = $courseid > 0 ? $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname', IGNORE_MISSING) : null;
echo html_writer::start_div('mb-3');
if ($course) {
    echo html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al curso', ['class' => 'btn local-ga-back-course me-2']);
}
echo html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php', $courseid > 0 ? ['courseid' => $courseid] : []), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel']);
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('authorizedusers', 'local_gestion_actividades'));
echo html_writer::tag('p', 'Los usuarios autorizados son gestores HEE globales. La figura Profesor HEE, configurada más abajo, solo puede gestionar los talleres concretos a los que se le asigne.', ['class' => 'alert alert-info']);

$suggestions = ($courseid > 0) ? manager::search_course_teachers($courseid, '') : [];
$results = [];
$qtrim = trim($q);
if ($courseid > 0 && $qtrim !== '') {
    $results = manager::search_course_teachers($courseid, $qtrim);
    $needle = \core_text::strtolower($qtrim);
    $email = local_ga_auth_extract_email($qtrim);
    $emailneedle = $email !== '' ? \core_text::strtolower($email) : '';
    foreach ($suggestions as $candidate) {
        $label = local_ga_auth_label($candidate);
        $labelneedle = \core_text::strtolower($label);
        $candemail = \core_text::strtolower((string)$candidate->email);
        if ($labelneedle === $needle || ($emailneedle !== '' && $candemail === $emailneedle) || strpos($labelneedle, $needle) !== false) {
            $results[$candidate->id] = $candidate;
        }
    }
    if (!$results && $email !== '') {
        $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0, 'confirmed' => 1], 'id,firstname,lastname,email', IGNORE_MISSING);
        if ($user) {
            $results[$user->id] = $user;
        }
    }
}

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
echo html_writer::label(get_string('course'), 'courseid');
echo html_writer::start_tag('select', ['name' => 'courseid', 'id' => 'courseid', 'class' => 'form-select', 'style' => 'max-width:520px']);
foreach ($courses as $c) {
    echo html_writer::tag('option', format_string($c->fullname) . ' [' . s($c->shortname) . '] — ID ' . $c->id, ['value' => $c->id, 'selected' => ((int)$c->id === (int)$courseid) ? 'selected' : null]);
}
echo html_writer::end_tag('select');
echo html_writer::label(get_string('searchteacherautocomplete', 'local_gestion_actividades'), 'q', false, ['class' => 'mt-2']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'id' => 'q', 'value' => s($q), 'class' => 'form-control', 'style' => 'max-width:520px', 'list' => 'teacher-suggestions', 'autocomplete' => 'on']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'), 'class' => 'btn btn-primary mt-2']);
echo html_writer::end_tag('form');

echo html_writer::start_tag('datalist', ['id' => 'teacher-suggestions']);
foreach ($suggestions as $u) {
    echo html_writer::tag('option', '', ['value' => local_ga_auth_label($u)]);
}
echo html_writer::end_tag('datalist');

if ($qtrim !== '') {
    echo $OUTPUT->heading(get_string('searchresults'), 3);
    if ($results) {
        $table = new html_table();
        $table->head = [get_string('user'), get_string('email'), get_string('actions')];
        foreach ($results as $u) {
            $addurl = new moodle_url('/local/gestion_actividades/authorized_users.php', ['action' => 'add', 'userid' => $u->id, 'courseid' => $courseid, 'sesskey' => sesskey()]);
            $table->data[] = [fullname($u), s($u->email), html_writer::link($addurl, get_string('addauthorizeduser', 'local_gestion_actividades'), ['class' => 'btn btn-primary btn-sm'])];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('noteachersfoundcourse', 'local_gestion_actividades'), 'info');
    }
}

echo $OUTPUT->heading(get_string('currentauthorizedusers', 'local_gestion_actividades'), 3);
$users = manager::list_authorized_users();
if ($users) {
    $table = new html_table();
    $table->head = [get_string('user'), get_string('email'), get_string('actions')];
    foreach ($users as $u) {
        $removeurl = new moodle_url('/local/gestion_actividades/authorized_users.php', ['action' => 'remove', 'userid' => $u->id, 'courseid' => $courseid, 'sesskey' => sesskey()]);
        $table->data[] = [fullname($u), s($u->email), html_writer::link($removeurl, get_string('remove'), ['class' => 'btn btn-danger btn-sm'])];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noauthorizedusers', 'local_gestion_actividades'), 'info');
}

echo html_writer::tag('hr', '', ['class' => 'my-4']);
echo $OUTPUT->heading('Profesor HEE', 2);
echo html_writer::tag(
    'p',
    '<strong>Profesor HEE</strong> no es un rol global de Moodle. El profesor mantiene su matrícula institucional y únicamente obtiene permisos dentro de Gestión HEE para los talleres concretos a los que se le asigne: alumnado, asistencia, materiales y actividad obligatoria/reflexión. No puede administrar el resto de la asignatura.',
    ['class' => 'alert alert-success']
);

if ($courseid <= 0 || !$course) {
    echo $OUTPUT->notification('Selecciona un curso para gestionar sus Profesores HEE.', 'info');
} else if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_edition_teachers'))) {
    echo $OUTPUT->notification('La tabla de profesorado por edición aún no está disponible en esta instalación.', 'warning');
} else {
    $editions = $DB->get_records_sql(
        "SELECT e.id, e.workshopid, e.editioncode, e.name AS editionname, e.sessiondate, e.status, e.archived,
                w.code AS workshopcode, w.name AS workshopname, w.workshoptype
           FROM {local_ga_workshop_editions} e
           JOIN {local_ga_workshops} w ON w.id = e.workshopid
          WHERE w.courseid = :courseid
       ORDER BY e.sessiondate DESC, w.name ASC, e.id DESC",
        ['courseid' => $courseid]
    );

    if (!$editions) {
        echo $OUTPUT->notification('Este curso todavía no tiene talleres configurados.', 'info');
    } else {
        $editionids = array_map('intval', array_keys($editions));
        $assigned = [];
        if ($editionids) {
            [$insql, $inparams] = $DB->get_in_or_equal($editionids, SQL_PARAMS_NAMED, 'hee');
            $rows = $DB->get_records_sql(
                "SELECT et.id, et.editionid, et.userid, u.firstname, u.lastname, u.email
                   FROM {local_ga_edition_teachers} et
                   JOIN {user} u ON u.id = et.userid
                  WHERE et.editionid {$insql} AND u.deleted = 0
               ORDER BY u.lastname ASC, u.firstname ASC",
                $inparams
            );
            foreach ($rows as $row) {
                $assigned[(int)$row->editionid][] = $row;
            }
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['Taller', 'Edición', 'Fecha', 'Profesor/es HEE', 'Asignar profesor HEE'];
        foreach ($editions as $edition) {
            $type = manager::normalize_workshop_type((string)($edition->workshoptype ?? 'typea')) === 'typeb' ? 'Tipo B' : 'Tipo A';
            $current = [];
            foreach ($assigned[(int)$edition->id] ?? [] as $teacher) {
                $removeurl = new moodle_url('/local/gestion_actividades/authorized_users.php', [
                    'action' => 'removeheeteacher',
                    'courseid' => $courseid,
                    'editionid' => (int)$edition->id,
                    'teacherid' => (int)$teacher->userid,
                    'sesskey' => sesskey(),
                ]);
                $current[] = html_writer::div(
                    s(fullname($teacher)) . ' <small class="text-muted">' . s($teacher->email) . '</small> ' .
                    html_writer::link($removeurl, 'Quitar', ['class' => 'btn btn-outline-danger btn-sm ms-1']),
                    'mb-1'
                );
            }
            $currenthtml = $current ? implode('', $current) : html_writer::span('Sin Profesor HEE asignado', 'text-muted');

            $form = html_writer::start_tag('form', ['method' => 'post', 'class' => 'm-0']);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addheeteacher']);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'editionid', 'value' => (int)$edition->id]);
            if ($suggestions) {
                $options = [];
                foreach ($suggestions as $candidate) {
                    $options[(int)$candidate->id] = fullname($candidate) . ' — ' . $candidate->email;
                }
                $form .= html_writer::select($options, 'teacherid', '', ['' => 'Selecciona profesor'], ['class' => 'form-control form-control-sm mb-1', 'required' => 'required']);
                $form .= html_writer::tag('button', 'Asignar como Profesor HEE', ['type' => 'submit', 'class' => 'btn btn-primary btn-sm']);
            } else {
                $form .= html_writer::span('No se han detectado profesores matriculados en este curso.', 'text-muted');
            }
            $form .= html_writer::end_tag('form');

            $table->data[] = [
                html_writer::tag('strong', s($edition->workshopcode)) . '<br>' . format_string($edition->workshopname) . '<br><small>' . s($type) . '</small>',
                s($edition->editioncode ?: $edition->editionname),
                !empty($edition->sessiondate) ? manager::format_date_compact((int)$edition->sessiondate) : '-',
                $currenthtml,
                $form,
            ];
        }
        echo html_writer::table($table);
    }
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
