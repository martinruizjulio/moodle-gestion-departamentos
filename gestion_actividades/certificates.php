<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$editionid = required_param('editionid', PARAM_INT);
require_login();

$edition = manager::get_workshop_edition($editionid);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($course->id);

if (!manager::can_manage_edition((int)$edition->id, (int)$USER->id)) {
    throw new required_capability_exception($coursecontext, 'moodle/course:update', 'nopermissions', '');
}

$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/certificates.php', ['editionid' => $editionid]));
$PAGE->set_title(get_string('certificates', 'local_gestion_actividades'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]), $OUTPUT->pix_icon('t/left', '', 'moodle', ['class' => 'iconsmall mr-1']) . ' Volver al taller', ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');

echo $OUTPUT->heading(get_string('certificates', 'local_gestion_actividades') . ': ' . format_string($workshop->code . ' - ' . $workshop->name));

echo html_writer::link(new moodle_url('/local/gestion_actividades/generate_certificates.php', ['id' => $editionid, 'sesskey' => sesskey()]), get_string('generatecertificates', 'local_gestion_actividades'), ['class' => 'btn btn-primary']);
echo ' ';
echo html_writer::link(new moodle_url('/local/gestion_actividades/teacher_view.php', ['id' => $workshop->id, 'editionid' => $edition->id]), 'Volver al taller', ['class' => 'btn btn-secondary']);

$certificates = manager::list_edition_certificates((int)$editionid);
if ($certificates) {
    $table = new html_table();
    $table->head = [get_string('student', 'local_gestion_actividades'), get_string('email'), get_string('timeissued', 'local_gestion_actividades'), get_string('certificatecode', 'local_gestion_actividades'), get_string('actions')];
    foreach ($certificates as $c) {
        $url = new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $c->id]);
        $table->data[] = [
            fullname($c),
            s($c->email),
            userdate((int)$c->timeissued),
            s($c->certcode),
            html_writer::link($url, get_string('downloadcertificate', 'local_gestion_actividades'), ['class' => 'btn btn-secondary btn-sm']) . ' ' . html_writer::link(new moodle_url('/local/gestion_actividades/regenerate_certificate.php', ['id' => $c->id, 'sesskey' => sesskey()]), get_string('regeneratecertificate', 'local_gestion_actividades'), ['class' => 'btn btn-warning btn-sm'])
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nocertificatesyet', 'local_gestion_actividades'), 'info');
}

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
