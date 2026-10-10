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
 * certificate_template.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

require_login();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

if (data_submitted() && confirm_sesskey()) {
    $html = required_param('templatehtml', PARAM_RAW);
    manager::save_certificate_template_html($html);
    redirect(new moodle_url('/local/gestion_actividades/certificate_template.php'), get_string('changessaved'));
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/gestion_actividades/certificate_template.php'));
$PAGE->set_title(get_string('certificatetemplate', 'local_gestion_actividades'));
$PAGE->set_heading(get_string('title', 'local_gestion_actividades'));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall me-1']) . ' Volver al panel', ['class' => 'btn local-ga-back-panel mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('certificatetemplate', 'local_gestion_actividades'));

echo html_writer::tag('p', get_string('certificatetemplate_help', 'local_gestion_actividades'), ['class' => 'alert alert-info']);

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('textarea', s(manager::get_certificate_template_html()), [
    'name' => 'templatehtml',
    'class' => 'form-control',
    'rows' => 10,
]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('savechanges'), 'class' => 'btn btn-primary mt-3']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), get_string('dashboard', 'local_gestion_actividades'), ['class' => 'btn btn-secondary mt-3']);
echo html_writer::end_tag('form');

echo html_writer::tag('h3', get_string('certificateplaceholders', 'local_gestion_actividades'), ['class' => 'mt-4']);
echo html_writer::tag('pre', "{alumno}\n{taller}\n{codigo_taller}\n{fecha}\n{horas}\n{curso_academico}\n{fecha_emision}\n{codigo_certificado}");

echo $OUTPUT->footer();
