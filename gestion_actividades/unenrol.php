<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$editionid = required_param('id', PARAM_INT);
$edition = manager::get_workshop_edition($editionid);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => (int)$workshop->courseid], '*', MUST_EXIST);

require_login($course);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
require_sesskey();

$returnurl = new moodle_url('/local/gestion_actividades/workshop_view.php', [
    'id' => (int)$workshop->id,
    'editionid' => $editionid,
]);

if (!empty($edition->sessiondate) && time() >= (int)$edition->sessiondate) {
    redirect(
        $returnurl,
        'El taller ya ha comenzado. Si necesitas darte de baja, contacta con el profesor o gestor.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// The course-page button is a one-click link: ask before cancelling so a
// student cannot lose the place by accident.
if (!optional_param('confirm', 0, PARAM_BOOL)) {
    $PAGE->set_context(context_course::instance((int)$course->id));
    $PAGE->set_course($course);
    $PAGE->set_url(new moodle_url('/local/gestion_actividades/unenrol.php', ['id' => $editionid]));
    $PAGE->set_title(format_string($workshop->name));
    $PAGE->set_heading(format_string($course->fullname));
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        '¿Quieres desapuntarte de ' . format_string($workshop->code . ' - ' . $workshop->name)
            . '? Tu plaza quedará libre para otra persona.',
        new moodle_url('/local/gestion_actividades/unenrol.php', [
            'id' => $editionid,
            'sesskey' => sesskey(),
            'confirm' => 1,
        ]),
        $returnurl
    );
    echo $OUTPUT->footer();
    exit;
}

$result = manager::unenrol_user_from_edition($editionid, (int)$USER->id);

redirect(
    $returnurl,
    $result->message,
    null,
    $result->success
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_WARNING
);
