<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\portfolio_pdf;
use local_gestion_actividades\local\portfolio_typeb;
use local_gestion_actividades\local\institutional_hours;
use local_gestion_actividades\local\typeb_reflection_activity;

require_login();
$context = context_system::instance();
if (!manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

function local_ga_dl_clean(string $name, string $fallback = 'documento'): string {
    $name = clean_filename(trim($name));
    return $name !== '' ? $name : $fallback;
}

function local_ga_btn_icon(string $pix, string $label): string {
    global $OUTPUT;
    return $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'iconsmall mr-1']) . ' ' . $label;
}

/**
 * Academic enrolment group for a student. Loaded once for the whole request
 * to avoid one database query per row.
 */
function local_ga_dl_student_group(int $userid): string {
    global $DB;
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_institutional_hours'))) {
            $records = $DB->get_records_sql("SELECT userid, groupname FROM {local_ga_institutional_hours} WHERE userid > 0");
            foreach ($records as $record) {
                $groups[(int)$record->userid] = trim((string)($record->groupname ?? ''));
            }
        }
    }
    $value = trim((string)($groups[$userid] ?? ''));
    return $value !== '' ? $value : '-';
}

function local_ga_dl_send_csv(string $filename, array $headers, array $rows): void {
    \core\session\manager::write_close();
    @set_time_limit(0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . clean_filename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

function local_ga_dl_count_workshop_rows(): int {
    global $DB;
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_workshops'))) {
        return 0;
    }
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_workshop_editions'))) {
        return $DB->count_records('local_ga_workshops');
    }
    return (int)$DB->get_field_sql("SELECT COUNT(1) FROM {local_ga_workshops} w LEFT JOIN {local_ga_workshop_editions} e ON e.workshopid = w.id");
}

function local_ga_dl_count_records_safe(string $tablename): int {
    global $DB;
    if (!$DB->get_manager()->table_exists(new xmldb_table($tablename))) {
        return 0;
    }
    return $DB->count_records($tablename);
}

/**
 * Optional series joins used to group reports by Edición de talleres. The
 * report keeps working on old installations while the formal migration runs.
 */
function local_ga_dl_series_sql(): stdClass {
    global $DB;
    $result = (object)[
        'joins' => '',
        'fields' => "NULL AS seriesid, '' AS seriestitle, 0 AS seriesdatefrom, 999999 AS seriessortorder",
    ];
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_series_items'))
            || !$DB->get_manager()->table_exists(new xmldb_table('local_ga_workshop_series'))) {
        return $result;
    }
    $result->joins = "LEFT JOIN {local_ga_series_items} si ON si.workshopid = w.id
                      LEFT JOIN {local_ga_workshop_series} ws ON ws.id = si.seriesid";
    $result->fields = "ws.id AS seriesid, ws.title AS seriestitle, ws.datefrom AS seriesdatefrom, si.sortorder AS seriessortorder";
    return $result;
}

function local_ga_dl_workshop_rows(): array {
    global $DB;
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_workshops'))) {
        return [];
    }
    $editionsql = $DB->get_manager()->table_exists(new xmldb_table('local_ga_workshop_editions'))
        ? "LEFT JOIN {local_ga_workshop_editions} e ON e.workshopid = w.id"
        : "";
    $editionfields = $editionsql !== ''
        ? "e.id AS editionid, e.name AS editionname, e.editioncode, e.sessiondate, e.enrolenddate, e.places, e.status AS editionstatus, e.archived"
        : "0 AS editionid, '' AS editionname, '' AS editioncode, 0 AS sessiondate, 0 AS enrolenddate, 0 AS places, '' AS editionstatus, 0 AS archived";
    $series = local_ga_dl_series_sql();
    $orderby = $editionsql !== ''
        ? 'COALESCE(ws.datefrom, e.sessiondate, 0) DESC, COALESCE(ws.id,0) DESC, COALESCE(si.sortorder,999999) ASC, e.sessiondate DESC, e.id DESC, w.code ASC'
        : 'w.code ASC';
    if ($series->joins === '') {
        $orderby = $editionsql !== '' ? 'e.sessiondate DESC, e.id DESC, w.code ASC' : 'w.code ASC';
    }
    $sql = "SELECT " . $DB->sql_concat('w.id', "'-'", ($editionsql !== '' ? 'COALESCE(e.id,0)' : "'0'")) . " AS uniqid,
                   w.id AS workshopid, w.code, w.name AS workshopname, w.hours, c.fullname AS coursename,
                   $editionfields, {$series->fields}
              FROM {local_ga_workshops} w
         LEFT JOIN {course} c ON c.id = w.courseid
              $editionsql
              {$series->joins}
          ORDER BY $orderby";
    return $DB->get_records_sql($sql);
}

function local_ga_dl_workshop_activity_rows(): array {
    global $DB;

    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_workshops'))) {
        return [];
    }

    $haseditions = $DB->get_manager()->table_exists(new xmldb_table('local_ga_workshop_editions'));
    $hasenrolments = $DB->get_manager()->table_exists(new xmldb_table('local_ga_edition_enrolments'));
    $hassubmissions = $DB->get_manager()->table_exists(new xmldb_table('local_ga_task_submissions'));

    if (!$haseditions || !$hasenrolments) {
        $rows = [];
        foreach (local_ga_dl_workshop_rows() as $r) {
            $r->userid = 0;
            $r->firstname = '';
            $r->lastname = '';
            $r->email = '';
            $r->attended = null;
            $r->tasksubmitted = null;
            $r->taskgrade = null;
            $r->taskstatus = '-';
            $rows[] = $r;
        }
        return $rows;
    }

    $submissionjoin = $hassubmissions
        ? "LEFT JOIN {local_ga_task_submissions} ts ON ts.editionid = e.id AND ts.userid = u.id AND ts.fileitemid > 0"
        : "";
    $submissionfield = $hassubmissions
        ? "CASE WHEN ts.id IS NULL THEN 0 ELSE 1 END AS tasksubmitted, ts.grade AS taskgrade"
        : "NULL AS tasksubmitted, NULL AS taskgrade";
    $series = local_ga_dl_series_sql();
    $order = $series->joins !== ''
        ? 'COALESCE(ws.datefrom, e.sessiondate, 0) DESC, COALESCE(ws.id,0) DESC, COALESCE(si.sortorder,999999) ASC, e.sessiondate DESC, e.id DESC, u.lastname ASC, u.firstname ASC'
        : 'e.sessiondate DESC, e.id DESC, w.code ASC, u.lastname ASC, u.firstname ASC';

    $sql = "SELECT " . $DB->sql_concat('w.id', "'-'", 'e.id', "'-'", 'COALESCE(u.id,0)') . " AS uniqid,
                   w.id AS workshopid,
                   w.code,
                   w.name AS workshopname,
                   w.hours,
                   w.workshoptype,
                   c.fullname AS coursename,
                   e.id AS editionid,
                   e.name AS editionname,
                   e.editioncode,
                   e.sessiondate,
                   e.enrolenddate,
                   e.places,
                   e.status AS editionstatus,
                   e.archived,
                   e.activitycreationtype,
                   u.id AS userid,
                   u.firstname,
                   u.lastname,
                   u.email,
                   ee.status AS enrolmentstatus,
                   ee.attended,
                   $submissionfield,
                   {$series->fields}
              FROM {local_ga_workshops} w
         LEFT JOIN {course} c ON c.id = w.courseid
         LEFT JOIN {local_ga_workshop_editions} e ON e.workshopid = w.id
         LEFT JOIN {local_ga_edition_enrolments} ee ON ee.editionid = e.id
         LEFT JOIN {user} u ON u.id = ee.userid AND u.deleted = 0
                   $submissionjoin
                   {$series->joins}
             WHERE (w.workshoptype = 'typea' OR w.workshoptype IS NULL OR w.workshoptype = '')
          ORDER BY $order";

    $rows = $DB->get_records_sql($sql);

    foreach (local_ga_dl_institutional_workshop_activity_rows() as $institutionalrow) {
        $rows['inst_' . (int)$institutionalrow->userid] = $institutionalrow;
    }

    return $rows;
}

