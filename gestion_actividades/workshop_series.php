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
 * workshop_series.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\course_layout;
use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;
use local_gestion_actividades\local\date_helper;
use local_gestion_actividades\local\academic_year;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}
workshop_series::ensure_schema();

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
    $SESSION->local_ga_return_courseid = $courseid;
} else if (
    $courseid <= 0 && !empty($SESSION->local_ga_return_courseid)
        && $DB->record_exists('course', ['id' => (int)$SESSION->local_ga_return_courseid])
) {
    $courseid = (int)$SESSION->local_ga_return_courseid;
}
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if ($action === 'save') {
            $datefrom = date_helper::parse_user_datetime(required_param('datefrom_text', PARAM_TEXT));
            $dateto = date_helper::parse_user_datetime(required_param('dateto_text', PARAM_TEXT));
            $savedcourseid = required_param('courseid', PARAM_INT);
            $id = workshop_series::save((object)[
                'id' => optional_param('id', 0, PARAM_INT),
                'courseid' => $savedcourseid,
                'title' => required_param('title', PARAM_TEXT),
                'datefrom' => $datefrom,
                'dateto' => $dateto,
                'status' => optional_param('status', 'active', PARAM_ALPHA),
                'academicyear' => optional_param('academicyear', '', PARAM_RAW_TRIMMED),
            ]);
            course_layout::synchronise_course($savedcourseid);
            $message = 'Edición de talleres guardada y jerarquía del curso actualizada.';
        } else if ($action === 'finish' && $id > 0) {
            $target = workshop_series::get($id);
            workshop_series::set_finished($id, true);
            course_layout::synchronise_course((int)$target->courseid);
            $message = 'Edición finalizada, oculta al alumnado y movida al histórico inferior.';
        } else if ($action === 'reopen' && $id > 0) {
            $target = workshop_series::get($id);
            workshop_series::set_finished($id, false);
            course_layout::synchronise_course((int)$target->courseid);
            $message = 'Edición reabierta y devuelta a la zona activa correspondiente.';
        } else if ($action === 'refresh' && $id > 0) {
            $target = workshop_series::get($id);
            workshop_series::ensure_course_structure($id);
            course_layout::synchronise_course((int)$target->courseid);
            $message = 'Calendario, subsecciones y jerarquía A/B actualizados.';
        } else if ($action === 'delete' && $id > 0) {
            $target = workshop_series::get($id);
            $targetcourseid = (int)$target->courseid;
            workshop_series::delete_series($id);
            course_layout::synchronise_course($targetcourseid);
            $id = 0;
            $courseid = $targetcourseid;
            $message = 'Edición vacía retirada de la gestión activa. Su sección queda oculta en el curso; no se ha borrado ninguna actividad.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$record = $id > 0 ? workshop_series::get($id) : null;
if ($record && $courseid <= 0) {
    $courseid = (int)$record->courseid;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/workshop_series.php', $id > 0 ? ['id' => $id] : []));
$PAGE->set_title('Ediciones de talleres');
$PAGE->set_heading('Gestión HEE');

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), '← Volver al panel', ['class' => 'btn local-ga-back-panel me-2 mb-3']) .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typea']), 'Talleres', ['class' => 'btn btn-outline-secondary me-2 mb-3']) .
    html_writer::link(new moodle_url('/local/gestion_actividades/test_cleanup.php'), 'Limpieza de pruebas', ['class' => 'btn btn-danger mb-3']),
    'mb-2'
);
echo $OUTPUT->heading('Ediciones de talleres');
echo html_writer::tag('p', 'Cada edición agrupa talleres de un único tipo (A o B) en una sección del curso. Primero aparece el calendario HTML y después las subsecciones Taller 01, 02… Las ediciones finalizadas se ocultan y se conservan debajo de la autoevaluación.', ['class' => 'lead']);

if ($message !== '') {
    echo $OUTPUT->notification($message, 'success');
}
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}

$courseoptions = manager::get_course_options();

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', $record ? 'Editar edición de talleres' : 'Nueva edición de talleres', ['class' => 'h5']);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $record->id ?? 0]);

echo html_writer::label('Curso', 'courseid');
echo html_writer::select($courseoptions, 'courseid', $record->courseid ?? $courseid, ['' => 'Selecciona un curso'], ['class' => 'form-select mb-3', 'required' => 'required']);

echo html_writer::label('Título de la edición', 'title');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'id' => 'title', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => $record->title ?? '']);

$defaultfrom = time() + 7 * DAYSECS;
$defaultto = $defaultfrom + 6 * DAYSECS;
$datefromvalue = date_helper::input_datetime(!empty($record->datefrom) ? (int)$record->datefrom : (int)$defaultfrom);
$datetovalue = date_helper::input_datetime(!empty($record->dateto) ? (int)$record->dateto : (int)$defaultto);
echo html_writer::label('Inicio de la edición', 'datefrom_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'datefrom_text', 'id' => 'datefrom_text', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => str_replace(' ', 'T', $datefromvalue)]);
echo html_writer::label('Fin de la edición', 'dateto_text');
echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'dateto_text', 'id' => 'dateto_text', 'class' => 'form-control mb-3', 'required' => 'required', 'value' => str_replace(' ', 'T', $datetovalue)]);

