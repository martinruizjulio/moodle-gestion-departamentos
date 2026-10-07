<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Bridge between each workshop edition and its Moodle Attendance activity.
 *
 * Agreed rules (2026-10-06):
 * - Attendance is mandatory to validate any Type A or Type B workshop.
 * - Only the "Presente" status counts. It is identified, independently of the
 *   course language, as the status with the highest grade of the status set
 *   used by the session (default set: Presente 2, Retraso 1, Justificado 1,
 *   Falta 0).
 * - The workshop attendance list is the source of truth: a student with a
 *   record in the list is attended in HEE only if some record is "Presente";
 *   a correction Presente -> Falta withdraws the HEE attendance, except when
 *   the student already has a certificate for that edition. Students without
 *   any record keep whatever was marked manually in "Alumnos / asistencia".
 * - Each edition gets one session (taller date/time, edition group only) so
 *   the teacher only has to take attendance.
 */
class attendance_sync {

    /**
     * Whether mod_attendance is installed with the tables used here.
     */
    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $DB->record_exists('modules', ['name' => 'attendance'])
            && $dbman->table_exists(new \xmldb_table('attendance'))
            && $dbman->table_exists(new \xmldb_table('attendance_sessions'))
            && $dbman->table_exists(new \xmldb_table('attendance_log'))
            && $dbman->table_exists(new \xmldb_table('attendance_statuses'));
    }

    /**
     * Attendance instance (table attendance) linked to an edition, or null.
     */
    private static function attendance_for_edition(\stdClass $edition): ?\stdClass {
        global $DB;
        if (empty($edition->attendancecmid) || !self::available()) {
            return null;
        }
        $row = $DB->get_record_sql(
            "SELECT a.*, cm.id AS cmid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'attendance'
               JOIN {attendance} a ON a.id = cm.instance
              WHERE cm.id = :cmid",
            ['cmid' => (int)$edition->attendancecmid],
            IGNORE_MISSING
        );
        return $row ?: null;
    }

    /**
     * An attendance activity linked to several editions (legacy course-wide
     * list) is shared: only sessions of the edition group belong to it.
     */
    private static function is_shared(int $attendancecmid): bool {
        global $DB;
        return $DB->count_records('local_ga_workshop_editions', ['attendancecmid' => $attendancecmid]) > 1;
    }

    /**
     * Session start and duration for an edition.
     *
     * @return int[] [sessdate, duration]
     */
    /**
     * Sessions the attendance list must have: one per day of the taller.
     *
     * @return array[] list of [start, duration, label]
     */
    private static function session_plan(\stdClass $edition, \stdClass $workshop): array {
        [$start, $duration] = self::session_times($edition, $workshop);
        $day2 = workshop_series::second_day(workshop_series::item_for_edition($edition));
        $base = format_string($workshop->code . ' - ' . $workshop->name);
        if (!$day2) {
            return [[$start, $duration, $base]];
        }
        return [
            [$start, $duration, $base . ' · Día 1'],
            [$day2[0], $day2[1] - $day2[0], $base . ' · Día 2'],
        ];
    }

    private static function session_times(\stdClass $edition, \stdClass $workshop): array {
        global $DB;
        $start = (int)($edition->sessiondate ?? 0);
        $duration = 0;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_series_items'))) {
            $params = ['workshopid' => (int)$workshop->id];
            $sql = "SELECT sessionenddate FROM {local_ga_series_items} WHERE workshopid = :workshopid";
            if (!empty($edition->seriesid)) {
                $sql .= " AND seriesid = :seriesid";
                $params['seriesid'] = (int)$edition->seriesid;
            }
            $end = (int)$DB->get_field_sql($sql . " ORDER BY id DESC", $params, IGNORE_MULTIPLE);
            if ($end > $start && $start > 0) {
                $duration = $end - $start;
            }
        }
        if ($duration <= 0 && !empty($workshop->hours) && (float)$workshop->hours > 0) {
            $duration = (int)round((float)$workshop->hours * HOURSECS);
        }
        if ($duration <= 0) {
            $duration = HOURSECS;
        }
        return [$start, $duration];
    }

    /**
     * Create (or keep in step) the attendance session of an edition.
     * An existing session is only moved while nobody has taken attendance.
     */
    public static function ensure_session(int $editionid): void {
        global $DB, $CFG;
        $edition = manager::get_workshop_edition($editionid);
        $attendance = self::attendance_for_edition($edition);
        if (!$attendance || empty($edition->sessiondate)) {
            return;
        }
        $workshop = manager::get_workshop((int)$edition->workshopid);
        $groupid = (int)($edition->groupid ?? 0);
        $plan = self::session_plan($edition, $workshop);

        $conditions = ['attendanceid' => (int)$attendance->id];
        if (self::is_shared((int)$attendance->cmid)) {
            if ($groupid <= 0) {
                return;
            }
            $conditions['groupid'] = $groupid;
        }
        $sessions = array_values($DB->get_records('attendance_sessions', $conditions, 'sessdate ASC, id ASC'));

        foreach (['/mod/attendance/locallib.php', '/mod/attendance/classes/calendar_helpers.php'] as $lib) {
            if (file_exists($CFG->dirroot . $lib)) {
                require_once($CFG->dirroot . $lib);
            }
        }

        // Keep the generated sessions aligned with the taller days while
        // attendance has not been taken in them; create missing days.
        foreach ($plan as $i => [$start, $duration, $label]) {
            if (isset($sessions[$i])) {
                $session = $sessions[$i];
                if (empty($session->lasttaken)
                        && ((int)$session->sessdate !== $start || (int)$session->duration !== $duration
                            || (int)$session->groupid !== $groupid || (string)$session->description !== $label)) {
                    $session->sessdate = $start;
                    $session->duration = $duration;
                    $session->groupid = $groupid;
                    $session->description = $label;
                    $session->timemodified = time();
                    $DB->update_record('attendance_sessions', $session);
                    if (!empty($session->caleventid) && function_exists('attendance_update_calendar_event')) {
                        try {
                            attendance_update_calendar_event($session);
                        } catch (\Throwable $e) {
                            debugging('No se pudo actualizar el evento de calendario de asistencia: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                }
                continue;
            }
            self::create_session((int)$attendance->id, $groupid, $start, $duration, $label);
        }

        // A taller changed from two days to one: drop the generated «Día 2»
        // session only while nobody has taken attendance in it.
        if (count($plan) === 1) {
            foreach (array_slice($sessions, 1) as $session) {
                if (empty($session->lasttaken) && substr((string)$session->description, -strlen(' · Día 2')) === ' · Día 2') {
                    if (!empty($session->caleventid) && function_exists('attendance_delete_calendar_events')) {
                        try {
                            attendance_delete_calendar_events([(int)$session->id]);
                        } catch (\Throwable $e) {
                            debugging('No se pudo borrar el evento de calendario de asistencia: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                    $DB->delete_records('attendance_log', ['sessionid' => (int)$session->id]);
                    $DB->delete_records('attendance_sessions', ['id' => (int)$session->id]);
                }
            }
        }
    }

    private static function create_session(int $attendanceid, int $groupid, int $start, int $duration, string $label): void {
        global $DB;
        $config = get_config('attendance');
        $session = (object)[
            'attendanceid' => $attendanceid,
            'groupid' => $groupid,
            'sessdate' => $start,
            'duration' => $duration,
            'lasttaken' => 0,
            'lasttakenby' => 0,
            'timemodified' => time(),
            'description' => $label,
            'descriptionformat' => FORMAT_HTML,
            'studentscanmark' => 0,
            'allowupdatestatus' => 0,
            'studentsearlyopentime' => 0,
            'autoassignstatus' => 0,
            'studentpassword' => '',
            'subnet' => '',
            'automark' => 0,
            'automarkcompleted' => 0,
            'statusset' => 0,
            'absenteereport' => 1,
            'preventsharedip' => 0,
            'preventsharediptime' => 0,
            'caleventid' => 0,
            'calendarevent' => empty($config->enablecalendar) ? 0 : 1,
            'includeqrcode' => 0,
            'rotateqrcode' => 0,
            'automarkcmid' => 0,
        ];
        $session->id = $DB->insert_record('attendance_sessions', $session);
        if (!empty($session->calendarevent) && function_exists('attendance_create_calendar_event')) {
            try {
                attendance_create_calendar_event($session);
            } catch (\Throwable $e) {
                debugging('No se pudo crear el evento de calendario de la sesión de asistencia: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Attendance list of an edition: userid => true when some record is
     * "Presente", false when the user only has other statuses. Users without
     * any record are absent from the array.
     *
     * @return bool[]
     */
    public static function list_map(int $editionid): array {
        global $DB;
        $edition = manager::get_workshop_edition($editionid);
        $attendance = self::attendance_for_edition($edition);
        if (!$attendance) {
            return [];
        }

        $conditions = ['attendanceid' => (int)$attendance->id];
        if (self::is_shared((int)$attendance->cmid)) {
            if (empty($edition->groupid)) {
                return [];
            }
            $conditions['groupid'] = (int)$edition->groupid;
        }
        $sessions = $DB->get_records('attendance_sessions', $conditions, '', 'id, statusset');
        if (!$sessions) {
            return [];
        }

        // "Presente" = highest-graded non-deleted status of each status set.
        $presentstatus = [];
        $sets = array_unique(array_map(static function($s) {
            return (int)$s->statusset;
        }, $sessions));
        foreach ($sets as $set) {
            $statuses = $DB->get_records('attendance_statuses', [
                'attendanceid' => (int)$attendance->id,
                'setnumber' => $set,
                'deleted' => 0,
            ], '', 'id, grade');
            $max = null;
            foreach ($statuses as $status) {
                $max = $max === null ? (float)$status->grade : max($max, (float)$status->grade);
            }
            if ($max === null || $max <= 0) {
                continue;
            }
            foreach ($statuses as $status) {
                if ((float)$status->grade === $max) {
                    $presentstatus[(int)$status->id] = true;
                }
            }
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($sessions), SQL_PARAMS_NAMED, 'as');
        $logs = $DB->get_records_select('attendance_log', "sessionid $insql", $params, '', 'id, sessionid, studentid, statusid');
        // «Presente» is required in EVERY session of the taller (a two-day
        // taller needs both days). A day without a mark yet counts as not
        // present; users without any mark are left out (no list taken).
        $presentdays = [];
        $marked = [];
        foreach ($logs as $log) {
            $uid = (int)$log->studentid;
            $marked[$uid] = true;
            if (isset($presentstatus[(int)$log->statusid])) {
                $presentdays[$uid][(int)$log->sessionid] = true;
            }
        }
        $needed = count($sessions);
        $byuser = [];
        foreach (array_keys($marked) as $uid) {
            $byuser[$uid] = count($presentdays[$uid] ?? []) >= $needed;
        }
        return $byuser;
    }

    /**
     * Apply the attendance list of an edition to its HEE enrolments.
     *
     * @return \stdClass counts: present, absent, unchanged, protected
     */
    public static function sync_edition(int $editionid): \stdClass {
        $summary = (object)['present' => 0, 'absent' => 0, 'unchanged' => 0, 'protected' => 0];
        $byuser = self::list_map($editionid);
        if (!$byuser) {
            return $summary;
        }

        $changedusers = [];
        foreach (manager::list_edition_enrolled_users_ultrasafe($editionid) as $row) {
            $uid = (int)($row->userid ?? 0);
            if ($uid <= 0 || !array_key_exists($uid, $byuser)) {
                continue;
            }
            $enrolment = manager::get_user_edition_enrolment($editionid, $uid);
            if (!$enrolment || !manager::is_active_enrolment($enrolment)) {
                continue;
            }
            $current = manager::is_user_attended_edition($editionid, $uid);
            $wanted = $byuser[$uid];
            if ($current === $wanted) {
                $summary->unchanged++;
                continue;
            }
            if (!$wanted && manager::get_user_certificate_for_edition($editionid, $uid)) {
                // Never withdraw the attendance behind an issued certificate.
                $summary->protected++;
                continue;
            }
            manager::set_enrolment_attendance((int)$enrolment->id, $wanted, 0);
            $changedusers[] = $uid;
            if ($wanted) {
                $summary->present++;
            } else {
                $summary->absent++;
            }
        }
        if ($changedusers) {
            manager::invalidate_block_caches_for_users($changedusers);
            manager::invalidate_teacher_block_cache([], [$editionid]);
        }
        return $summary;
    }

    /**
     * Sync every edition that uses a given attendance instance.
     */
    public static function sync_attendance_instance(int $attendanceid): void {
        global $DB;
        if (!self::available()) {
            return;
        }
        $cm = get_coursemodule_from_instance('attendance', $attendanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $editionids = $DB->get_fieldset_select('local_ga_workshop_editions', 'id', 'attendancecmid = :cmid', ['cmid' => (int)$cm->id]);
        foreach ($editionids as $editionid) {
            self::sync_edition((int)$editionid);
        }
    }

    /**
     * Sync an edition at most once per request (certificate loops call this
     * for every student).
     */
    public static function sync_edition_once(int $editionid): void {
        static $done = [];
        if (isset($done[$editionid])) {
            return;
        }
        $done[$editionid] = true;
        self::sync_edition_safely($editionid);
    }

    /**
     * Best-effort wrapper for pages: never break the page if the sync fails.
     */
    public static function sync_edition_safely(int $editionid): void {
        try {
            self::sync_edition($editionid);
        } catch (\Throwable $e) {
            debugging('No se pudo sincronizar la asistencia del taller: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
