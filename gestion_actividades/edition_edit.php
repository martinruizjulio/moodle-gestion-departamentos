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
 * edition_edit.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\course_layout;
use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;
use local_gestion_actividades\local\typeb_reflection_activity;
use local_gestion_actividades\local\date_helper;

require_login();

$id = optional_param('id', 0, PARAM_INT);
$workshopid = required_param('workshopid', PARAM_INT);
$seriesid = optional_param('seriesid', 0, PARAM_INT);
$sortorder = optional_param('sortorder', 0, PARAM_INT);

$workshop = manager::get_workshop($workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$context = context_course::instance((int)$course->id);

// Creating/editing the structure of an edition is global HEE administration.
// Profesor HEE manages only the assigned edition contents from teacher_view.php.
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$record = $id ? manager::get_workshop_edition($id) : null;
if ($record && (int)$record->workshopid !== $workshopid) {
    throw new invalid_parameter_exception('La edición seleccionada no pertenece a este taller.');
}
$istypebworkshop = manager::is_typeb_workshop($workshop);
$prefillhours = optional_param('prefillhours', '', PARAM_TEXT);
$prefilldescription = optional_param('prefilldescription', '', PARAM_TEXT);
$prefillname = optional_param('prefillname', '', PARAM_TEXT);
$linkedseries = workshop_series::series_for_workshop($workshopid);
$item = workshop_series::item_for_workshop($workshopid);
if ($linkedseries) {
    $seriesid = (int)$linkedseries->id;
    if ($sortorder <= 0 && $item) {
        $sortorder = (int)$item->sortorder;
    }
}

$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'publish' && $id > 0 && confirm_sesskey()) {
    if (manager::is_workshop_publishable($workshop)) {
        $ok = manager::ensure_workshop_course_visuals_safely((int)$workshop->id);
        workshop_series::refresh_for_workshop((int)$workshop->id);
        course_layout::synchronise_course((int)$course->id);
        redirect(
            new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $id, 'workshopid' => $workshopid]),
            $ok ? 'Taller publicado/actualizado en el curso.' : 'No se pudo publicar el taller en el curso.',
            null,
            $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
    redirect(
        new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $id, 'workshopid' => $workshopid]),
        'El taller todavía no está listo para publicarse: revisa fecha, estado y archivo.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $id, 'workshopid' => $workshopid]));
