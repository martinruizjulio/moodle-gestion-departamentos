<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;
use local_gestion_actividades\local\date_helper;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}
workshop_series::ensure_schema();

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if ($action === 'save') {
            $datefrom = date_helper::parse_user_datetime(required_param('datefrom_text', PARAM_TEXT));
            $dateto = date_helper::parse_user_datetime(required_param('dateto_text', PARAM_TEXT));
            $id = workshop_series::save((object)[
                'id' => optional_param('id', 0, PARAM_INT),
                'courseid' => required_param('courseid', PARAM_INT),
                'title' => required_param('title', PARAM_TEXT),
                'datefrom' => $datefrom,
                'dateto' => $dateto,
                'status' => optional_param('status', 'active', PARAM_ALPHA),
            ]);
            $message = 'Edición de talleres guardada y estructura del curso actualizada.';
        } else if ($action === 'finish' && $id > 0) {
            workshop_series::set_finished($id, true);
            $message = 'Edición finalizada y oculta para el alumnado.';
        } else if ($action === 'reopen' && $id > 0) {
            workshop_series::set_finished($id, false);
            $message = 'Edición reabierta.';
        } else if ($action === 'refresh' && $id > 0) {
            workshop_series::ensure_course_structure($id);
            $message = 'Calendario y subsecciones actualizados.';
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
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), '← Volver al panel', ['class' => 'btn btn-outline-secondary me-2 mb-3']) .
    html_writer::link(new moodle_url('/local/gestion_actividades/workshops.php', ['type' => 'typea']), 'Talleres', ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);
echo $OUTPUT->heading('Ediciones de talleres');
echo html_writer::tag('p', 'Cada edición agrupa varios talleres en una sección del curso. El calendario se genera automáticamente y cada taller se coloca en su propia subsección.', ['class' => 'lead']);

if ($message !== '') echo $OUTPUT->notification($message, 'success');
if ($error !== '') echo $OUTPUT->notification(s($error), 'error');

$courseoptions = manager::get_course_options();

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', $record ? 'Editar edición de talleres' : 'Nueva edición de talleres', ['class' => 'h5']);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $record->id ?? 0]);

echo html_writer::label('Curso', 'courseid');
echo html_writer::select($courseoptions, 'courseid', $record->courseid ?? $courseid, ['' => 'Selecciona un curso'], ['class' => 'form-control mb-3', 'required' => 'required']);

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

echo html_writer::label('Estado', 'status');
echo html_writer::select(['active' => 'Activa', 'finished' => 'Finalizada / oculta'], 'status', $record->status ?? 'active', false, ['class' => 'form-control mb-3']);
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
    $table->head = ['Curso', 'Edición', 'Fechas', 'Talleres', 'Estado', 'Acciones'];
    foreach ($series as $s) {
        $course = $DB->get_record('course', ['id' => $s->courseid], 'id,fullname', IGNORE_MISSING);
        $count = count(workshop_series::items((int)$s->id));
        $actions = html_writer::link(new moodle_url('/local/gestion_actividades/workshop_series.php', ['id' => $s->id]), 'Editar', ['class' => 'btn btn-sm btn-primary me-1']);
        $actions .= html_writer::link(new moodle_url('/local/gestion_actividades/workshop_edit.php', ['type' => 'typea', 'seriesid' => $s->id, 'sortorder' => workshop_series::next_sortorder((int)$s->id)]), 'Añadir taller manual', ['class' => 'btn btn-sm btn-success me-1']);
        $actions .= html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-block']);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $s->id]);
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'refresh']);
        $actions .= html_writer::tag('button', 'Actualizar calendario', ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary me-1']);
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
        $table->data[] = [
            $course ? format_string($course->fullname) : (int)$s->courseid,
            s($s->title),
            userdate((int)$s->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$s->dateto, '%d/%m/%Y'),
            $count,
            $s->status === 'finished' ? 'Finalizada / oculta' : (((int)$s->dateto < time()) ? 'Fecha final superada / oculta' : 'Activa'),
            $actions,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