function local_ga_dl_institutional_workshop_activity_rows(): array {
    global $DB;

    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_institutional_hours'))) {
        return [];
    }

    $sql = "SELECT ih.id,
                   ih.userid,
                   ih.email,
                   ih.fullname AS importfullname,
                   ih.courselevel,
                   ih.groupname,
                   ih.typeahours,
                   ih.taskgrade,
                   ih.source,
                   ih.timemodified,
                   u.firstname,
                   u.lastname,
                   u.email AS moodleemail
              FROM {local_ga_institutional_hours} ih
         LEFT JOIN {user} u ON u.id = ih.userid AND u.deleted = 0
             WHERE ih.userid > 0
               AND (ih.typeahours > 0 OR ih.taskgrade IS NOT NULL)
          ORDER BY ih.timemodified DESC, u.lastname ASC, u.firstname ASC, ih.id DESC";
    $records = $DB->get_records_sql($sql);
    $rows = [];
    foreach ($records as $r) {
        $row = new stdClass();
        $row->workshopid = 0;
        $row->code = 'RI';
        $row->workshopname = 'Reconocimiento institucional';
        $row->hours = round((float)($r->typeahours ?? 0), 2);
        $row->coursename = 'Reconocimiento institucional';
        $row->editionid = 0;
        $row->editionname = 'Reconocimiento institucional';
        $row->editioncode = 'RI';
        $row->sessiondate = (int)($r->timemodified ?? 0);
        $row->enrolenddate = 0;
        $row->places = '';
        $row->editionstatus = 'Reconocimiento institucional';
        $row->archived = 0;
        $row->activitycreationtype = 'assign';
        $row->userid = (int)$r->userid;
        $row->firstname = $r->firstname ?? '';
        $row->lastname = $r->lastname ?? '';
        $row->email = $r->moodleemail ?: ($r->email ?? '');
        $row->enrolmentstatus = 'recognized';
        $row->attended = 1;
        $row->tasksubmitted = 1;
        $row->taskgrade = ($r->taskgrade !== null && $r->taskgrade !== '') ? (float)$r->taskgrade : null;
        $row->seriesid = 0;
        $row->seriestitle = 'Reconocimiento institucional';
        $row->seriesdatefrom = (int)($r->timemodified ?? 0);
        $row->seriessortorder = 999999;
        $rows[] = $row;
    }
    return $rows;
}

function local_ga_dl_typea_rows(): array {
    global $DB;
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_certificates'))) {
        return [];
    }
    $series = local_ga_dl_series_sql();
    $order = $series->joins !== ''
        ? 'COALESCE(ws.datefrom, cert.timeissued, 0) DESC, COALESCE(ws.id,0) DESC, COALESCE(si.sortorder,999999) ASC, cert.timeissued DESC, u.lastname ASC, u.firstname ASC'
        : 'cert.timeissued DESC, u.lastname ASC, u.firstname ASC';
    $sql = "SELECT cert.id, cert.userid, cert.courseid, cert.workshopid, cert.editionid, cert.certcode, cert.filename,
                   cert.status, cert.timeissued,
                   u.firstname, u.lastname, u.email,
                   c.fullname AS coursename,
                   w.code AS workshopcode, w.name AS workshopname,
                   e.name AS editionname, e.editioncode,
                   {$series->fields}
              FROM {local_ga_certificates} cert
         LEFT JOIN {user} u ON u.id = cert.userid
         LEFT JOIN {course} c ON c.id = cert.courseid
         LEFT JOIN {local_ga_workshops} w ON w.id = cert.workshopid
         LEFT JOIN {local_ga_workshop_editions} e ON e.id = cert.editionid
                   {$series->joins}
             WHERE (cert.certificatetype = 'typea' OR cert.certificatetype IS NULL OR cert.certificatetype = '')
          ORDER BY $order";
    return $DB->get_records_sql($sql);
}

function local_ga_dl_typeb_rows(): array {
    global $DB;
    portfolio_typeb::ensure_table();
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_typeb_certs'))) {
        return [];
    }
    $sql = "SELECT b.*, u.firstname, u.lastname, u.email
              FROM {local_ga_typeb_certs} b
         LEFT JOIN {user} u ON u.id = b.userid
          ORDER BY b.activitydate DESC, b.timecreated DESC, u.lastname ASC, u.firstname ASC, b.id DESC";
    return $DB->get_records_sql($sql);
}

function local_ga_dl_internal_typeb_rows(): array {
    global $DB;
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ga_workshops'))
            || !$DB->get_manager()->table_exists(new xmldb_table('local_ga_workshop_editions'))) {
        return [];
    }
    $hascerts = $DB->get_manager()->table_exists(new xmldb_table('local_ga_certificates'));
    $certjoin = $hascerts ? "LEFT JOIN {local_ga_certificates} cert ON cert.editionid = e.id AND cert.userid = u.id AND cert.certificatetype = 'typeb'" : "";
    $certfields = $hascerts ? "cert.id AS certificateid, cert.status AS certificatestatus, cert.filename AS certificatefilename, cert.timeissued AS certificatetimeissued" : "NULL AS certificateid, NULL AS certificatestatus, NULL AS certificatefilename, NULL AS certificatetimeissued";
    $series = local_ga_dl_series_sql();
    $order = $series->joins !== ''
        ? 'COALESCE(ws.datefrom, e.sessiondate, 0) DESC, COALESCE(ws.id,0) DESC, COALESCE(si.sortorder,999999) ASC, e.sessiondate DESC, e.id DESC, u.lastname ASC, u.firstname ASC'
        : 'e.sessiondate DESC, e.id DESC, w.code ASC, u.lastname ASC, u.firstname ASC';
    $sql = "SELECT " . $DB->sql_concat('w.id', "'-'", 'e.id', "'-'", 'COALESCE(u.id,0)') . " AS uniqid,
                   w.id AS workshopid, w.code, w.name AS workshopname, w.hours,
                   c.fullname AS coursename, e.id AS editionid, e.name AS editionname, e.editioncode,
                   e.sessiondate, e.status AS editionstatus, ee.attended,
                   u.id AS userid, u.firstname, u.lastname, u.email,
                   $certfields,
                   {$series->fields}
              FROM {local_ga_workshops} w
         LEFT JOIN {course} c ON c.id = w.courseid
         LEFT JOIN {local_ga_workshop_editions} e ON e.workshopid = w.id
         LEFT JOIN {local_ga_edition_enrolments} ee ON ee.editionid = e.id
         LEFT JOIN {user} u ON u.id = ee.userid AND u.deleted = 0
                   $certjoin
                   {$series->joins}
             WHERE w.workshoptype = 'typeb'
          ORDER BY $order";
    $rows = $DB->get_records_sql($sql);
    foreach ($rows as $row) {
        $row->reflectionsubmitted = false;
        $row->reflectiontext = '';
        $row->reflectiontime = 0;
        if (!empty($row->editionid) && !empty($row->userid)) {
            try {
                $summary = typeb_reflection_activity::submission_summary((int)$row->editionid, (int)$row->userid);
                $row->reflectionsubmitted = !empty($summary->submitted);
                $row->reflectiontext = (string)($summary->text ?? '');
                $row->reflectiontime = (int)($summary->timemodified ?? 0);
            } catch (Throwable $e) {
                // Keep this report usable even if an old Moodle assignment is incomplete.
            }
        }
    }
    return $rows;
}