$PAGE->set_title(get_string('editedition', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

if (data_submitted() && confirm_sesskey()) {
    $teachers = optional_param_array('teachers', [], PARAM_INT);
    $sessiondate = date_helper::parse_user_datetime(required_param('sessiondate_text', PARAM_TEXT));
    $sessionenddate = date_helper::parse_user_datetime(required_param('sessionenddate_text', PARAM_TEXT));
    $enrolenddate = date_helper::parse_user_datetime(required_param('enrolenddate_text', PARAM_TEXT));
    if ($sessiondate <= 0 || $sessionenddate <= $sessiondate) {
        throw new moodle_exception('invaliddata', 'error', '', 'La hora de fin debe ser posterior a la hora de inicio.');
    }
    // Optional second day (two-day taller). Empty = one day.
    $day2text = trim(optional_param('session2date_text', '', PARAM_TEXT));
    $day2endtext = trim(optional_param('session2enddate_text', '', PARAM_TEXT));
    $session2date = $day2text !== '' ? date_helper::parse_user_datetime($day2text) : 0;
    $session2enddate = $day2text !== '' && $day2endtext !== '' ? date_helper::parse_user_datetime($day2endtext) : 0;
    if ($day2text !== '' && ($session2date <= 0 || $session2enddate <= $session2date)) {
        throw new moodle_exception('invaliddata', 'error', '', 'Segundo día: indica inicio y fin, y el fin debe ser posterior al inicio.');
    }
    if ($session2date > 0 && $session2date < $sessionenddate) {
        throw new moodle_exception('invaliddata', 'error', '', 'El segundo día debe ser posterior al primero.');
    }
    if ($enrolenddate <= 0 || $enrolenddate >= $sessiondate) {
        throw new moodle_exception('invaliddata', 'error', '', 'La inscripción debe cerrar antes del inicio del taller.');
    }
    // The Edición this seminar belongs to: the one in the URL, the one the
    // edition is explicitly linked to, or (legacy) the one found for the seminar.
    $targetseriesid = $seriesid > 0 ? $seriesid
        : ((int)($record->seriesid ?? 0) > 0 ? (int)$record->seriesid : ($linkedseries ? (int)$linkedseries->id : 0));
    // If the seminar now falls outside its Edición, widen the Edición instead of
    // rejecting the change, so an edit made on the seminar never leaves it
    // outside (and hidden from) its Edición by mistake.
    $seriesextension = null;
    if ($targetseriesid > 0) {
        try {
            $seriesextension = workshop_series::extend_to_cover(
                $targetseriesid,
                $sessiondate,
                max($sessionenddate, $session2enddate),
                $workshopid
            );
        } catch (\RuntimeException $e) {
            throw new moodle_exception('invaliddata', 'error', '', $e->getMessage());
        }
    }
    if ($seriesid > 0) {
        course_layout::assert_series_accepts_type($seriesid, $istypebworkshop ? 'typeb' : 'typea');
    }

    // Type A defaults to a Moodle quiz. Existing Type A editions keep their
    // already configured activity type so editing metadata never changes it.
    $existingtype = '';
    if (!$istypebworkshop && $record) {
        if (($record->requiredmodname ?? '') === 'quiz' || !empty($record->requiredquizcmid)) {
            $existingtype = 'quiz';
        } else if (($record->requiredmodname ?? '') === 'assign' || !empty($record->requiredassigncmid)) {
            $existingtype = 'assign';
        }
    }
    $activitycreationtype = $istypebworkshop ? 'assign' : ($existingtype !== '' ? $existingtype : 'quiz');
    $data = (object)[
        'id' => optional_param('id', 0, PARAM_INT),
        'workshopid' => $workshopid,
        'workshopname' => required_param('workshopname', PARAM_TEXT),
        'workshopdescription' => optional_param('workshopdescription', '', PARAM_TEXT),
        'workshophours' => optional_param('workshophours', '', PARAM_RAW_TRIMMED),
        'activityid' => optional_param('activityid', 0, PARAM_INT),
        'name' => required_param('name', PARAM_TEXT),
        'editioncode' => required_param('editioncode', PARAM_ALPHANUMEXT),
        'sessiondate' => $sessiondate,
        'enrolenddate' => $enrolenddate,
        'places' => required_param('places', PARAM_INT),
        'groupid' => $record->groupid ?? 0,
        'attendancecmid' => optional_param('attendancecmid', 0, PARAM_INT),
        'certificatecmid' => optional_param('certificatecmid', 0, PARAM_INT),
        'requiredcmid' => optional_param('requiredcmid', 0, PARAM_INT),
        'requiredmodname' => $activitycreationtype,
        'activitycreationtype' => $activitycreationtype,
        'archived' => $record->archived ?? 0,
        'timearchived' => $record->timearchived ?? 0,
        'status' => optional_param('status', 'open', PARAM_ALPHANUMEXT),
        'teachers' => $teachers,
    ];
    $savededitionid = manager::save_workshop_edition($data);
    if ($seriesid > 0 && array_key_exists('seriesid', $DB->get_columns('local_ga_workshop_editions'))) {
        // Link to its Edición before creating activities (as the Excel import
        // does): they are then born inside the Edición instead of following
        // the legacy path, which pulled them out of the course page.
        $DB->set_field('local_ga_workshop_editions', 'seriesid', $seriesid, ['id' => $savededitionid]);
    }

    if ($istypebworkshop) {
        // Manual Type B must match the Excel path: one edition group,
        // one Attendance activity and one reflection assignment.
        manager::get_or_create_edition_group($savededitionid);
        $attendance = manager::create_attendance_activity_for_edition(
            $savededitionid,
            $sortorder > 0 ? ('Asistencia T' . sprintf('%02d', $sortorder)) : ''
        );
        if (empty($attendance->success)) {
            throw new moodle_exception('invaliddata', 'error', '', (string)($attendance->message ?? 'No se pudo crear la asistencia Tipo B.'));
        }

        $reflection = typeb_reflection_activity::ensure_for_edition($savededitionid);
        if (empty($reflection->success)) {
            throw new moodle_exception('invaliddata', 'error', '', (string)($reflection->message ?? 'No se pudo crear la tarea de reflexión Tipo B.'));
        }
    } else {
        // manager::save_workshop_edition() keeps legacy compatibility defaults.
        // Re-assert the canonical Type A policy here: new editions are quiz-first,
        // while an existing explicitly configured assignment stays an assignment.
        $columns = $DB->get_columns('local_ga_workshop_editions');
        $requiredcmid = 0;
        if ($record) {
            if ($activitycreationtype === 'quiz') {
                $requiredcmid = !empty($record->requiredquizcmid)
                    ? (int)$record->requiredquizcmid
                    : (($record->requiredmodname ?? '') === 'quiz' ? (int)($record->requiredcmid ?? 0) : 0);
            } else {
                $requiredcmid = !empty($record->requiredassigncmid)
                    ? (int)$record->requiredassigncmid
                    : (($record->requiredmodname ?? '') === 'assign' ? (int)($record->requiredcmid ?? 0) : 0);
            }
        }
        $update = (object)[
            'id' => $savededitionid,
            'requiredcmid' => $requiredcmid,
            'requiredmodname' => $activitycreationtype,
            'activitycreationtype' => $activitycreationtype,
            'timemodified' => time(),
        ];
        if (isset($columns['requiredquizcmid'])) {
            $update->requiredquizcmid = $activitycreationtype === 'quiz' ? $requiredcmid : 0;
        }
        if (isset($columns['requiredassigncmid'])) {
            $update->requiredassigncmid = $activitycreationtype === 'assign' ? $requiredcmid : 0;
        }
        $DB->update_record('local_ga_workshop_editions', $update);
    }

    if ($seriesid > 0) {
        // Link first so generated Moodle activities are born as modern
        // series-owned content rather than falling through the legacy path.
        workshop_series::attach_workshop($seriesid, $workshopid, $sortorder, 0, $sessionenddate, $savededitionid);
        workshop_series::set_item_teachernames($seriesid, $workshopid, optional_param('teachernames', '', PARAM_TEXT));
        workshop_series::set_item_second_day($seriesid, $workshopid, $session2date, $session2enddate);

        if (!$istypebworkshop) {
            $defaults = manager::ensure_typea_default_activities($savededitionid, $sortorder);
            if (empty($defaults->success)) {
                throw new moodle_exception('invaliddata', 'error', '', (string)$defaults->message);
            }
            workshop_series::ensure_course_structure($seriesid);
        }
    } else {
        workshop_series::refresh_for_workshop($workshopid);
    }
    // Keep the attendance session on the (possibly changed) workshop date.
    try {
        \local_gestion_actividades\local\attendance_sync::ensure_session($savededitionid);
    } catch (\Throwable $e) {
        debugging('No se pudo actualizar la sesión de asistencia: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    if ($seriesextension && $seriesid <= 0) {
        // Refresh the calendar/date range shown in the course.
        workshop_series::ensure_course_structure((int)$seriesextension->seriesid);
    }
    // Saving also publishes: the same steps as «Publicar en curso», so the
    // course shows the changes without a second click.
    $published = false;
    $freshworkshop = manager::get_workshop($workshopid);
    if (manager::is_workshop_publishable($freshworkshop)) {
        $published = manager::ensure_workshop_course_visuals_safely($workshopid);
        workshop_series::refresh_for_workshop($workshopid);
    }
    course_layout::synchronise_course((int)$course->id);
    $savedmessage = $published ? 'Cambios guardados y publicados en el curso.' : get_string('changessaved');
    if ($seriesextension) {
        $savedmessage .= ' ' . workshop_series::extension_message($seriesextension);
    }
    // Stay on this same form after saving.
    redirect(
        new moodle_url('/local/gestion_actividades/edition_edit.php', [
            'id' => $savededitionid,
            'workshopid' => $workshopid,
            'seriesid' => $seriesid,
            'sortorder' => $sortorder,
        ]),
        $savedmessage,
        null,
        $seriesextension ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel me-2 mb-3']) .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php', $seriesid > 0 ? ['id' => $seriesid] : []), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver a ediciones', ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);

echo $OUTPUT->heading('Configuración completa del taller: ' . s($workshop->code));
if ($linkedseries) {
    echo html_writer::tag('div', '<strong>Edición de talleres:</strong> ' . s($linkedseries->title) . ($sortorder > 0 ? ' · Taller ' . sprintf('%02d', $sortorder) : '') . '. Los campos siguen la misma secuencia del Excel y, al guardar, el calendario HTML se actualiza automáticamente.', ['class' => 'alert alert-info']);
}

$teachers = manager::get_course_teachers($workshop->courseid);
$selectedteachers = $id ? array_keys(manager::get_edition_teachers($id)) : [];

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'workshopid', 'value' => $workshopid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'seriesid', 'value' => $seriesid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sortorder', 'value' => $sortorder]);

echo html_writer::tag('h3', 'Datos del taller', ['class' => 'h4 mt-3']);
echo html_writer::label('Nombre del taller', 'workshopname');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'workshopname', 'class' => 'form-control mb-2', 'required' => 'required', 'value' => ($prefillname !== '' ? $prefillname : ($workshop->name ?? ''))]);
echo html_writer::label('Descripción del taller', 'workshopdescription');
echo html_writer::tag('textarea', s(($prefilldescription !== '' ? $prefilldescription : ($workshop->description ?? ''))), ['name' => 'workshopdescription', 'class' => 'form-control mb-3', 'rows' => 3]);

$editionnamevalue = $record->name ?? ($workshop->code . ' - ' . $workshop->name);
$editioncodevalue = $record->editioncode ?? ($workshop->code . '_E1');
$defaultsessiontime = $linkedseries ? max((int)$linkedseries->datefrom, time() + DAYSECS) : time() + 14 * DAYSECS;
$sessiondatevalue = date_helper::input_datetime(!empty($record->sessiondate) ? (int)$record->sessiondate : (int)$defaultsessiontime);
$existingend = $item ? (int)$item->sessionenddate : 0;
$sessionendvalue = date_helper::input_datetime($existingend > 0 ? $existingend : (int)$defaultsessiontime + 2 * HOURSECS);
$enrolenddatevalue = date_helper::input_datetime(!empty($record->enrolenddate) ? (int)$record->enrolenddate : (int)$defaultsessiontime - 7 * DAYSECS);
$placesvalue = $record->places ?? 20;
$workshophoursvalue = $prefillhours !== '' ? $prefillhours : (isset($workshop->hours) && $workshop->hours !== null ? str_replace('.', ',', (string)$workshop->hours) : '');

echo html_writer::tag('h3', 'Fecha, horario, horas e inscripción', ['class' => 'h4 mt-4']);
echo html_writer::label('Fecha y hora de inicio', 'sessiondate_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'sessiondate_text', 'class' => 'form-control mb-2', 'required' => 'required', 'value' => str_replace(' ', 'T', $sessiondatevalue)]);
echo html_writer::label('Fecha y hora de fin', 'sessionenddate_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'sessionenddate_text', 'class' => 'form-control mb-2', 'required' => 'required', 'value' => str_replace(' ', 'T', $sessionendvalue)]);
$day2 = workshop_series::second_day($item ?: null);
echo html_writer::start_tag('details', ['class' => 'mb-3 border rounded p-2'] + ($day2 ? ['open' => 'open'] : []));
echo html_writer::tag('summary', 'Taller de dos días (opcional)');
echo html_writer::tag('p', 'Rellena solo si el taller se hace en dos días. Se crearán dos sesiones en la lista de asistencia y hará falta «Presente» los dos días. Las horas del taller son el total de ambos días. Déjalo vacío para un taller de un día.', ['class' => 'text-muted small mt-2']);
echo html_writer::label('Segundo día: fecha y hora de inicio', 'session2date_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'session2date_text', 'class' => 'form-control mb-2',
    'value' => $day2 ? str_replace(' ', 'T', date_helper::input_datetime($day2[0])) : '']);
echo html_writer::label('Segundo día: fecha y hora de fin', 'session2enddate_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'session2enddate_text', 'class' => 'form-control mb-2',
    'value' => $day2 ? str_replace(' ', 'T', date_helper::input_datetime($day2[1])) : '']);
echo html_writer::end_tag('details');
echo html_writer::label('Horas del taller', 'workshophours');
echo html_writer::empty_tag('input', ['type' => 'text', 'inputmode' => 'decimal', 'name' => 'workshophours', 'class' => 'form-control mb-2', 'required' => 'required', 'value' => $workshophoursvalue]);
echo html_writer::label('Número de plazas', 'places');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'places', 'class' => 'form-control mb-2', 'required' => 'required', 'min' => '1', 'value' => $placesvalue]);
echo html_writer::label('Fecha límite de inscripción al taller', 'enrolenddate_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'enrolenddate_text', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => str_replace(' ', 'T', $enrolenddatevalue)]);

echo html_writer::tag('h3', 'Identificación de la edición del taller', ['class' => 'h4 mt-4']);
echo html_writer::label('Nombre de la edición', 'name');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'class' => 'form-control mb-2', 'required' => 'required', 'value' => $editionnamevalue]);
echo html_writer::label('Código de edición', 'editioncode');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'editioncode', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => $editioncodevalue]);

