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
 * typeb_catalog.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Catálogo de talleres Tipo B externos (nombre + horas). Solo Gestor HEE.
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\typeb_catalog;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}
$url = new moodle_url('/local/gestion_actividades/typeb_catalog.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title('Catálogo de talleres Tipo B externos');
$PAGE->set_heading('Gestión HEE');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action !== '' && data_submitted()) {
    require_sesskey();
    try {
        if ($action === 'save') {
            $hours = unformat_float(required_param('hours', PARAM_RAW_TRIMMED));
            typeb_catalog::save($id, required_param('name', PARAM_TEXT), (float)$hours, (int)$USER->id);
            redirect($url, $id > 0 ? 'Taller actualizado.' : 'Taller añadido al catálogo.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'retire' || $action === 'activate') {
            typeb_catalog::set_active($id, $action === 'activate', (int)$USER->id);
            redirect($url, $action === 'activate' ? 'Taller disponible de nuevo para los alumnos.'
                : 'Taller retirado: ya no aparece en el desplegable. Sus solicitudes se conservan.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'delete') {
            $ok = typeb_catalog::delete_unused($id);
            redirect(
                $url,
                $ok ? 'Taller eliminado del catálogo.' : 'No se puede eliminar: hay solicitudes de alumnos con este taller. Usa «Retirar».',
                null,
                $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
            );
        }
    } catch (invalid_parameter_exception $e) {
        redirect(
            new moodle_url($url, $id > 0 ? ['edit' => $id] : []),
            $e->debuginfo ?: $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$editid = optional_param('edit', 0, PARAM_INT);
$editing = $editid > 0 ? typeb_catalog::get($editid) : null;

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel me-2 mb-3'])
    . html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_admin.php', ['status' => 'pending']), 'Revisar solicitudes Tipo B', ['class' => 'btn btn-warning mb-3']),
    'mb-2'
);
echo $OUTPUT->heading('Catálogo de talleres Tipo B externos');
echo html_writer::tag('p', 'Los alumnos eligen uno de estos talleres al subir su certificado; el título y las horas son siempre los del catálogo. '
    . 'Cada alumno solo puede presentar un taller una vez. Al validar la solicitud y entregar el alumno su reflexión, estas horas se suman a su ficha como Tipo B. '
    . '«Retirar» lo quita del desplegable sin tocar las solicitudes ya presentadas.', ['class' => 'alert alert-info']);

// Add / edit form.
$form = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save'])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $editing ? (int)$editing->id : 0])
    . html_writer::div(
        html_writer::div(html_writer::label('Nombre del taller', 'id_name') . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'id' => 'id_name',
            'class' => 'form-control', 'required' => 'required', 'maxlength' => 255, 'value' => $editing ? $editing->name : '']), 'col-md-8 mb-2')
        . html_writer::div(html_writer::label('Horas', 'id_hours') . html_writer::empty_tag('input', ['type' => 'text', 'inputmode' => 'decimal', 'name' => 'hours', 'id' => 'id_hours',
            'class' => 'form-control', 'required' => 'required', 'placeholder' => '2,5', 'value' => $editing ? format_float((float)$editing->hours, 2, true) : '']), 'col-md-2 mb-2')
        . html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary w-100', 'value' => $editing ? 'Guardar' : 'Añadir']), 'col-md-2 mb-2 d-flex align-items-end'),
        'row'
    );
if ($editing) {
    $form .= html_writer::div('Si cambias el nombre o las horas, también se actualizan en las solicitudes aún no validadas de este taller. Las ya validadas conservan sus horas.', 'text-muted small')
        . html_writer::link($url, 'Cancelar edición', ['class' => 'small']);
}
echo html_writer::div(html_writer::div(html_writer::tag('h3', $editing ? 'Editar taller' : 'Añadir taller', ['class' => 'h5'])
    . html_writer::tag('form', $form, ['method' => 'post', 'action' => $url->out(false)]), 'card-body'), 'card mb-4');

// List.
$items = typeb_catalog::all();
$counts = typeb_catalog::request_counts();
if (!$items) {
    echo $OUTPUT->notification('El catálogo está vacío. Añade el primer taller con el formulario de arriba.', 'info');
} else {
    $postbutton = function (string $action, int $id, string $label, string $class, string $confirm = '') use ($url) {
        $attrs = ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline'];
        if ($confirm !== '') {
            $attrs['onsubmit'] = 'return confirm(' . json_encode($confirm) . ');';
        }
        return html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id])
            . html_writer::tag('button', $label, ['type' => 'submit', 'class' => $class]), $attrs);
    };
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = ['Taller', 'Horas', 'Estado', 'Solicitudes', 'Acciones'];
    foreach ($items as $item) {
        $n = (int)($counts[$item->id] ?? 0);
        $actions = html_writer::link(new moodle_url($url, ['edit' => $item->id]), 'Editar', ['class' => 'btn btn-sm btn-outline-primary me-1']);
        $actions .= !empty($item->active)
            ? $postbutton('retire', (int)$item->id, 'Retirar', 'btn btn-sm btn-outline-warning me-1')
            : $postbutton('activate', (int)$item->id, 'Reactivar', 'btn btn-sm btn-outline-success me-1');
        if ($n === 0) {
            $actions .= $postbutton('delete', (int)$item->id, 'Eliminar', 'btn btn-sm btn-outline-danger', '¿Eliminar este taller del catálogo?');
        }
        $table->data[] = [
            format_string($item->name),
            format_float((float)$item->hours, 2, true) . ' h',
            !empty($item->active) ? html_writer::span('Disponible', 'badge bg-success') : html_writer::span('Retirado', 'badge bg-secondary'),
            $n,
            $actions,
        ];
    }
    echo html_writer::table($table);
}
if (!function_exists('local_gestion_actividades_enable_interactive_tables')) {
    require_once($CFG->dirroot . '/local/gestion_actividades/lib.php');
}
local_gestion_actividades_enable_interactive_tables();
echo $OUTPUT->footer();