function local_ga_dl_userids_with_portfolio(): array {
    global $DB;
    $userids = [];
    if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_certificates'))) {
        $rows = $DB->get_records_sql("SELECT DISTINCT userid FROM {local_ga_certificates} WHERE userid > 0");
        foreach ($rows as $r) {
            $userids[(int)$r->userid] = true;
        }
    }
    portfolio_typeb::ensure_table();
    if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_typeb_certs'))) {
        $rows = $DB->get_records_sql("SELECT DISTINCT userid FROM {local_ga_typeb_certs} WHERE userid > 0");
        foreach ($rows as $r) {
            $userids[(int)$r->userid] = true;
        }
    }
    if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_institutional_hours'))) {
        $rows = $DB->get_records_sql("SELECT DISTINCT userid FROM {local_ga_institutional_hours} WHERE userid > 0");
        foreach ($rows as $r) {
            $userids[(int)$r->userid] = true;
        }
    }
    return array_keys($userids);
}

function local_ga_dl_add_typea_file(stdClass $cert, string $zipname, string $tempdir, array &$files): void {
    global $DB;
    $course = $DB->get_record('course', ['id' => (int)$cert->courseid], '*', IGNORE_MISSING);
    if (!$course) {
        return;
    }
    $coursecontext = context_course::instance((int)$course->id, IGNORE_MISSING);
    if (!$coursecontext) {
        return;
    }
    $fs = get_file_storage();
    $filename = (string)($cert->filename ?? '');
    $file = $filename !== '' ? $fs->get_file($coursecontext->id, 'local_gestion_actividades', 'certificate', (int)$cert->id, '/', $filename) : false;
    if (!$file || $file->is_directory()) {
        $area = $fs->get_area_files($coursecontext->id, 'local_gestion_actividades', 'certificate', (int)$cert->id, 'filename', false);
        foreach ($area as $candidate) {
            if (!$candidate->is_directory()) {
                $file = $candidate;
                break;
            }
        }
    }
    if (!$file || $file->is_directory()) {
        return;
    }
    $path = $tempdir . '/typea_' . (int)$cert->id . '.pdf';
    $file->copy_content_to($path);
    $files[$zipname] = $path;
}

function local_ga_dl_add_typeb_file(stdClass $cert, string $zipname, string $tempdir, array &$files): void {
    $context = context_system::instance();
    $fs = get_file_storage();
    $filename = (string)($cert->filename ?? '');
    $file = $filename !== '' ? $fs->get_file($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$cert->id, '/', $filename) : false;
    if (!$file || $file->is_directory()) {
        $area = $fs->get_area_files($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$cert->id, 'filename', false);
        foreach ($area as $candidate) {
            if (!$candidate->is_directory()) {
                $file = $candidate;
                break;
            }
        }
    }
    if (!$file || $file->is_directory()) {
        return;
    }
    $path = $tempdir . '/typeb_' . (int)$cert->id . '.pdf';
    $file->copy_content_to($path);
    $files[$zipname] = $path;
}

function local_ga_dl_send_zip(array $files, string $zipname, string $emptyredirect): void {
    if (!$files) {
        redirect(new moodle_url($emptyredirect), 'No hay archivos para descargar.', null, \core\output\notification::NOTIFY_INFO);
    }
    $packer = get_file_packer('application/zip');
    $tempdir = make_request_directory();
    $zippath = $tempdir . '/' . clean_filename($zipname);
    $packer->archive_to_pathname($files, $zippath);
    send_temp_file($zippath, clean_filename($zipname));
}

function local_ga_dl_nav_button(string $url, string $label, string $class = 'btn btn-outline-secondary'): string {
    return html_writer::link(new moodle_url($url), local_ga_btn_icon('t/left', $label), ['class' => $class . ' mr-2 mb-2']);
}

function local_ga_dl_action_url(string $action, bool $includesesskey = false): moodle_url {
    $params = ['action' => $action];
    if ($includesesskey) {
        $params['sesskey'] = sesskey();
    }
    return new moodle_url('/local/gestion_actividades/manager_downloads.php', $params);
}

function local_ga_dl_empty_notice(string $text): string {
    global $OUTPUT;
    return $OUTPUT->notification($text, 'info');
}

function local_ga_dl_render_table(array $headers, array $rows): string {
    if (!$rows) {
        return local_ga_dl_empty_notice('No hay registros para mostrar.');
    }
    $table = new html_table();
    $table->head = $headers;
    $table->data = $rows;
    $table->attributes['class'] = 'generaltable table table-striped table-bordered';
    return html_writer::table($table);
}

function local_ga_dl_render_card(string $title, string $badge, string $description, array $buttons): string {
    $out = html_writer::start_tag('div', ['class' => 'card shadow-sm mb-2', 'style' => 'height:auto;']);
    $out .= html_writer::start_tag('div', ['class' => 'card-body', 'style' => 'padding:.75rem .9rem;']);
    $out .= html_writer::tag('h3', $title, ['class' => 'h5 mb-2']);
    $out .= html_writer::tag('div', $badge, ['class' => 'badge badge-light mb-1', 'style' => 'font-size:0.85rem;padding:5px 8px;']);
    $out .= html_writer::tag('p', $description, ['class' => 'text-muted mb-2']);
    foreach ($buttons as $buttonhtml) {
        $out .= $buttonhtml;
    }
    $out .= html_writer::end_div();
    $out .= html_writer::end_div();
    return $out;
}