echo html_writer::tag('h3', 'Profesorado y contenido Moodle', ['class' => 'h4 mt-4']);
echo html_writer::label('Profesores del taller', 'teachers');
echo html_writer::start_tag('select', ['name' => 'teachers[]', 'multiple' => 'multiple', 'class' => 'form-select mb-3', 'size' => 8]);
foreach ($teachers as $t) {
    echo html_writer::tag('option', fullname($t) . ' — ' . $t->email, ['value' => $t->id, 'selected' => in_array($t->id, $selectedteachers) ? 'selected' : null]);
}
echo html_writer::end_tag('select');
if ($linkedseries || $seriesid > 0) {
    echo html_writer::label('Nombre del profesorado para el calendario', 'teachernames');
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'teachernames', 'id' => 'teachernames',
        'class' => 'form-control mb-1', 'maxlength' => 255, 'value' => (string)($item->teachernames ?? ''),
        'placeholder' => 'Ej.: Ana García; Luis Pérez']);
    echo html_writer::tag('div', 'Es lo que se muestra en el calendario de la Edición. Si se deja vacío, se muestran los nombres de los profesores seleccionados arriba.', ['class' => 'form-text mb-3']);
}

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'activityid', 'value' => $record->activityid ?? 0]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attendancecmid', 'value' => $record->attendancecmid ?? 0]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'certificatecmid', 'value' => $record->certificatecmid ?? 0]);
if ($istypebworkshop) {
    echo html_writer::tag('div', '<strong>Actividad asociada:</strong> Tarea Moodle de reflexión. El alumno escribe su valoración del taller como texto en línea (sin archivos adjuntos). Con asistencia confirmada + entrega de la reflexión queda Apto y puede generarse el certificado.', ['class' => 'alert alert-info']);
    $requiredcmidvalue = $record->requiredcmid ?? 0;
} else {
    if (!$record) {
        $activitylabel = 'Cuestionario Moodle (predeterminado)';
    } else if ((($record->requiredmodname ?? '') === 'quiz') || !empty($record->requiredquizcmid)) {
        $activitylabel = 'Cuestionario Moodle';
    } else {
        $activitylabel = 'Tarea Moodle';
    }
    echo html_writer::tag('div', '<strong>Actividad asociada actual:</strong> ' . s($activitylabel) . '. Los talleres Tipo A nuevos usan cuestionario Moodle por defecto. Puede vincularse otra actividad posteriormente desde la gestión del taller. En las importaciones Excel puede duplicarse un cuestionario modelo; sus preguntas se crean o importan siempre desde Moodle.', ['class' => 'alert alert-info']);
    $requiredcmidvalue = $record->requiredcmid ?? 0;
}
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'requiredcmid', 'value' => $requiredcmidvalue]);

