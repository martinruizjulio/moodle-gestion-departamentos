<?php
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

$editionid = required_param('id', PARAM_INT);
$edition = manager::get_workshop_edition($editionid);
$workshop = manager::get_workshop((int)$edition->workshopid);
$course = $DB->get_record('course', ['id' => $workshop->courseid], '*', MUST_EXIST);

require_login($course);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}

$returnurl = new moodle_url('/local/gestion_actividades/workshop_view.php', [
    'id' => $workshop->id,
    'editionid' => $editionid,
]);

// The enrol link is also stored in shared section HTML, where a per-session
// sesskey cannot be embedded. Without a valid sesskey, ask for confirmation
// instead of enrolling on a plain GET (CSRF protection).
// confirm_sesskey() without argument calls required_param('sesskey') and
// throws "missing parameter" for links without it, so read it optionally.
$sesskey = optional_param('sesskey', '', PARAM_RAW);
if ($sesskey === '' || !confirm_sesskey($sesskey)) {
    $context = context_course::instance((int)$course->id);
    $PAGE->set_context($context);
    $PAGE->set_course($course);
    $PAGE->set_url(new moodle_url('/local/gestion_actividades/enrol.php', ['id' => $editionid]));
    $PAGE->set_title(format_string($workshop->name));
    $PAGE->set_heading(format_string($course->fullname));

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('enrolme', 'local_gestion_actividades') . ': ' . format_string($workshop->code . ' - ' . $workshop->name),
        new moodle_url('/local/gestion_actividades/enrol.php', ['id' => $editionid, 'sesskey' => sesskey()]),
        $returnurl
    );
    echo $OUTPUT->footer();
    exit;
}

$result = manager::enrol_user_in_edition($editionid, (int)$USER->id, 'self');

redirect(
    $returnurl,
    $result->message,
    null,
    $result->success ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
);