$downloadactions = ['workshops_csv', 'typeb_workshops_csv', 'typea_csv', 'typeb_csv', 'transfers_csv', 'hours_csv', 'portfolios_csv', 'typea_zip', 'typeb_zip', 'packages_zip'];
if (in_array($action, $downloadactions, true)) {
    require_sesskey();

    if ($action === 'workshops_csv') {
        $rows = [];
        foreach (local_ga_dl_workshop_activity_rows() as $r) {
            $hasTask = strpos((string)($r->activitycreationtype ?? ''), 'assign') !== false || strpos((string)($r->activitycreationtype ?? ''), 'tarea') !== false;
            $taskvalue = !$hasTask ? 'No procede' : ((int)($r->tasksubmitted ?? 0) === 1 ? 'Sí' : 'No');
            $taskgradevalue = (!$hasTask || (int)($r->tasksubmitted ?? 0) !== 1 || $r->taskgrade === null || $r->taskgrade === '') ? '' : (string)format_float((float)$r->taskgrade, 2, true);
            $taskresultvalue = !$hasTask ? 'No procede' : ((int)($r->tasksubmitted ?? 0) !== 1 ? 'Pendiente entrega' : ($taskgradevalue === '' ? 'Pendiente nota' : ((float)$r->taskgrade >= 5.0 ? 'Apto' : 'No apto')));
            $attendancevalue = $r->userid ? (!empty($r->attended) ? 'Sí' : 'No') : '-';
            $rows[] = [
                $r->seriestitle ?? '',
                $r->coursename ?? '',
                $r->workshopid,
                $r->code,
                $r->workshopname,
                isset($r->hours) ? (string)$r->hours : '',
                $r->editionid ?: '',
                $r->editioncode ?: '',
                $r->editionname ?: '',
                !empty($r->sessiondate) ? userdate((int)$r->sessiondate, '%Y-%m-%d %H:%M') : '',
                !empty($r->enrolenddate) ? userdate((int)$r->enrolenddate, '%Y-%m-%d %H:%M') : '',
                $r->places ?: '',
                $r->editionstatus ?: '',
                !empty($r->archived) ? 'Sí' : 'No',
                $r->userid ? fullname($r) : '-',
                $r->email ?? '',
                $attendancevalue,
                $taskvalue,
                $taskgradevalue,
                $taskresultvalue,
            ];
        }
        local_ga_dl_send_csv('listado_talleres_tipo_a.csv', ['Edición de talleres', 'Curso', 'ID taller', 'Código taller', 'Taller', 'Horas', 'ID edición', 'Código edición', 'Edición', 'Fecha taller', 'Fin inscripción', 'Plazas', 'Estado', 'Archivado', 'Alumno', 'Email', 'Asistencia', 'Tarea entregada', 'Nota tarea', 'Resultado tarea'], $rows);
    }

    if ($action === 'typeb_workshops_csv') {
        $rows = [];
        foreach (local_ga_dl_internal_typeb_rows() as $r) {
            $submitted = !empty($r->reflectionsubmitted);
            $rows[] = [
                $r->seriestitle ?? '', $r->coursename ?? '', $r->code ?? '', $r->workshopname ?? '', round((float)($r->hours ?? 0), 2),
                $r->editioncode ?? '', $r->editionname ?? '', !empty($r->sessiondate) ? userdate((int)$r->sessiondate, '%Y-%m-%d %H:%M') : '',
                $r->userid ? fullname($r) : '-', $r->email ?? '', !empty($r->attended) ? 'Asiste' : 'No asiste',
                $submitted ? 'Entregado' : 'Pendiente', trim(strip_tags((string)($r->reflectiontext ?? '')))
            ];
        }
        local_ga_dl_send_csv('listado_talleres_tipo_b.csv', ['Edición de talleres', 'Curso', 'Código taller', 'Taller', 'Horas', 'Código edición', 'Edición', 'Fecha taller', 'Alumno', 'Email', 'Asistencia', 'Reflexión', 'Texto en línea'], $rows);
    }

    if ($action === 'typea_csv') {
        $rows = [];
        foreach (local_ga_dl_typea_rows() as $c) {
            $rows[] = [
                $c->seriestitle ?? '', fullname($c), $c->email ?? '', $c->coursename ?? '', $c->workshopcode ?? '', $c->workshopname ?? '',
                $c->editioncode ?? '', $c->editionname ?? '', $c->certcode ?? '', $c->status ?? '',
                !empty($c->timeissued) ? userdate((int)$c->timeissued, '%Y-%m-%d %H:%M') : '', $c->filename ?? '',
            ];
        }
        local_ga_dl_send_csv('listado_certificados_tipo_a.csv', ['Edición de talleres', 'Alumno', 'Email', 'Curso', 'Código taller', 'Taller', 'Código edición', 'Edición', 'Código certificado', 'Estado', 'Fecha emisión', 'Archivo'], $rows);
    }

    if ($action === 'transfers_csv') {
        $rows = [];
        foreach (manager::list_all_typeb_transfers() as $r) {
            $rows[] = [
                fullname($r),
                $r->email ?? '',
                trim((string)($r->workshopcode ?? '') . ' - ' . (string)($r->workshopname ?? '')),
                round((float)($r->hours ?? 0), 2),
                format_text((string)($r->reflectiontext ?? ''), FORMAT_PLAIN),
                !empty($r->timecreated) ? userdate((int)$r->timecreated) : '',
            ];
        }
        local_ga_dl_send_csv('traspasos_tipoa_tipob.csv', ['Alumno', 'Email', 'Taller A traspasado', 'Horas', 'Texto obligatorio', 'Fecha traspaso'], $rows);
    }

    if ($action === 'typeb_csv') {
        $rows = [];
        foreach (local_ga_dl_typeb_rows() as $c) {
            $rows[] = [
                fullname($c), $c->email ?? '', $c->activityname ?? '',
                !empty($c->activitydate) ? userdate((int)$c->activitydate, '%Y-%m-%d') : '',
                isset($c->hours) ? (string)$c->hours : '', $c->activitydescription ?? '', $c->status ?? '', !empty($c->authorizedconfirm) ? 'Sí' : 'No',
                trim((string)($c->reflectiontext ?? '')) !== '' ? 'Sí' : 'No',
                $c->reviewcomment ?? '', !empty($c->timecreated) ? userdate((int)$c->timecreated, '%Y-%m-%d %H:%M') : '', $c->filename ?? '',
            ];
        }
        local_ga_dl_send_csv('listado_certificados_tipo_b.csv', ['Alumno', 'Email', 'Actividad', 'Fecha actividad', 'Horas', 'Texto justificativo', 'Estado', 'Declaración normativa', 'Reflexión completada', 'Comentario revisión', 'Fecha subida', 'Archivo'], $rows);
    }

    if ($action === 'hours_csv') {
        $rows = [];
        foreach (manager::get_hours_summary_by_student() as $r) {
            $rows[] = [
                fullname($r),
                $r->email ?? '',
                local_ga_dl_student_group((int)$r->id),
                (int)($r->completedworkshops ?? 0),
                round((float)($r->totaltypeahours ?? 0), 2),
                (int)($r->validatedtypebcount ?? 0),
                round((float)($r->totaltypebhours ?? 0), 2),
                round((float)($r->totalhours ?? 0), 2),
                max(0, round(54 - (float)($r->totalhours ?? 0), 2)),
            ];
        }
        local_ga_dl_send_csv('listado_horas_alumnos.csv', ['Alumno', 'Email', 'Grupo', 'Talleres Tipo A completados', 'Horas Tipo A', 'Tipo B validados', 'Horas Tipo B', 'Total reconocido', 'Pendiente hasta 54'], $rows);
    }

    if ($action === 'portfolios_csv') {
        global $DB;
        $rows = [];
        foreach (local_ga_dl_userids_with_portfolio() as $userid) {
            $user = $DB->get_record('user', ['id' => (int)$userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$user) {
                continue;
            }
            $summaryrow = $hourssummarybyuser[(int)$userid] ?? null;
            $typeacerts = method_exists(manager::class, 'list_user_certificates') ? manager::list_user_certificates((int)$userid) : [];
            $typeahours = (float)($summaryrow->totaltypeahours ?? 0.0);
            $typebhours = (float)($summaryrow->totaltypebhours ?? 0.0);
            $total = (float)($summaryrow->totalhours ?? ($typeahours + $typebhours));
            $rows[] = [fullname($user), $user->email ?? '', local_ga_dl_student_group((int)$userid), count($typeacerts), round($typeahours, 2), round($typebhours, 2), round($total, 2), max(0, round(54 - $total, 2))];
        }
        local_ga_dl_send_csv('listado_portafolios.csv', ['Alumno', 'Email', 'Grupo', 'Certificados Tipo A', 'Horas Tipo A', 'Horas Tipo B', 'Total reconocido', 'Horas pendientes hasta 54'], $rows);
    }

    if ($action === 'typea_zip') {
        $tempdir = make_request_directory();
        $files = [];
        $n = 1;
        foreach (local_ga_dl_typea_rows() as $c) {
            $date = !empty($c->timeissued) ? userdate((int)$c->timeissued, '%Y%m%d') : 'sin_fecha';
            $student = local_ga_dl_clean(fullname($c), 'alumno');
            $title = local_ga_dl_clean(($c->workshopcode ?? 'tipo_a') . '_' . ($c->workshopname ?? 'certificado'), 'certificado');
            $zipname = sprintf('Certificados_Tipo_A/%03d_%s_%s_%s.pdf', $n++, $date, $student, $title);
            local_ga_dl_add_typea_file($c, $zipname, $tempdir, $files);
        }
        local_ga_dl_send_zip($files, 'certificados_tipo_a_' . date('Ymd_His') . '.zip', '/local/gestion_actividades/manager_downloads.php');
    }

    if ($action === 'typeb_zip') {
        $tempdir = make_request_directory();
        $files = [];
        $n = 1;
        foreach (local_ga_dl_typeb_rows() as $c) {
            if (!portfolio_typeb::is_countable($c)) {
                continue;
            }
            $date = !empty($c->activitydate) ? userdate((int)$c->activitydate, '%Y%m%d') : 'sin_fecha';
            $student = local_ga_dl_clean(fullname($c), 'alumno');
            $title = local_ga_dl_clean($c->activityname ?? 'certificado_tipo_b', 'certificado_tipo_b');
            $zipname = sprintf('Certificados_Tipo_B/%03d_%s_%s_%s.pdf', $n++, $date, $student, $title);
            local_ga_dl_add_typeb_file($c, $zipname, $tempdir, $files);
        }
        local_ga_dl_send_zip($files, 'certificados_tipo_b_validados_' . date('Ymd_His') . '.zip', '/local/gestion_actividades/manager_downloads.php');
    }

    if ($action === 'packages_zip') {
        global $DB;
        $tempdir = make_request_directory();
        $files = [];
        foreach (local_ga_dl_userids_with_portfolio() as $userid) {
            $user = $DB->get_record('user', ['id' => (int)$userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$user) {
                continue;
            }
            $folder = local_ga_dl_clean(fullname($user), 'alumno_' . (int)$userid);
            $pdf = portfolio_pdf::render_pdf_string((int)$userid);
            $mainpath = $tempdir . '/portfolio_' . (int)$userid . '.pdf';
            file_put_contents($mainpath, $pdf);
            $files[$folder . '/00_portafolio_' . $folder . '.pdf'] = $mainpath;

            $typeacerts = method_exists(manager::class, 'list_user_certificates') ? manager::list_user_certificates((int)$userid) : [];
            usort($typeacerts, function($a, $b) {
                return ((int)($b->timeissued ?? 0)) <=> ((int)($a->timeissued ?? 0));
            });
            $n = 1;
            foreach ($typeacerts as $c) {
                $date = !empty($c->timeissued) ? userdate((int)$c->timeissued, '%Y%m%d') : 'sin_fecha';
                $title = local_ga_dl_clean(($c->workshopcode ?? 'tipo_a') . '_' . ($c->workshopname ?? 'certificado'), 'certificado_tipo_a');
                local_ga_dl_add_typea_file($c, sprintf('%s/01_Tipo_A/%02d_%s_%s.pdf', $folder, $n++, $date, $title), $tempdir, $files);
            }

            $typebcerts = array_values(array_filter(portfolio_typeb::list_for_user((int)$userid), static function($cert): bool {
                return portfolio_typeb::is_countable($cert);
            }));
            usort($typebcerts, function($a, $b) {
                return ((int)($b->activitydate ?? 0)) <=> ((int)($a->activitydate ?? 0));
            });
            $n = 1;
            foreach ($typebcerts as $c) {
                $date = !empty($c->activitydate) ? userdate((int)$c->activitydate, '%Y%m%d') : 'sin_fecha';
                $title = local_ga_dl_clean($c->activityname ?? 'certificado_tipo_b', 'certificado_tipo_b');
                local_ga_dl_add_typeb_file($c, sprintf('%s/02_Tipo_B/%02d_%s_%s.pdf', $folder, $n++, $date, $title), $tempdir, $files);
            }
        }
        local_ga_dl_send_zip($files, 'expedientes_completos_' . date('Ymd_His') . '.zip', '/local/gestion_actividades/manager_downloads.php');
    }
}

$workshopcount = local_ga_dl_count_workshop_rows();
$typeacount = local_ga_dl_count_records_safe('local_ga_certificates');
$typebcount = local_ga_dl_count_records_safe('local_ga_typeb_certs');
$hoursrows = manager::get_hours_summary_by_student();
$hourssummarybyuser = [];
foreach ($hoursrows as $hoursrow) {
    $hourssummarybyuser[(int)$hoursrow->id] = $hoursrow;
}
$hoursstudentcount = count($hoursrows);
$hourstotal = 0.0;
foreach ($hoursrows as $hrow) {
    $hourstotal += (float)($hrow->totalhours ?? 0);
}
$portfolioids = local_ga_dl_userids_with_portfolio();

$viewactions = ['view_workshops', 'view_typeb_workshops', 'view_typea', 'view_typeb', 'view_transfers', 'view_hours', 'view_portfolios'];
$viewmode = in_array($action, $viewactions, true) ? $action : '';

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/gestion_actividades/manager_downloads.php', $viewmode ? ['action' => $viewmode] : []));
$PAGE->set_title('Listados y descargas');
$PAGE->set_heading('Gestión HEE');

echo $OUTPUT->header();

if ($viewmode !== '') {
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), local_ga_btn_icon('t/left', 'Volver al panel'), ['class' => 'btn btn-outline-secondary mr-2 mb-3']) .
        html_writer::link(new moodle_url('/local/gestion_actividades/manager_downloads.php'), local_ga_btn_icon('t/left', 'Volver a listados'), ['class' => 'btn btn-outline-secondary mb-3']),
        'mb-2'
    );

    if ($viewmode === 'view_workshops') {
        echo html_writer::tag('h2', 'Listado de Talleres Tipo A');
        echo html_writer::tag('p', 'Consulta en pantalla los talleres y ediciones existentes. Se muestran agrupados por Edición de talleres y con las fechas más recientes primero.', ['class' => 'text-muted']);
        echo html_writer::div(
            html_writer::link(local_ga_dl_action_url('workshops_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-3']),
            'mb-2'
        );
        $rows = [];
        foreach (local_ga_dl_workshop_activity_rows() as $r) {
            $hasTask = strpos((string)($r->activitycreationtype ?? ''), 'assign') !== false || strpos((string)($r->activitycreationtype ?? ''), 'tarea') !== false;
            $taskvalue = !$hasTask
                ? html_writer::span('No procede', 'badge badge-secondary')
                : ((int)($r->tasksubmitted ?? 0) === 1 ? html_writer::span('Entregada', 'badge badge-success') : html_writer::span('No entregada', 'badge badge-warning'));
            $taskgradevalue = (!$hasTask || (int)($r->tasksubmitted ?? 0) !== 1 || $r->taskgrade === null || $r->taskgrade === '') ? '-' : format_float((float)$r->taskgrade, 2, true);
            if (!$hasTask) {
                $taskresultvalue = html_writer::span('No procede', 'badge badge-secondary');
            } else if ((int)($r->tasksubmitted ?? 0) !== 1) {
                $taskresultvalue = html_writer::span('Pendiente entrega', 'badge badge-warning');
            } else if ($taskgradevalue === '-') {
                $taskresultvalue = html_writer::span('Pendiente nota', 'badge badge-warning');
            } else if ((float)$r->taskgrade >= 5.0) {
                $taskresultvalue = html_writer::span('Apto', 'badge badge-success');
            } else {
                $taskresultvalue = html_writer::span('No apto', 'badge badge-danger');
            }
            $attendancevalue = $r->userid
                ? (!empty($r->attended) ? html_writer::span('Asiste', 'badge badge-success') : html_writer::span('No asiste', 'badge badge-warning'))
                : '-';

            $rows[] = [
                s($r->seriestitle ?: '-'),
                s($r->coursename ?? ''),
                s($r->code ?? ''),
                s($r->workshopname ?? ''),
                format_float((float)($r->hours ?? 0), 2, true) . ' h',
                s($r->editionname ?: '-'),
                !empty($r->sessiondate) ? userdate((int)$r->sessiondate, '%d/%m/%Y %H:%M') : '-',
                s($r->editionstatus ?: '-'),
                !empty($r->archived) ? 'Sí' : 'No',
                $r->userid ? s(fullname($r)) : '-',
                s($r->email ?? ''),
                $r->userid ? s(local_ga_dl_student_group((int)$r->userid)) : '-',
                $attendancevalue,
                $taskvalue,
                $taskgradevalue,
                $taskresultvalue,
            ];
        }
        echo local_ga_dl_render_table(['Edición de talleres', 'Curso', 'Código', 'Taller', 'Horas', 'Edición', 'Fecha taller', 'Estado', 'Archivado', 'Alumno', 'Email', 'Grupo', 'Asistencia', 'Tarea entregada', 'Nota tarea', 'Resultado tarea'], $rows);
    }

    if ($viewmode === 'view_typeb_workshops') {
        echo html_writer::tag('h2', 'Listado de Talleres Tipo B');
        echo html_writer::tag('p', 'Consulta los Talleres Tipo B. La entrega de reflexión se obtiene de la Tarea Moodle canónica, no de la antigua tabla auxiliar.', ['class' => 'text-muted']);
        echo html_writer::div(html_writer::link(local_ga_dl_action_url('typeb_workshops_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-3']), 'mb-2');
        $rows = [];
        foreach (local_ga_dl_internal_typeb_rows() as $r) {
            $submitted = !empty($r->reflectionsubmitted);
            $text = trim(strip_tags((string)($r->reflectiontext ?? '')));
            $rows[] = [
                s($r->seriestitle ?: '-'), s($r->coursename ?? ''), s($r->code ?? ''), s($r->workshopname ?? ''), format_float((float)($r->hours ?? 0), 2, true) . ' h',
                s($r->editionname ?? '-'), !empty($r->sessiondate) ? userdate((int)$r->sessiondate, '%d/%m/%Y %H:%M') : '-',
                $r->userid ? s(fullname($r)) : '-', s($r->email ?? ''),
                !empty($r->attended) ? html_writer::span('Confirmada', 'badge badge-success') : html_writer::span('No confirmada', 'badge badge-warning'),
                $submitted ? html_writer::span('Entregada', 'badge badge-success') : html_writer::span('Pendiente', 'badge badge-warning'),
                $text !== '' ? s(\core_text::substr($text, 0, 220)) : ($submitted ? 'Entrega mediante archivo' : '-'),
                !empty($r->certificateid) ? html_writer::span('Generado', 'badge badge-success') : html_writer::span('Pendiente', 'badge badge-warning'),
                !empty($r->certificatetimeissued) ? userdate((int)$r->certificatetimeissued, '%d/%m/%Y %H:%M') : '-',
                '-',
            ];
        }
        foreach (local_ga_dl_typeb_rows() as $r) {
            $status = (string)($r->status ?? 'pending');
            $hasreflection = trim((string)($r->reflectiontext ?? '')) !== '';
            $confirm = html_writer::span($status === 'validated' ? 'Validado' : 'Pendiente', $status === 'validated' ? 'badge badge-success' : 'badge badge-warning');
            if ($status === 'pending') {
                $confirm = html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/gestion_actividades/typeb_review.php'), 'class' => 'm-0']);
                $confirm .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'id','value'=>(int)$r->id]);
                $confirm .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>'validate']);
                $confirm .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
                $confirm .= html_writer::tag('label', html_writer::empty_tag('input', ['type'=>'checkbox','name'=>'confirm','value'=>'1','required'=>'required']) . ' Por confirmar', ['class'=>'d-block mb-1']);
                $confirm .= html_writer::tag('button', 'Confirmar', ['type'=>'submit','class'=>'btn btn-success btn-sm']);
                $confirm .= html_writer::end_tag('form');
            } else if ($status === 'rejected') {
                $confirm = html_writer::span('Rechazado', 'badge badge-danger');
            } else if ($status === portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION) {
                $confirm = html_writer::span('Validado · falta reflexión', 'badge badge-warning');
            }
            $evidence = html_writer::link(new moodle_url('/local/gestion_actividades/typeb_download.php', ['id'=>(int)$r->id]), local_ga_btn_icon('t/download', 'Ver evidencia'), ['class'=>'btn btn-secondary btn-sm']);
            $rows[] = [
                'Externo', 'Externo', '-', s($r->activityname ?? ''), format_float((float)($r->hours ?? 0), 2, true) . ' h',
                'Subido por el alumno', !empty($r->activitydate) ? userdate((int)$r->activitydate, '%d/%m/%Y') : '-',
                s(fullname($r)), s($r->email ?? ''), html_writer::span('No procede', 'badge badge-secondary'),
                $hasreflection ? html_writer::span('Entregada', 'badge badge-success') : html_writer::span('Pendiente', 'badge badge-warning'),
                $hasreflection ? s(\core_text::substr((string)$r->reflectiontext, 0, 220)) : '-',
                $status === 'validated' ? html_writer::span('Reconocido', 'badge badge-success') : html_writer::span('No computa', 'badge badge-warning'),
                !empty($r->timereviewed) ? userdate((int)$r->timereviewed, '%d/%m/%Y %H:%M') : '-',
                $evidence . html_writer::div($confirm, 'mt-1'),
            ];
        }
        echo local_ga_dl_render_table(['Edición de talleres/origen', 'Curso/origen', 'Código', 'Taller', 'Horas', 'Edición/origen', 'Fecha taller', 'Alumno', 'Email', 'Asistencia', 'Reflexión', 'Contenido', 'Reconocimiento', 'Fecha revisión', 'Acciones'], $rows);
    }

    if ($viewmode === 'view_typea') {
        echo html_writer::tag('h2', 'Listado de Certificados Tipo A');
        echo html_writer::tag('p', 'Consulta los certificados Tipo A generados por el sistema y descarga cada PDF individual si lo necesitas.', ['class' => 'text-muted']);
        echo html_writer::div(
            html_writer::link(local_ga_dl_action_url('typea_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mr-2 mb-3']) .
            html_writer::link(local_ga_dl_action_url('typea_zip', true), local_ga_btn_icon('t/download', 'Descargar PDFs ZIP'), ['class' => 'btn btn-secondary mb-3']),
            'mb-2'
        );
        $rows = [];
        foreach (local_ga_dl_typea_rows() as $c) {
            $actions = html_writer::link(new moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => $c->id]), local_ga_btn_icon('t/download', 'Descargar'), ['class' => 'btn btn-secondary btn-sm']);
            $rows[] = [
                s($c->seriestitle ?: '-'),
                s(fullname($c)),
                s($c->email ?? ''),
                s(local_ga_dl_student_group((int)$c->userid)),
                s($c->coursename ?? ''),
                s(trim(($c->workshopcode ?? '') . ' - ' . ($c->workshopname ?? ''), ' -')),
                s($c->editionname ?? '-'),
                !empty($c->timeissued) ? userdate((int)$c->timeissued, '%d/%m/%Y %H:%M') : '-',
                s($c->status ?? ''),
                s($c->filename ?? ''),
                $actions,
            ];
        }
        echo local_ga_dl_render_table(['Edición de talleres', 'Alumno', 'Email', 'Grupo', 'Curso', 'Taller', 'Edición', 'Fecha emisión', 'Estado', 'Archivo', 'Acciones'], $rows);
    }

    if ($viewmode === 'view_transfers') {
        echo html_writer::tag('h2', 'Traspasos Tipo A a Tipo B');
        echo html_writer::tag('p', 'Listado de traspasos realizados por el alumnado. Las horas traspasadas dejan de contar como Tipo A y pasan a contar como Tipo B.', ['class' => 'text-muted']);
        echo html_writer::div(html_writer::link(local_ga_dl_action_url('transfers_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-3']), 'mb-2');
        $rows = [];
        foreach (manager::list_all_typeb_transfers() as $r) {
            $rows[] = [
                fullname($r),
                s($r->email ?? ''),
                s(local_ga_dl_student_group((int)$r->userid)),
                s(trim((string)($r->workshopcode ?? '') . ' - ' . (string)($r->workshopname ?? ''))),
                format_float((float)($r->hours ?? 0), 2, true) . ' h',
                format_text((string)($r->reflectiontext ?? ''), FORMAT_PLAIN),
                !empty($r->timecreated) ? userdate((int)$r->timecreated) : '-',
            ];
        }
        echo local_ga_dl_render_table(['Alumno', 'Email', 'Grupo', 'Taller A traspasado', 'Horas', 'Texto obligatorio', 'Fecha traspaso'], $rows);
        if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
            local_gestion_actividades_enable_interactive_tables();
        }
        echo $OUTPUT->footer();
        exit;
    }

    if ($viewmode === 'view_typeb') {
        echo html_writer::tag('h2', 'Listado de Certificados Tipo B externos');
        echo html_writer::tag('p', 'El listado administrativo muestra todos los estados. El ZIP incluye exclusivamente evidencias validadas cuya reflexión obligatoria ya está completada.', ['class' => 'text-muted']);
        echo html_writer::div(
            html_writer::link(local_ga_dl_action_url('typeb_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mr-2 mb-3']) .
            html_writer::link(local_ga_dl_action_url('typeb_zip', true), local_ga_btn_icon('t/download', 'Descargar PDFs validados ZIP'), ['class' => 'btn btn-secondary mb-3']),
            'mb-2'
        );
        $rows = [];
        foreach (local_ga_dl_typeb_rows() as $c) {
            $actions = html_writer::link(new moodle_url('/local/gestion_actividades/typeb_download.php', ['id' => $c->id]), local_ga_btn_icon('t/download', 'Descargar PDF'), ['class' => 'btn btn-secondary btn-sm']);
            $rows[] = [
                s(fullname($c)),
                s($c->email ?? ''),
                s(local_ga_dl_student_group((int)$c->userid)),
                s($c->activityname ?? ''),
                !empty($c->activitydate) ? userdate((int)$c->activitydate, '%d/%m/%Y') : '-',
                format_float((float)($c->hours ?? 0), 2, true) . ' h',
                s($c->status ?? ''),
                !empty($c->authorizedconfirm) ? 'Confirmada' : 'No',
                trim((string)($c->reflectiontext ?? '')) !== '' ? 'Sí' : 'No',
                s($c->reviewcomment ?? '-'),
                $actions,
            ];
        }
        echo local_ga_dl_render_table(['Alumno', 'Email', 'Grupo', 'Actividad', 'Fecha', 'Horas', 'Estado', 'Normativa', 'Reflexión', 'Comentario', 'Acciones'], $rows);
    }

    if ($viewmode === 'view_hours') {
        echo html_writer::tag('h2', 'Listado de horas de alumnos');
        echo html_writer::tag('p', 'Consulta en pantalla las horas reconocidas por alumno, separando Talleres Tipo A y Tipo B validados.', ['class' => 'text-muted']);
        echo html_writer::div(
            html_writer::link(local_ga_dl_action_url('hours_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-3']),
            'mb-2'
        );
        $rows = [];
        foreach ($hoursrows as $r) {
            $rows[] = [
                s(fullname($r)),
                s($r->email ?? ''),
                s(local_ga_dl_student_group((int)$r->id)),
                (int)($r->completedworkshops ?? 0),
                format_float((float)($r->totaltypeahours ?? 0), 2, true) . ' h',
                (int)($r->validatedtypebcount ?? 0),
                format_float((float)($r->totaltypebhours ?? 0), 2, true) . ' h',
                format_float((float)($r->totalhours ?? 0), 2, true) . ' h',
                format_float(max(0, 54 - (float)($r->totalhours ?? 0)), 2, true) . ' h',
            ];
        }
        echo local_ga_dl_render_table(['Alumno', 'Email', 'Grupo', 'Talleres A', 'Horas A', 'Tipo B validados', 'Horas B', 'Total reconocido', 'Pendiente hasta 54'], $rows);
    }

    if ($viewmode === 'view_portfolios') {
        global $DB;
        echo html_writer::tag('h2', 'Listado de portafolios');
        echo html_writer::tag('p', 'Consulta en pantalla los portafolios disponibles y descarga el PDF individual de cada alumno o los paquetes masivos.', ['class' => 'text-muted']);
        echo html_writer::div(
            html_writer::link(local_ga_dl_action_url('portfolios_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mr-2 mb-3']) .
            html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_pdf_all.php', ['sesskey' => sesskey()]), local_ga_btn_icon('t/download', 'Descargar portafolios PDF ZIP'), ['class' => 'btn btn-primary mr-2 mb-3']) .
            html_writer::link(local_ga_dl_action_url('packages_zip', true), local_ga_btn_icon('t/download', 'Descargar expedientes completos ZIP'), ['class' => 'btn btn-secondary mb-3']),
            'mb-2'
        );
        $rows = [];
        foreach ($portfolioids as $userid) {
            $user = $DB->get_record('user', ['id' => (int)$userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$user) {
                continue;
            }
            $summaryrow = $hourssummarybyuser[(int)$userid] ?? null;
            $typeacerts = method_exists(manager::class, 'list_user_certificates') ? manager::list_user_certificates((int)$userid) : [];
            $typeahours = (float)($summaryrow->totaltypeahours ?? 0.0);
            $typebhours = (float)($summaryrow->totaltypebhours ?? 0.0);
            $total = (float)($summaryrow->totalhours ?? ($typeahours + $typebhours));
            $actions = html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_pdf_download.php', ['userid' => (int)$userid]), local_ga_btn_icon('t/download', 'Descargar PDF'), ['class' => 'btn btn-secondary btn-sm']);
            $rows[] = [
                s(fullname($user)),
                s($user->email ?? ''),
                s(local_ga_dl_student_group((int)$userid)),
                count($typeacerts),
                format_float($typeahours, 2, true) . ' h',
                format_float($typebhours, 2, true) . ' h',
                format_float($total, 2, true) . ' h',
                format_float(max(0, 54 - $total), 2, true) . ' h',
                $actions,
            ];
        }
        echo local_ga_dl_render_table(['Alumno', 'Email', 'Grupo', 'Certificados A', 'Horas A', 'Horas B', 'Total reconocido', 'Pendiente hasta 54', 'Acciones'], $rows);
    }

    if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
        local_gestion_actividades_enable_interactive_tables();
    }
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(html_writer::link(new moodle_url('/local/gestion_actividades/dashboard.php'), local_ga_btn_icon('t/left', 'Volver al panel'), ['class' => 'btn btn-outline-secondary mb-3']), 'mb-2');
echo html_writer::tag('h2', 'Listados y descargas');
echo html_writer::tag('p', 'Acceso centralizado a todos los listados y descargas: talleres, certificados Tipo A, certificados Tipo B y portafolios.', ['class' => 'lead']);