echo html_writer::label('Estado', 'status');
$statusoptions = [
    'pending' => 'Pendiente',
    'open' => 'Abierto / vigente',
    'closed_full' => 'Cerrado por plazas',
    'closed_date' => 'Cerrado por fecha',
    'finished' => 'Finalizado',
];
echo html_writer::select($statusoptions, 'status', $record->status ?? 'pending', false, ['class' => 'form-control mb-3']);

echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('savechanges')]);
if ($id) {
    echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/edition_edit.php', ['id' => $id, 'workshopid' => $workshopid, 'action' => 'publish', 'sesskey' => sesskey()]), 'Publicar en curso', ['class' => 'btn btn-success']);
    echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/edition_delete.php', ['id' => $id]), get_string('deleteedition', 'local_gestion_actividades'), ['class' => 'btn btn-danger']);
}
echo html_writer::end_tag('form');

echo html_writer::script("
(function() {
  var start = document.querySelector('input[name=\"sessiondate_text\"]');
  var end = document.querySelector('input[name=\"sessionenddate_text\"]');
  var deadline = document.querySelector('input[name=\"enrolenddate_text\"]');
  function pad(n){ return String(n).padStart(2, '0'); }
  function formatLocal(d){
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  function updateRelated(){
    if (!start || !start.value) { return; }
    var s = new Date(start.value);
    if (isNaN(s.getTime())) { return; }
    if (end && (!end.value || new Date(end.value) <= s)) {
      var e = new Date(s.getTime() + 2 * 60 * 60 * 1000);
      end.value = formatLocal(e);
    }
    if (deadline) {
      var d = new Date(s);
      d.setDate(d.getDate() - 7);
      deadline.value = formatLocal(d);
    }
  }
  if (start) start.addEventListener('change', updateRelated);
})();
");

echo $OUTPUT->footer();
