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
 * portfolio_cover_template.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\portfolio_pdf;

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

if (data_submitted() && confirm_sesskey()) {
    $html = required_param('coverhtml', PARAM_RAW);
    portfolio_pdf::save_cover_template($html);
    redirect(new moodle_url('/local/gestion_actividades/portfolio_cover_template.php'), 'Portada guardada correctamente.');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/portfolio_cover_template.php'));
$PAGE->set_title('Portada del portafolio');
$PAGE->set_heading('Portada del portafolio');

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading('Portada editable del portafolio');
echo html_writer::tag('p', 'Esta portada se usará en todos los PDF de portafolio y se imprimirá sobre la misma plantilla visual UCV usada para los certificados. Puedes editar el texto y usar las variables automáticas.', ['class' => 'alert alert-info']);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::start_div('row g-3 align-items-stretch');
echo html_writer::start_div('col-lg-6');
echo html_writer::tag('h3', 'Código / contenido HTML', ['class' => 'h5']);
echo html_writer::tag('textarea', s(portfolio_pdf::get_cover_template()), [
    'name' => 'coverhtml', 'id' => 'local-ga-coverhtml', 'class' => 'form-control font-monospace', 'rows' => 18,
    'style' => 'min-height:520px;resize:vertical;',
]);
echo html_writer::end_div();
echo html_writer::start_div('col-lg-6');
echo html_writer::tag('h3', 'Previsualización', ['class' => 'h5']);
echo html_writer::tag('iframe', '', [
    'id' => 'local-ga-cover-preview',
    'title' => 'Previsualización de la portada',
    'style' => 'width:100%;min-height:520px;border:1px solid #d8dee4;border-radius:.5rem;background:#fff;',
    'sandbox' => '',
]);
echo html_writer::div('La vista previa usa datos ficticios y se actualiza al escribir. El PDF final sustituirá las variables por los datos reales del alumno.', 'form-text text-muted mt-2');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Guardar portada', 'class' => 'btn btn-primary mt-3']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_admin.php'), 'Volver al portafolio gestor', ['class' => 'btn btn-secondary mt-3']);
echo html_writer::end_tag('form');

$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var editor = document.getElementById('local-ga-coverhtml');
    var preview = document.getElementById('local-ga-cover-preview');
    if (!editor || !preview) { return; }
    var values = {
        '{alumno}': 'María García López',
        '{curso}': '2026/2027',
        '{horas_tipo_a}': '28',
        '{horas_tipo_b}': '26',
        '{horas_total}': '54',
        '{fecha_emision}': '10/10/2026'
    };
    var render = function() {
        var html = editor.value;
        Object.keys(values).forEach(function(key) {
            html = html.split(key).join(values[key]);
        });
        preview.srcdoc = '<!doctype html><html><head><meta charset="utf-8">'
            + '<style>body{font-family:Arial,sans-serif;padding:28px;line-height:1.45;color:#222}img{max-width:100%}</style>'
            + '</head><body>' + html + '</body></html>';
    };
    editor.addEventListener('input', render);
    render();
})();
JS);
echo html_writer::tag('h3', 'Variables disponibles', ['class' => 'mt-4']);
echo html_writer::tag('pre', "{alumno}\n{curso}\n{horas_tipo_a}\n{horas_tipo_b}\n{horas_total}\n{fecha_emision}");
echo $OUTPUT->footer();