$leftcards = [
    local_ga_dl_render_card(
        'Talleres Tipo A',
        $workshopcount . ' fila(s)',
        'Listado completo de talleres y ediciones, agrupado por Edición de talleres.',
        [
            html_writer::link(local_ga_dl_action_url('view_workshops'), local_ga_btn_icon('i/search', 'Ver listado'), ['class' => 'btn btn-outline-secondary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('workshops_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-1']),
        ]
    ),
    local_ga_dl_render_card(
        'Traspasos A→B',
        count(manager::list_all_typeb_transfers()) . ' traspaso(s)',
        'Listado de traspasos de Talleres Tipo A a Tipo B realizados por alumnado.',
        [
            html_writer::link(local_ga_dl_action_url('view_transfers'), local_ga_btn_icon('i/search', 'Ver listado'), ['class' => 'btn btn-outline-secondary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('transfers_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-1']),
        ]
    ),
    local_ga_dl_render_card(
        'Horas de alumnos',
        $hoursstudentcount . ' alumno(s) · ' . round($hourstotal, 2) . ' h',
        'Listado de horas acumuladas por alumno, incluyendo Tipo A y Tipo B validado.',
        [
            html_writer::link(local_ga_dl_action_url('view_hours'), local_ga_btn_icon('i/search', 'Ver listado'), ['class' => 'btn btn-outline-secondary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('hours_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-1']),
        ]
    ),
];

$rightcards = [
    local_ga_dl_render_card(
        'Talleres Tipo B',
        count(local_ga_dl_internal_typeb_rows()) . ' fila(s)',
        'Talleres Tipo B: asistencia, reflexión de la Tarea Moodle y certificado generado.',
        [
            html_writer::link(local_ga_dl_action_url('view_typeb_workshops'), local_ga_btn_icon('i/search', 'Ver listado'), ['class' => 'btn btn-outline-secondary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('typeb_workshops_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mb-1']),
        ]
    ),
    local_ga_dl_render_card(
        'Certificados Tipo A',
        $typeacount . ' certificado(s)',
        'Listado y descarga masiva de certificados generados por el sistema.',
        [
            html_writer::link(local_ga_dl_action_url('view_typea'), local_ga_btn_icon('i/search', 'Ver listado'), ['class' => 'btn btn-outline-secondary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('typea_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('typea_zip', true), local_ga_btn_icon('t/download', 'Descargar PDFs ZIP'), ['class' => 'btn btn-secondary mb-1']),
        ]
    ),
    local_ga_dl_render_card(
        'Portafolios',
        count($portfolioids) . ' alumno(s)',
        'Portafolios principales y expedientes completos. Los expedientes solo incorporan Tipo B externo plenamente reconocido.',
        [
            html_writer::link(local_ga_dl_action_url('view_portfolios'), local_ga_btn_icon('i/search', 'Ver listado'), ['class' => 'btn btn-outline-secondary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('portfolios_csv', true), local_ga_btn_icon('t/download', 'Descargar CSV'), ['class' => 'btn btn-primary mr-1 mb-1']),
            html_writer::link(new moodle_url('/local/gestion_actividades/portfolio_pdf_all.php', ['sesskey' => sesskey()]), local_ga_btn_icon('t/download', 'Descargar portafolios PDF ZIP'), ['class' => 'btn btn-primary mr-1 mb-1']),
            html_writer::link(local_ga_dl_action_url('packages_zip', true), local_ga_btn_icon('t/download', 'Descargar expedientes completos ZIP'), ['class' => 'btn btn-secondary mb-1']),
        ]
    ),
];

echo html_writer::start_div('row');
echo html_writer::start_div('col-md-6');
foreach ($leftcards as $cardhtml) {
    echo $cardhtml;
}
echo html_writer::end_div();
echo html_writer::start_div('col-md-6');
foreach ($rightcards as $cardhtml) {
    echo $cardhtml;
}
echo html_writer::end_div();
echo html_writer::end_div();

if (function_exists('local_gestion_actividades_enable_interactive_tables')) {
    local_gestion_actividades_enable_interactive_tables();
}
echo $OUTPUT->footer();