$cur = academic_year::current();
$curstart = (int)explode('/', $cur)[0];
$yearoptions = ['' => 'Automático según la fecha de inicio'];
foreach (academic_year::options([($curstart + 1) . '/' . ($curstart + 2), ($curstart - 1) . '/' . $curstart]) as $y) {
    $yearoptions[$y] = $y . ($y === $cur ? ' (actual)' : '');
}
$recordyear = $record ? academic_year::for_series($record) : '';
echo html_writer::label('Curso académico', 'academicyear');
echo html_writer::select($yearoptions, 'academicyear', $recordyear, false, ['class' => 'form-select', 'id' => 'academicyear']);
echo html_writer::div('Se usa para filtrar y ordenar los listados. Por defecto, el curso de la fecha de inicio (cambia el 1 de septiembre).', 'form-text text-muted mb-3');

echo html_writer::label('Estado', 'status');
echo html_writer::select(['active' => 'Activa', 'finished' => 'Finalizada / oculta'], 'status', $record->status ?? 'active', false, ['class' => 'form-select mb-3']);
echo html_writer::tag('button', $record ? 'Guardar edición' : 'Crear edición', ['type' => 'submit', 'class' => 'btn btn-primary']);
if ($record) {
    echo ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php'), 'Nueva edición', ['class' => 'btn btn-secondary']);
}
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

$series = workshop_series::list_for_course(0);
if ($series) {
    echo html_writer::tag('h3', 'Ediciones existentes', ['class' => 'h4']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['Curso académico', 'Curso', 'Edición', 'Tipo', 'Fechas', 'Talleres', 'Estado', 'Acciones'];
    foreach ($series as $s) {
        $course = $DB->get_record('course', ['id' => $s->courseid], 'id,fullname', IGNORE_MISSING);
        $count = count(workshop_series::items((int)$s->id));
        $seriestype = course_layout::series_type((int)$s->id);
        $typelabel = $seriestype === 'typeb' ? 'Tipo B' : ($seriestype === 'typea' ? 'Tipo A' : ($seriestype === 'mixed' ? 'Mixta heredada' : 'Sin definir'));
        $nextorder = workshop_series::next_sortorder((int)$s->id);
        $actions = html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php', ['id' => $s->id]), 'Editar', ['class' => 'btn btn-sm btn-primary me-1']);
        if ($seriestype === 'typea' || $seriestype === '') {
            $actions .= html_writer::link(new moodle_url('/local/gestion_actividades/workshop_edit.php', ['type' => 'typea', 'seriesid' => $s->id, 'sortorder' => $nextorder]), $seriestype === '' ? 'Añadir taller A' : 'Añadir taller', ['class' => 'btn btn-sm btn-success me-1']);
        }
        if ($seriestype === 'typeb' || $seriestype === '') {
            $actions .= html_writer::link(new moodle_url('/local/gestion_actividades/workshop_edit.php', ['type' => 'typeb', 'seriesid' => $s->id, 'sortorder' => $nextorder]), $seriestype === '' ? 'Añadir taller B' : 'Añadir taller', ['class' => 'btn btn-sm btn-success me-1']);
        }
        $actions .= html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-block']);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $s->id]);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'refresh']);
        $actions .= html_writer::tag('button', 'Actualizar estructura', ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary me-1']);
        $actions .= html_writer::end_tag('form');
        if ($s->status === 'finished') {
            $actions .= html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-block']);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $s->id]);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reopen']);
            $actions .= html_writer::tag('button', 'Reabrir', ['type' => 'submit', 'class' => 'btn btn-sm btn-warning']);
            $actions .= html_writer::end_tag('form');
        } else {
            $actions .= html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-block']);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $s->id]);
            $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'finish']);
            $actions .= html_writer::tag('button', 'Finalizar y ocultar', ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
            $actions .= html_writer::end_tag('form');
        }
        $actions .= html_writer::start_tag('form', [
            'method' => 'post',
            'style' => 'display:inline-block',
            'onsubmit' => "return confirm('¿Borrar esta Edición de talleres? Solo es posible si aún no tiene inscripciones ni datos académicos; en ese caso usa Finalizar y ocultar.');",
        ]);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $s->id]);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete']);
        $actions .= html_writer::tag('button', 'Borrar edición', ['type' => 'submit', 'class' => 'btn btn-sm btn-danger ms-1']);
        $actions .= html_writer::end_tag('form');

        $table->data[] = [
            academic_year::for_series($s),
            $course ? format_string($course->fullname) : (int)$s->courseid,
            s($s->title),
            $typelabel,
            userdate((int)$s->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$s->dateto, '%d/%m/%Y'),
            $count,
            $s->status === 'finished' ? 'Finalizada / oculta' : 'Activa',
            $actions,
        ];
    }
    echo html_writer::table($table);
}
if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
