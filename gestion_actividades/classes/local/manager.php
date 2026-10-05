<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/gradelib.php');

class manager {
    public static function get_activity(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_ga_activities', ['id' => $id], '*', MUST_EXIST);
    }

    public static function save_activity(\stdClass $data): int {
        global $DB, $USER;
        $now = time();
        $record = (object)[
            'courseid' => (int)$data->courseid,
            'activitykey' => trim($data->activitykey),
            'name' => trim($data->name),
            'description' => $data->description ?? '',
            'teacherid' => !empty($data->teacherid) ? (int)$data->teacherid : null,
            'places' => max(1, (int)$data->places),
            'idfield' => in_array($data->idfield, ['email', 'username', 'idnumber'], true) ? $data->idfield : 'email',
            'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $record->id = (int)$data->id;
            $DB->update_record('local_ga_activities', $record);
            return $record->id;
        }
        $record->timecreated = $now;
        return $DB->insert_record('local_ga_activities', $record);
    }

    public static function process_csv(\stdClass $activity, string $filepath, string $filename, string $gradecolumn, bool $creategroup, bool $createmissingusers = false, string $academicyear = '', bool $savegradehistory = true, bool $updategradebook = false, string $gradeitemname = ''): \stdClass {
        global $DB, $USER;

        // Missing identities are provisioned by institutional Moodle, never here.
        $createmissingusers = false;
        $now = time();
        if ($academicyear === '') {
            $year = (int)date('Y');
            $academicyear = $year . '/' . ($year + 1);
        }
        $import = (object)[
            'activityid' => $activity->id,
            'filename' => $filename,
            'userid' => $USER->id,
            'timecreated' => $now,
        ];
        $importid = $DB->insert_record('local_ga_imports', $import);

        // Keep the latest import clean for this activity.
        $DB->delete_records('local_ga_candidates', ['activityid' => $activity->id]);
        $DB->delete_records('local_ga_participants', ['activityid' => $activity->id]);

        $rows = self::read_csv($filepath);
        if (empty($rows)) {
            throw new \moodle_exception('errorcsvempty', 'local_gestion_actividades');
        }

        $headers = array_map([self::class, 'normalise_header'], array_shift($rows));
        $idfield = $activity->idfield;
        $idindex = array_search(self::normalise_header($idfield), $headers, true);
        $gradeindex = array_search(self::normalise_header($gradecolumn), $headers, true);
        if ($gradeindex === false && self::normalise_header($gradecolumn) !== 'grade') {
            $gradeindex = array_search('grade', $headers, true);
        }
        if ($gradeindex === false && self::normalise_header($gradecolumn) !== 'nota') {
            $gradeindex = array_search('nota', $headers, true);
        }
        if ($idindex === false) {
            throw new \moodle_exception('errormissingidcolumn', 'local_gestion_actividades', '', $idfield);
        }
        if ($gradeindex === false) {
            throw new \moodle_exception('errormissinggradecolumn', 'local_gestion_actividades');
        }

        $firstnameindex = array_search('firstname', $headers, true);
        if ($firstnameindex === false) { $firstnameindex = array_search('nombre', $headers, true); }
        $lastnameindex = array_search('lastname', $headers, true);
        if ($lastnameindex === false) { $lastnameindex = array_search('apellidos', $headers, true); }
        $emailindex = array_search('email', $headers, true);
        $usernameindex = array_search('username', $headers, true);
        $idnumberindex = array_search('idnumber', $headers, true);

        $seenusers = [];
        $candidates = [];
        $gradebookgrades = [];
        foreach ($rows as $row) {
            $identifier = self::cell($row, $idindex);
            $gradevalue = str_replace(',', '.', self::cell($row, $gradeindex));
            $candidate = (object)[
                'activityid' => $activity->id,
                'importid' => $importid,
                'userid' => null,
                'identifier' => $identifier,
                'firstname' => self::cell($row, $firstnameindex),
                'lastname' => self::cell($row, $lastnameindex),
                'email' => self::cell($row, $emailindex),
                'username' => self::cell($row, $usernameindex),
                'idnumber' => self::cell($row, $idnumberindex),
                'grade' => null,
                'rank' => null,
                'status' => 'candidate',
                'reason' => '',
                'timecreated' => $now,
            ];

            if ($identifier === '') {
                $candidate->status = 'invalid';
                $candidate->reason = 'Identificador vacío.';
                $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
                $candidates[] = $candidate;
                continue;
            }
            if ($gradevalue === '') {
                $candidate->status = 'nograde';
                $candidate->reason = 'Sin nota de expediente. No participa en el ranking de esta convocatoria.';
                $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
                $candidates[] = $candidate;
                continue;
            }
            if (!is_numeric($gradevalue)) {
                $candidate->status = 'invalid';
                $candidate->reason = 'Nota no numérica.';
                $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
                $candidates[] = $candidate;
                continue;
            }
            $candidate->grade = (float)$gradevalue;

            $users = self::find_users_by_field($idfield, $identifier);
            if (count($users) === 0) {
                if ($createmissingusers) {
                    $createduser = self::create_missing_user_from_candidate($candidate, $idfield, $identifier);
                    if ($createduser) {
                        $users = [$createduser];
                        $candidate->reason = 'Usuario creado automáticamente desde el CSV.';
                    } else {
                        $candidate->status = 'notfound';
                        $candidate->reason = 'No se ha encontrado usuario Moodle con ' . $idfield . ' = ' . $identifier . '. No se ha podido crear porque faltan email, nombre o apellidos.';
                        $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
                        $candidates[] = $candidate;
                        continue;
                    }
                } else {
                    $candidate->status = 'notfound';
                    $candidate->reason = 'No se ha encontrado usuario Moodle con ' . $idfield . ' = ' . $identifier;
                    $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
                    $candidates[] = $candidate;
                    continue;
                }
            }
            if (count($users) > 1) {
                $candidate->status = 'duplicate';
                $candidate->reason = 'Más de un usuario Moodle coincide con ese identificador.';
                $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
                $candidates[] = $candidate;
                continue;
            }

            $user = reset($users);
            $candidate->userid = $user->id;
            $candidate->email = $user->email;
            $candidate->username = $user->username;
            $candidate->idnumber = $user->idnumber;
            $candidate->firstname = $user->firstname;
            $candidate->lastname = $user->lastname;

            if ($savegradehistory && $candidate->grade !== null) {
                self::save_grade_history($activity, (int)$user->id, (float)$candidate->grade, $academicyear, $importid);
            }

            if (isset($seenusers[$user->id])) {
                $candidate->status = 'duplicate';
                $candidate->reason = 'El mismo usuario aparece más de una vez en el CSV.';
                $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
                $candidates[] = $candidate;
                continue;
            }
            $seenusers[$user->id] = true;

            if ($updategradebook && $candidate->grade !== null) {
                $gradebookgrades[(int)$user->id] = (float)$candidate->grade;
            }

            if (self::has_completed_activity($activity->activitykey, (int)$user->id)) {
                $candidate->status = 'completed';
                $candidate->reason = 'El alumno ya consta como realizado/certificado para esta clave de actividad.';
            }

            $candidate->id = $DB->insert_record('local_ga_candidates', $candidate);
            $candidates[] = $candidate;
        }

        if ($updategradebook && !empty($gradebookgrades)) {
            self::update_course_gradebook($activity, $gradebookgrades, $gradeitemname, $academicyear);
        }

        self::rank_candidates($activity);

        $groupid = null;
        if ($creategroup) {
            $groupid = self::create_group_for_selected($activity);
        } else {
            self::save_selected_participants($activity, null);
        }

        return self::summary($activity->id, $groupid);
    }

    private static function rank_candidates(\stdClass $activity): void {
        global $DB;
        $candidates = $DB->get_records('local_ga_candidates', ['activityid' => $activity->id]);
        $valid = [];
        foreach ($candidates as $candidate) {
            if ($candidate->status === 'candidate' && !empty($candidate->userid)) {
                $valid[] = $candidate;
            }
        }
        usort($valid, function($a, $b) {
            if ((float)$a->grade === (float)$b->grade) {
                return strcmp((string)$a->lastname, (string)$b->lastname);
            }
            return ((float)$a->grade < (float)$b->grade) ? 1 : -1;
        });

        $rank = 1;
        foreach ($valid as $candidate) {
            $candidate->rank = $rank;
            if ($rank <= (int)$activity->places) {
                $candidate->status = 'selected';
                $candidate->reason = 'Admitido por ranking.';
            } else {
                $candidate->status = 'reserve';
                $candidate->reason = 'Reserva por nota.';
            }
            $DB->update_record('local_ga_candidates', $candidate);
            $rank++;
        }
    }

    public static function create_group_for_selected(\stdClass $activity): ?int {
        global $DB;
        $groupname = shorten_text($activity->name, 60) . ' - Convocatoria ' . $activity->id . ' - Admitidos';
        $data = (object)[
            'courseid' => $activity->courseid,
            'name' => $groupname,
            'description' => 'Grupo creado automáticamente por Gestion_actividades.',
            'descriptionformat' => FORMAT_PLAIN,
        ];
        $groupid = groups_create_group($data);
        $selected = $DB->get_records('local_ga_candidates', ['activityid' => $activity->id, 'status' => 'selected']);
        foreach ($selected as $candidate) {
            groups_add_member($groupid, $candidate->userid);
        }
        self::save_selected_participants($activity, $groupid);
        return $groupid;
    }

    private static function save_selected_participants(\stdClass $activity, ?int $groupid): void {
        global $DB;
        $selected = $DB->get_records('local_ga_candidates', ['activityid' => $activity->id, 'status' => 'selected']);
        foreach ($selected as $candidate) {
            $participant = (object)[
                'activityid' => $activity->id,
                'candidateid' => $candidate->id,
                'userid' => $candidate->userid,
                'grade' => $candidate->grade,
                'rank' => $candidate->rank,
                'groupid' => $groupid,
                'status' => 'selected',
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            $DB->insert_record('local_ga_participants', $participant);
        }
    }

    public static function mark_selected_as_completed(int $activityid): int {
        global $DB;
        $activity = self::get_activity($activityid);
        $participants = $DB->get_records('local_ga_participants', ['activityid' => $activityid, 'status' => 'selected']);
        $count = 0;
        foreach ($participants as $participant) {
            if (!$DB->record_exists('local_ga_completions', ['activitykey' => $activity->activitykey, 'userid' => $participant->userid])) {
                $DB->insert_record('local_ga_completions', (object)[
                    'activitykey' => $activity->activitykey,
                    'activityid' => $activityid,
                    'userid' => $participant->userid,
                    'status' => 'completed',
                    'source' => 'manual_v01',
                    'timecompleted' => time(),
                ]);
                $count++;
            }
            $participant->status = 'completed';
            $participant->timemodified = time();
            $DB->update_record('local_ga_participants', $participant);
        }
        return $count;
    }

    public static function has_completed_activity(string $activitykey, int $userid): bool {
        global $DB;
        return $DB->record_exists_select('local_ga_completions', 'activitykey = :activitykey AND userid = :userid AND status IN (\'completed\', \'certified\', \'attended\')', [
            'activitykey' => $activitykey,
            'userid' => $userid,
        ]);
    }



    public static function get_workshop_overview_rows(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))
            || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            return [];
        }

        $sql = "SELECT e.id,
                       w.id AS workshopid,
                       w.courseid,
                       w.code AS workshopcode,
                       w.name AS workshopname,
                       w.hours AS workshophours,
                       w.workshoptype AS workshoptype,
                       e.name AS editionname,
                       e.editioncode,
                       e.sessiondate,
                       e.enrolenddate,
                       e.places,
                       e.groupid,
                       e.status,
                       e.attendancecmid,
                       e.certificatecmid,
                       e.requiredcmid,
                       e.requiredmodname,
                       e.activitycreationtype,
                       e.tasknumericgrade,
                       e.quizgradingmode,
                       e.archived,
                       e.timearchived
                  FROM {local_ga_workshops} w
                  JOIN {local_ga_workshop_editions} e ON e.workshopid = w.id
              ORDER BY e.sessiondate DESC, e.id DESC";
        $rows = $DB->get_records_sql($sql);
        if (!$rows) {
            return [];
        }

        $editionids = array_map('intval', array_keys($rows));
        $groupids = [];
        foreach ($rows as $row) {
            if (!empty($row->groupid)) {
                $groupids[(int)$row->groupid] = (int)$row->groupid;
            }
            $row->enrolledcount = 0;
            $row->groupname = '';
            $row->teachers = '';
            $row->teacherids = [];
        }

        if ($groupids) {
            list($groupsql, $gparams) = $DB->get_in_or_equal(array_values($groupids), SQL_PARAMS_NAMED);
            $groups = $DB->get_records_select('groups', 'id ' . $groupsql, $gparams, '', 'id,name');
            $counts = $DB->get_records_sql("SELECT groupid, COUNT(id) AS cnt FROM {groups_members} WHERE groupid $groupsql GROUP BY groupid", $gparams);
            foreach ($rows as $row) {
                $gid = (int)$row->groupid;
                if ($gid > 0) {
                    $row->groupname = isset($groups[$gid]) ? $groups[$gid]->name : '';
                    $row->enrolledcount = isset($counts[$gid]) ? (int)$counts[$gid]->cnt : 0;
                }
            }
        }

        if ($editionids && $DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_teachers'))) {
            list($insql, $params) = $DB->get_in_or_equal($editionids, SQL_PARAMS_NAMED);
            $teachers = $DB->get_records_sql("SELECT et.id, et.editionid, et.userid, u.firstname, u.lastname, u.email
                                                FROM {local_ga_edition_teachers} et
                                                JOIN {user} u ON u.id = et.userid
                                               WHERE et.editionid $insql
                                            ORDER BY u.lastname ASC, u.firstname ASC", $params);
            $names = [];
            $ids = [];
            foreach ($teachers as $teacher) {
                $eid = (int)$teacher->editionid;
                $names[$eid][] = fullname($teacher);
                $ids[$eid][] = (int)$teacher->userid;
            }
            foreach ($rows as $row) {
                $eid = (int)$row->id;
                $row->teachers = !empty($names[$eid]) ? implode(', ', $names[$eid]) : '';
                $row->teacherids = !empty($ids[$eid]) ? $ids[$eid] : [];
            }
        }

        $now = time();
        foreach ($rows as $row) {
            if (!empty($row->archived) || $row->status === 'archived') {
                $row->computedstatus = 'archived';
            } else if ($row->status === 'closed_full') {
                $row->computedstatus = 'closed_full';
            } else if (!empty($row->status) && in_array((string)$row->status, ['finished', 'completed', 'closed_finished'], true)) {
                $row->computedstatus = 'archived';
            } else if (!empty($row->enrolenddate) && $row->enrolenddate < $now) {
                $row->computedstatus = 'closed_date';
            } else {
                $row->computedstatus = 'open';
            }
        }

        return $rows;
    }



    public static function get_or_create_course_section(int $courseid, string $sectionname): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $sectionname = trim($sectionname);
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');

        foreach ($sections as $section) {
            if (trim($section->name ?? '') === $sectionname) {
                return (int)$section->section;
            }
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        // Append as last regular section (delegated subsections stay last).
        $created = course_create_section($course, 0);
        $sectionrecord = $DB->get_record('course_sections', ['id' => (int)$created->id], '*', MUST_EXIST);
        $newsectionnum = (int)$sectionrecord->section;
        $sectionrecord->name = $sectionname;
        $sectionrecord->visible = 1;
        $sectionrecord->timemodified = time();
        $DB->update_record('course_sections', $sectionrecord);

        rebuild_course_cache($courseid, true);
        return $newsectionnum;
    }


    public static function get_main_workshop_section_name(): string {
        return 'TALLERES TIPO A';
    }

    public static function get_main_workshop_section_name_for_type(string $type): string {
        return self::normalize_workshop_type($type) === 'typeb' ? 'TALLERES TIPO B' : 'TALLERES TIPO A';
    }

    public static function get_archive_workshop_section_name(): string {
        return 'TALLERES TIPO A - ARCHIVO';
    }

    public static function normalize_workshop_type(string $type): string {
        $type = strtolower(trim($type));
        return in_array($type, ['typea', 'typeb'], true) ? $type : 'typea';
    }

    public static function get_workshop_type(\stdClass $workshop): string {
        return self::normalize_workshop_type((string)($workshop->workshoptype ?? 'typea'));
    }

    public static function is_typeb_workshop(\stdClass $workshop): bool {
        return self::get_workshop_type($workshop) === 'typeb';
    }

    public static function ensure_workshop_sections(int $courseid): \stdClass {
        return (object)[
            'main' => self::get_or_create_course_section($courseid, self::get_main_workshop_section_name()),
            'typeb' => self::get_or_create_course_section($courseid, self::get_main_workshop_section_name_for_type('typeb')),
            'archive' => 0,
        ];
    }

    public static function get_workshop_section_name(\stdClass $workshop): string {
        return trim($workshop->code . ' - ' . $workshop->name);
    }

    public static function get_or_create_workshop_section(\stdClass $workshop): int {
        return self::get_or_create_course_section((int)$workshop->courseid, self::get_main_workshop_section_name_for_type(self::get_workshop_type($workshop)));
    }



    public static function create_required_activity_for_edition(int $editionid, ?int $userid = null, string $forcedtype = ''): \stdClass {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $result = (object)['success' => false, 'message' => '', 'cmid' => 0];

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $course = $DB->get_record('course', ['id' => (int)$workshop->courseid], '*', MUST_EXIST);

        $type = in_array($forcedtype, ['assign', 'quiz'], true) ? $forcedtype : self::detect_required_activity_type($edition);
        if ($type === '') {
            $result->message = get_string('requiredtypenotselected', 'local_gestion_actividades');
            return $result;
        }

        $existing = self::get_required_activity_for_edition_by_type($editionid, $type);
        if ($existing && !empty($existing->cmid) && $DB->record_exists('course_modules', ['id' => (int)$existing->cmid, 'course' => (int)$workshop->courseid])) {
            $result->success = true;
            $result->cmid = (int)$existing->cmid;
            $result->message = get_string('requiredactivityalreadycreated', 'local_gestion_actividades');
            return $result;
        }

        if (!$DB->record_exists('modules', ['name' => $type])) {
            $result->message = get_string('requiredmodnotavailable', 'local_gestion_actividades') . ': ' . $type;
            return $result;
        }

        $sectionnum = self::get_or_create_course_section((int)$course->id, self::get_main_workshop_section_name());

        $name = $type === 'quiz'
            ? get_string('quizforworkshop', 'local_gestion_actividades', $workshop->name)
            : get_string('assignmentforworkshop', 'local_gestion_actividades', $workshop->name);

        $moduleinfo = new \stdClass();
        $moduleinfo->course = (int)$course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->module = (int)$DB->get_field('modules', 'id', ['name' => $type], MUST_EXIST);
        $moduleinfo->modulename = $type;
        $moduleinfo->name = $name;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 1;
        $moduleinfo->intro = get_string('requiredactivityintro', 'local_gestion_actividades', $workshop->name);
        $moduleinfo->introformat = FORMAT_HTML;

        // Minimal required fields for compatibility.
        if ($type === 'assign') {
            $moduleinfo->alwaysshowdescription = 1;
            $moduleinfo->submissiondrafts = 0;
            $moduleinfo->requiresubmissionstatement = 0;
            $moduleinfo->sendnotifications = 0;
            $moduleinfo->sendlatenotifications = 0;
            $moduleinfo->sendstudentnotifications = 0;
            $moduleinfo->duedate = 0;
            $moduleinfo->allowsubmissionsfromdate = 0;
            $moduleinfo->grade = 100;
            $moduleinfo->teamsubmission = 0;
            $moduleinfo->requireallteammemberssubmit = 0;
            $moduleinfo->blindmarking = 0;
            $moduleinfo->attemptreopenmethod = 'none';
            $moduleinfo->maxattempts = -1;
        } else if ($type === 'quiz') {
            $moduleinfo->grade = 100;
            $moduleinfo->sumgrades = 0;
            $moduleinfo->attempts = 0;
            $moduleinfo->questionsperpage = 1;
            $moduleinfo->preferredbehaviour = 'deferredfeedback';
            $moduleinfo->timeopen = 0;
            $moduleinfo->timeclose = 0;
        }

        try {
            $created = add_moduleinfo($moduleinfo, $course);
            $cmid = 0;
            if (!empty($created->coursemodule)) {
                $cmid = (int)$created->coursemodule;
            } else if (!empty($created->coursemoduleid)) {
                $cmid = (int)$created->coursemoduleid;
            } else if (!empty($created->cmid)) {
                $cmid = (int)$created->cmid;
            }

            if ($cmid > 0) {
                self::update_edition_required_cmid($editionid, $cmid);
                try {
                    self::restrict_required_activity_to_edition_group($editionid, $cmid);
                    self::hard_archive_cmid_from_course_page((int)$cmid);
                    self::add_workshop_backlink_to_required_activity($editionid, $cmid);
                } catch (\Throwable $e) {
                    // Non-blocking: the activity is still created and linked.
                }
                $result->success = true;
                $result->cmid = $cmid;
                $result->message = get_string('requiredactivitycreated', 'local_gestion_actividades');
                rebuild_course_cache((int)$course->id, true);
                return $result;
            }

            $result->message = get_string('requiredactivitycreatefailed', 'local_gestion_actividades');
            return $result;
        } catch (\Throwable $e) {
            $result->message = get_string('requiredactivitycreatefailed', 'local_gestion_actividades') . ': ' . $e->getMessage();
            return $result;
        }
    }

    public static function archive_due_workshop_editions(int $courseid = 0): int {
        // Las fechas del taller y de cierre de inscripción no finalizan ni archivan una edición.
        // Una edición solo deja de estar activa cuando el profesor la finaliza o archiva expresamente.
        return 0;
    }

    /** Minimum mark to pass a Type A quiz, on a 0-10 scale. */
    public const QUIZ_PASS_MARK = 5.0;

    /**
     * Requirement of a Type A edition whose required activity is a Moodle quiz:
     * attendance + quiz finished with at least 5 out of 10. The quiz grade is
     * rescaled to 10 whatever its maximum grade in Moodle.
     */
    public static function get_quiz_requirement(\stdClass $edition): \stdClass {
        $cmid = !empty($edition->requiredquizcmid) ? (int)$edition->requiredquizcmid : (int)($edition->requiredcmid ?? 0);
        return (object)['cmid' => $cmid, 'minimum' => self::QUIZ_PASS_MARK, 'scale' => 10.0];
    }

    /**
     * Quiz grade of a user rescaled to 0-10, or null when not graded.
     */
    public static function get_user_quiz_grade_out_of_10(int $userid, int $cmid): ?float {
        $item = self::get_module_grade_item($cmid);
        $grade = self::get_user_grade_for_cmid($userid, $cmid);
        $min = (float)($item->grademin ?? 0);
        if (!$item || $grade === null || (float)$item->grademax <= $min) {
            return null;
        }
        // Same normalisation as grade_manager::normalise_to_ten() (gradebook average).
        return round(($grade - $min) / ((float)$item->grademax - $min) * 10.0, 2);
    }

    /**
     * Null when the quiz requirement is met, otherwise a short description.
     */
    public static function quiz_missing_requirement(\stdClass $edition, int $userid): ?string {
        $req = self::get_quiz_requirement($edition);
        if ($req->cmid <= 0 || !self::user_submitted_required_activity($userid, $req->cmid)) {
            return 'cuestionario';
        }
        $grade = self::get_user_quiz_grade_out_of_10($userid, $req->cmid);
        if ($grade === null || $grade < $req->minimum) {
            return 'nota mínima del cuestionario (' . format_float($req->minimum, 0) . '/10)';
        }
        return null;
    }

    /**
     * Main grade item of a course module (itemnumber 0; ignores outcomes).
     */
    private static function get_module_grade_item(int $cmid): ?\stdClass {
        global $DB;
        $sql = "SELECT gi.id, gi.gradepass, gi.grademin, gi.grademax
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {grade_items} gi ON gi.courseid = cm.course AND gi.itemtype = 'mod'
                       AND gi.itemmodule = m.name AND gi.iteminstance = cm.instance AND gi.itemnumber = 0
                 WHERE cm.id = :cmid";
        $records = $DB->get_records_sql($sql, ['cmid' => $cmid], 0, 1);
        return $records ? reset($records) : null;
    }

    public static function get_user_grade_for_cmid(int $userid, int $cmid): ?float {
        global $DB;
        if ($cmid <= 0) { return null; }
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,instance,module');
        if (!$cm) { return null; }
        $module = $DB->get_record('modules', ['id' => $cm->module], 'id,name');
        if (!$module) { return null; }
        $item = self::get_module_grade_item($cmid);
        if (!$item) { return null; }
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $userid], 'finalgrade');
        if (!$grade || $grade->finalgrade === null) { return null; }
        return (float)$grade->finalgrade;
    }


    public static function ensure_workshop_sections_safely(int $workshopid): bool {
        global $DB;
        try {
            $workshop = $DB->get_record('local_ga_workshops', ['id' => $workshopid], '*', MUST_EXIST);
            self::ensure_workshop_sections((int)$workshop->courseid);
            self::get_or_create_workshop_section($workshop);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }


    public static function ensure_workshop_course_visuals_safely(int $workshopid): bool {
        try {
            $workshop = self::get_workshop($workshopid);
            return self::ensure_workshop_url_in_main_section($workshop);
        } catch (\Throwable $e) {
            return false;
        }
    }


    public static function is_workshop_currently_offered(\stdClass $workshop): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            return true;
        }

        // Un taller recién creado sin edición todavía debe aparecer en el panel para poder configurarlo.
        if (!$DB->record_exists('local_ga_workshop_editions', ['workshopid' => (int)$workshop->id])) {
            return true;
        }

        return self::is_workshop_publishable($workshop);
    }

    public static function is_workshop_publishable(\stdClass $workshop): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            return false;
        }

        // La fecha del taller solo informa de cuándo se realiza. No retira la edición del curso.
        // La edición permanece publicada hasta que un profesor la finaliza o archiva expresamente.
        $sql = "SELECT id
                  FROM {local_ga_workshop_editions}
                 WHERE workshopid = :workshopid
                   AND (archived = 0 OR archived IS NULL)
                   AND (status IS NULL OR status NOT IN ('archived', 'finished', 'closed_full', 'completed', 'closed_finished'))
              ORDER BY sessiondate ASC, id ASC";
        return $DB->record_exists_sql($sql, ['workshopid' => (int)$workshop->id]);
    }

    public static function list_current_workshops(int $courseid = 0, string $type = ''): array {
        $workshops = self::list_workshops($courseid, $type);
        $out = [];
        foreach ($workshops as $workshop) {
            if (self::is_workshop_currently_offered($workshop)) {
                $out[$workshop->id] = $workshop;
            }
        }
        return $out;
    }

    public static function ensure_all_workshop_course_visuals(int $courseid = 0): \stdClass {
        global $DB;

        $params = [];
        $where = '';
        if ($courseid > 0) {
            $where = 'WHERE courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        $workshops = $DB->get_records_sql("SELECT * FROM {local_ga_workshops} $where ORDER BY courseid ASC, code ASC, id ASC", $params);
        $summary = (object)[
            'total' => count($workshops),
            'created' => 0,
            'failed' => 0,
            'messages' => [],
        ];

        $courseids = [];
        foreach ($workshops as $workshop) {
            $courseids[(int)$workshop->courseid] = true;
        }

        foreach (array_keys($courseids) as $cid) {
            try {
                $removed = self::cleanup_generated_course_entries_for_course((int)$cid);
                if ($removed > 0) {
                    $summary->messages[] = 'Limpieza previa en curso ID ' . (int)$cid . ': ' . (int)$removed . ' entrada(s) retirada(s).';
                }
                self::delete_student_portfolio_url_from_course((int)$cid);
            } catch (\Throwable $e) {
                $summary->messages[] = 'No se pudo limpiar el curso ID ' . (int)$cid . ': ' . $e->getMessage();
            }
        }

        foreach ($workshops as $workshop) {
            if (!self::is_workshop_publishable($workshop)) {
                try {
                    $removed = self::delete_workshop_course_entries($workshop);
                    if ($removed > 0) {
                        $summary->messages[] = trim($workshop->code . ' - ' . $workshop->name) . ': retirado de la sección del curso porque no tiene edición vigente/publicable o está finalizado/archivado.';
                    } else {
                        $summary->messages[] = trim($workshop->code . ' - ' . $workshop->name) . ': omitido porque no tiene edición vigente/publicable o está finalizado/archivado.';
                    }
                } catch (\Throwable $e) {
                    $summary->messages[] = trim($workshop->code . ' - ' . $workshop->name) . ': omitido, pero no se pudo retirar de la sección: ' . $e->getMessage();
                }
                continue;
            }

            try {
                if (self::ensure_workshop_url_in_main_section($workshop)) {
                    $summary->created++;
                    $summary->messages[] = trim($workshop->code . ' - ' . $workshop->name) . ': publicado/actualizado en el curso.';
                } else {
                    $summary->failed++;
                    $summary->messages[] = trim($workshop->code . ' - ' . $workshop->name) . ': no se pudo publicar como URL visible.';
                }
            } catch (\Throwable $e) {
                $summary->failed++;
                $summary->messages[] = trim($workshop->code . ' - ' . $workshop->name) . ': ' . $e->getMessage();
            }
        }

        foreach (array_keys($courseids) as $cid) {
            try {
                self::sync_workshop_section_summary((int)$cid, 'typea');
                self::sync_workshop_section_summary((int)$cid, 'typeb');
                if (class_exists('local_gestion_actividades\local\grade_manager')) {
                    grade_manager::ensure_selfassessment_availability((int)$cid);
                }
            } catch (\Throwable $e) {
                $summary->failed++;
                $summary->messages[] = 'No se pudo reconstruir la sección visible del curso ID ' . (int)$cid . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    public static function ensure_workshop_entry_in_main_section(\stdClass $workshop): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        if (!$DB->record_exists('modules', ['name' => 'label'])) {
            return false;
        }

        $mainsection = self::get_or_create_course_section((int)$workshop->courseid, self::get_main_workshop_section_name_for_type(self::get_workshop_type($workshop)));
        $labelname = self::get_workshop_course_entry_name($workshop);

        // Avoid duplicates.
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {label} l ON l.id = cm.instance
                 WHERE cm.course = :courseid
                   AND m.name = 'label'
                   AND l.name = :name";
        if ($DB->record_exists_sql($sql, ['courseid' => (int)$workshop->courseid, 'name' => $labelname])) {
            return true;
        }

        $course = $DB->get_record('course', ['id' => (int)$workshop->courseid], '*', MUST_EXIST);
        $module = $DB->get_record('modules', ['name' => 'label'], '*', MUST_EXIST);

        $url = new \moodle_url('/local/gestion_actividades/editions.php', ['workshopid' => $workshop->id]);
        $hours = isset($workshop->hours) && $workshop->hours !== null ? ' — ' . round((float)$workshop->hours, 2) . ' h' : '';
        $intro = '<div class="local-ga-workshop-entry"><strong>' . s($workshop->code . ' - ' . $workshop->name) . '</strong>' .
            s($hours) . '<br><a href="' . $url->out(false) . '">' .
            get_string('openworkshopeditions', 'local_gestion_actividades') . '</a></div>';

        $moduleinfo = new \stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->module = $module->id;
        $moduleinfo->modulename = 'label';
        $moduleinfo->section = $mainsection;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $labelname;
        $moduleinfo->intro = $intro;
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->showdescription = 0;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 0;
        $moduleinfo->availability = null;

        add_moduleinfo($moduleinfo, $course);
        rebuild_course_cache((int)$workshop->courseid, true);
        return true;
    }

    public static function get_workshop_course_entry_name(\stdClass $workshop): string {
        return trim($workshop->code . ' - ' . $workshop->name);
    }




    /**
     * Ensure a course module is visible and present in the target section sequence.
     * Archived workshop entries may remain as valid modules but be absent from every sequence.
     */
    private static function restore_cmid_to_course_section(int $courseid, int $cmid, int $sectionnum): bool {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $cmrecord = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], '*', IGNORE_MISSING);
        $targetrecord = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $sectionnum,
        ], '*', IGNORE_MISSING);
        if (!$cmrecord || !$targetrecord) {
            return false;
        }

        try {
            // Use Moodle's course API so course_modules.section, the section sequence,
            // format-specific data and all related caches are updated as one operation.
            rebuild_course_cache($courseid, true);
            $modinfo = get_fast_modinfo($courseid);
            $cm = $modinfo->get_cm($cmid);
            $targetsection = $modinfo->get_section_info($sectionnum, MUST_EXIST);

            $currentsectionnum = isset($cm->sectionnum) ? (int)$cm->sectionnum : -1;
            $targetsequence = trim((string)($targetrecord->sequence ?? '')) === ''
                ? []
                : array_values(array_filter(array_map('intval', explode(',', (string)$targetrecord->sequence))));
            $insequence = in_array($cmid, $targetsequence, true);

            if ($currentsectionnum !== $sectionnum || !$insequence) {
                moveto_module($cm, $targetsection);
            }

            set_coursemodule_visible($cmid, 1, 1, false);
            rebuild_course_cache($courseid, true);

            // Verify the persisted state instead of trusting the API call blindly.
            $verifiedcm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], 'id,section,visible,visibleoncoursepage', MUST_EXIST);
            $verifiedsection = $DB->get_record('course_sections', ['id' => (int)$targetrecord->id], 'id,sequence', MUST_EXIST);
            $verifiedsequence = trim((string)($verifiedsection->sequence ?? '')) === ''
                ? []
                : array_values(array_filter(array_map('intval', explode(',', (string)$verifiedsection->sequence))));

            return (int)$verifiedcm->section === (int)$targetrecord->id
                && !empty($verifiedcm->visible)
                && (!property_exists($verifiedcm, 'visibleoncoursepage') || !empty($verifiedcm->visibleoncoursepage))
                && in_array($cmid, $verifiedsequence, true);
        } catch (\Throwable $e) {
            return false;
        }
    }


    /**
     * Rebuild the visible workshop cards directly in the course section summary.
     * This avoids relying on label modules and section sequences, which can vary
     * between Moodle course formats. Only currently publishable workshops are shown.
     */

    /**
     * Whether enrolment is closed for an edition.
     * Uses the explicit enrolment deadline when configured and otherwise
     * closes at the end of the workshop day.
     */
    public static function is_edition_enrolment_closed(\stdClass $edition, ?int $now = null): bool {
        $now = $now ?? time();
        if (!empty($edition->enrolenddate)) {
            return $now > (int)$edition->enrolenddate;
        }
        if (!empty($edition->sessiondate)) {
            $daystart = usergetmidnight((int)$edition->sessiondate);
            return $now > ($daystart + DAYSECS - 1);
        }
        return false;
    }

    public static function sync_workshop_section_summary(int $courseid, string $type = 'typea'): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $sectionname = self::get_main_workshop_section_name_for_type($type);
        $sectionnum = self::get_or_create_course_section($courseid, $sectionname);
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $sectionnum,
        ], '*', MUST_EXIST);

        $workshops = self::list_workshops($courseid, $type);
        $cards = '';
        foreach ($workshops as $workshop) {
            if (!self::is_workshop_publishable($workshop)) {
                continue;
            }
            $edition = self::get_primary_workshop_edition((int)$workshop->id);
            if (!$edition) {
                continue;
            }
            $viewurl = new \moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => (int)$workshop->id, 'editionid' => (int)$edition->id]);
            $enrolurl = new \moodle_url('/local/gestion_actividades/enrol.php', ['id' => (int)$edition->id]);
            $date = !empty($edition->sessiondate) ? self::format_workshop_date((int)$edition->sessiondate) : '-';
            $hours = isset($workshop->hours) && $workshop->hours !== null ? round((float)$workshop->hours, 2) . ' h' : '-';
            $remaining = self::get_edition_remaining_places($edition);
            $remainingtext = $remaining === null ? get_string('unlimitedplaces', 'local_gestion_actividades') : (string)$remaining;
            $title = trim((string)$workshop->code . ' - ' . (string)$workshop->name);

            $cards .= '<div class="local-ga-course-workshop" style="padding:1rem 1.1rem;border:1px solid #d8dee9;border-left:4px solid #0f6cbf;border-radius:10px;background:#fff;margin:.65rem 0;">';
            $cards .= '<div style="font-weight:700;font-size:1.08rem;">' . s($title) . '</div>';
            if (!empty($workshop->description)) {
                $cards .= '<div style="margin-top:.3rem;">' . s(trim((string)$workshop->description)) . '</div>';
            }
            $cards .= '<div style="margin-top:.45rem;color:#444;">';
            $cards .= '<strong>' . get_string('date') . ':</strong> ' . s($date);
            $cards .= ' · <strong>' . get_string('workshophours', 'local_gestion_actividades') . ':</strong> ' . s($hours);
            $cards .= ' · <strong>' . get_string('remainingplaces', 'local_gestion_actividades') . ':</strong> ' . s($remainingtext);
            $cards .= '</div>';
            $cards .= '<div class="local-ga-card-actions" data-editionid="' . (int)$edition->id . '" style="margin-top:.65rem;display:flex;align-items:center;gap:.35rem;flex-wrap:wrap;">';
            if (self::is_edition_enrolment_closed($edition)) {
                // El cierre por fecha es común para todo el curso y puede renderizarse directamente.
                $cards .= '<span class="btn disabled local-ga-enrol-status" data-editionid="' . (int)$edition->id . '" '
                    . 'style="background:#fff0d5;border:1px solid #efbd68;color:#8a4b00;font-weight:600;" '
                    . 'aria-disabled="true">' . get_string('enrolmentclosed', 'local_gestion_actividades') . '</span>';
            } else {
                // El resumen de sección es compartido. Se deja un control HTML válido que Moodle no elimina;
                // el módulo AMD sustituye su texto y estilo según el usuario conectado.
                $cards .= '<a class="btn btn-primary local-ga-enrol-status" data-editionid="' . (int)$edition->id . '" '
                    . 'href="' . $enrolurl->out(false) . '">' . get_string('enrolme', 'local_gestion_actividades') . '</a>';
            }
            $cards .= '<a class="btn btn-secondary" href="' . $viewurl->out(false) . '">' . get_string('viewworkshop', 'local_gestion_actividades') . '</a>';
            $cards .= '</div></div>';
        }

        $summary = $cards;
        if ($summary === '') {
            $summary = '<div class="alert alert-info mb-0">No hay talleres disponibles en este momento.</div>';
        }

        if ((string)($section->summary ?? '') !== $summary || (int)($section->summaryformat ?? FORMAT_HTML) !== FORMAT_HTML || empty($section->visible)) {
            course_update_section($courseid, $section, [
                'summary' => $summary,
                'summaryformat' => FORMAT_HTML,
                'visible' => 1,
            ]);
        } else {
            rebuild_course_cache($courseid, true);
        }
        return true;
    }

    public static function ensure_workshop_url_in_main_section(\stdClass $workshop): bool {
        return self::sync_workshop_section_summary(
            (int)$workshop->courseid,
            self::get_workshop_type($workshop)
        );
    }

    public static function ensure_student_portfolio_url_in_course(int $courseid): bool {
        // Desde v1.5.14 el acceso de alumno no se publica como actividad en la sección del curso.
        // Debe mostrarse mediante el bloque lateral block_gestion_hee, visible solo para alumnos.
        self::delete_student_portfolio_url_from_course($courseid);
        return false;
    }

    public static function delete_student_portfolio_url_from_course(int $courseid): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        if (!$DB->record_exists('modules', ['name' => 'url']) || !$DB->get_manager()->table_exists(new \xmldb_table('url'))) {
            return 0;
        }

        $names = [
            get_string('studentportfolioentryname', 'local_gestion_actividades'),
            'Mi portafolio HEE',
            'Mis certificados',
            'Mis horas',
        ];
        $deleted = 0;

        foreach ($names as $name) {
            $sql = "SELECT cm.id AS cmid
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {url} u ON u.id = cm.instance
                     WHERE cm.course = :courseid
                       AND m.name = 'url'
                       AND " . $DB->sql_like('u.name', ':name', false);
            $records = $DB->get_records_sql($sql, [
                'courseid' => $courseid,
                'name' => $DB->sql_like_escape($name) . '%',
            ]);
            foreach ($records as $record) {
                try {
                    course_delete_module((int)$record->cmid);
                    $deleted++;
                } catch (\Throwable $e) {
                    // Continue with the rest.
                }
            }
        }

        if ($deleted > 0) {
            rebuild_course_cache($courseid, true);
        }
        return $deleted;
    }

    public static function filter_object_to_columns(string $tablename, \stdClass $object): \stdClass {
        global $DB;
        $columns = $DB->get_columns($tablename);
        $out = new \stdClass();
        foreach ((array)$object as $key => $value) {
            if (isset($columns[$key])) {
                $out->$key = $value;
            }
        }
        return $out;
    }

    
    public static function get_primary_workshop_edition(int $workshopid): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            return null;
        }

        $sql = "SELECT *
                  FROM {local_ga_workshop_editions}
                 WHERE workshopid = :workshopid
                   AND (archived = 0 OR archived IS NULL)
                   AND (status IS NULL OR status NOT IN ('archived', 'finished', 'completed', 'closed_finished'))
              ORDER BY sessiondate DESC,
                       id DESC";
        $records = $DB->get_records_sql($sql, ['workshopid' => $workshopid], 0, 1);
        if (!$records) {
            return null;
        }
        return reset($records);
    }

    
    public static function get_edition_remaining_places(\stdClass $edition): ?int {
        $places = (int)($edition->places ?? 0);
        if ($places <= 0) {
            return null;
        }
        return max(0, $places - self::get_edition_enrolment_count((int)$edition->id));
    }



    public static function get_edition_enrolment(int $editionid, int $userid): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            return null;
        }
        $record = $DB->get_record('local_ga_edition_enrolments', ['editionid' => $editionid, 'userid' => $userid]);
        return $record ?: null;
    }

    public static function get_edition_enrolment_count(int $editionid): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            return 0;
        }
        $sql = "SELECT COUNT(1)
                  FROM {local_ga_edition_enrolments}
                 WHERE editionid = :editionid
                   AND status IN ('enrolled', 'attended')";
        return (int)$DB->count_records_sql($sql, ['editionid' => $editionid]);
    }

    public static function enrol_user_in_edition(int $editionid, int $userid, string $source = 'self'): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $now = time();

        $result = (object)['success' => false, 'message' => ''];

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            $result->message = get_string('enroltablemissing', 'local_gestion_actividades');
            return $result;
        }

        if ($source !== 'manual' && !empty($edition->enrolenddate) && $now > (int)$edition->enrolenddate) {
            $result->message = get_string('enrolclosed', 'local_gestion_actividades');
            return $result;
        }

        $existing = self::get_edition_enrolment($editionid, $userid);
        if ($existing && $existing->status === 'enrolled') {
            $result->success = true;
            $result->message = get_string('alreadyenrolled', 'local_gestion_actividades');
            return $result;
        }

        $places = (int)($edition->places ?? 0);
        $enrolmentcount = self::get_edition_enrolment_count($editionid);

        // La incorporación manual puede superar fecha y aforo; después de guardar
        // se amplía el número de plazas para conservar las plazas ordinarias libres.
        if ($source !== 'manual' && $places > 0 && $enrolmentcount >= $places) {
            $result->message = get_string('editionfull', 'local_gestion_actividades');
            return $result;
        }

        try {
            if (empty($edition->groupid) || !$DB->record_exists('groups', ['id' => (int)$edition->groupid])) {
                $newgroupid = self::get_or_create_edition_group((int)$editionid);
                $edition->groupid = $newgroupid;
            }
            if (!empty($edition->groupid) && $DB->record_exists('groups', ['id' => (int)$edition->groupid])) {
                groups_add_member((int)$edition->groupid, $userid);
            }
        } catch (\Throwable $e) {
            // Do not block internal enrolment if group creation/add fails.
        }

        $record = (object)[
            'editionid' => $editionid,
            'userid' => $userid,
            'status' => 'enrolled',
            'source' => $source,
            'reason' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record = self::filter_record_to_existing_fields('local_ga_edition_enrolments', $record);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_ga_edition_enrolments', $record);
        } else {
            $DB->insert_record('local_ga_edition_enrolments', $record);
        }

        if ($source === 'manual' && $places > 0) {
            // Incremento atómico para evitar perder plazas si dos profesores añaden alumnado a la vez.
            $DB->execute("UPDATE {local_ga_workshop_editions}
                            SET places = places + 1
                          WHERE id = :editionid AND places > 0", ['editionid' => $editionid]);
        }

        $result->success = true;
        $result->message = get_string('enrolledok', 'local_gestion_actividades');
        return $result;
    }

    public static function format_workshop_date(?int $timestamp): string {
        if (empty($timestamp)) {
            return '-';
        }
        return userdate($timestamp, '%A %d/%m/%Y');
    }

    public static function get_cm_url_or_empty(int $cmid): string {
        global $DB;
        if (empty($cmid) || !$DB->record_exists('course_modules', ['id' => $cmid])) {
            return '';
        }
        return (new \moodle_url('/mod/' . self::get_modname_from_cmid($cmid) . '/view.php', ['id' => $cmid]))->out(false);
    }

    public static function get_modname_from_cmid(int $cmid): string {
        global $DB;
        $sql = "SELECT m.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.id = :cmid";
        return (string)$DB->get_field_sql($sql, ['cmid' => $cmid]);
    }



    public static function ensure_workshop_course_visual_with_message(int $workshopid): \stdClass {
        global $DB, $CFG;

        $result = (object)[
            'success' => false,
            'message' => '',
        ];

        try {
            require_once($CFG->dirroot . '/course/lib.php');

            $workshop = $DB->get_record('local_ga_workshops', ['id' => $workshopid], '*', MUST_EXIST);
            $edition = self::get_primary_workshop_edition($workshopid);
            $courseid = (int)$workshop->courseid;

            if (!$DB->record_exists('modules', ['name' => 'label'])) {
                $result->message = 'No existe o no está instalado el módulo label/etiqueta.';
                return $result;
            }
            if (!$DB->get_manager()->table_exists(new \xmldb_table('label'))) {
                $result->message = 'No existe la tabla label del módulo etiqueta.';
                return $result;
            }

            $sectionnum = self::get_or_create_course_section($courseid, self::get_main_workshop_section_name());
            $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum], '*', MUST_EXIST);
            $module = $DB->get_record('modules', ['name' => 'label'], '*', MUST_EXIST);

            $entrybase = self::get_workshop_course_entry_name($workshop);
            $hours = isset($workshop->hours) && $workshop->hours !== null && $workshop->hours !== ''
                ? round((float)$workshop->hours, 2) . ' h'
                : '-';
            $entryname = trim($workshop->code . ' - ' . $workshop->name);

            $viewurl = new \moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $workshop->id, 'editionid' => $edition ? (int)$edition->id : 0]);
            $enrolurl = $edition ? new \moodle_url('/local/gestion_actividades/enrol.php', ['id' => $edition->id]) : $viewurl;
            $date = $edition ? self::format_workshop_date((int)$edition->sessiondate) : '-';
            $description = trim((string)($workshop->description ?? ''));

            $remainingtext = '-';
            if ($edition) {
                $remaining = self::get_edition_remaining_places($edition);
                $remainingtext = $remaining === null ? get_string('unlimitedplaces', 'local_gestion_actividades') : (string)$remaining;
            }

            $intro = '<div class="local-ga-course-workshop" style="padding:.8rem 1rem;border-left:4px solid #0f6cbf;background:#f8f9fa;margin:.4rem 0;">';
            $intro .= '<div style="font-weight:700;font-size:1.08rem;">' . s($entryname) . '</div>';
            if ($description !== '') {
                $intro .= '<div style="margin-top:.25rem;">' . s($description) . '</div>';
            }
            $intro .= '<div style="margin-top:.4rem;">';
            $intro .= '<strong>' . get_string('date') . ':</strong> ' . s($date);
            $intro .= ' · <strong>' . get_string('workshophours', 'local_gestion_actividades') . ':</strong> ' . s($hours);
            $intro .= ' · <strong>' . get_string('remainingplaces', 'local_gestion_actividades') . ':</strong> ' . s($remainingtext);
            $intro .= '</div>';
            $intro .= '<div style="margin-top:.45rem;">';
            $intro .= '<a class="btn btn-primary" href="' . $enrolurl->out(false) . '">' . get_string('enrolme', 'local_gestion_actividades') . '</a> ';
            $intro .= '<a class="btn btn-secondary" href="' . $viewurl->out(false) . '">' . get_string('viewworkshop', 'local_gestion_actividades') . '</a>';
            $intro .= '<div style="margin-top:.35rem;color:#555;">' . get_string('frontstatusnote', 'local_gestion_actividades') . '</div>';
            $intro .= '</div>';
            $intro .= '</div>';

            $sql = "SELECT l.id AS labelid, cm.id AS cmid
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {label} l ON l.id = cm.instance
                     WHERE cm.course = :courseid
                       AND m.name = 'label'
                       AND (l.name LIKE :oldname OR l.name LIKE :newname)";
            $existing = $DB->get_records_sql($sql, [
                'courseid' => $courseid,
                'oldname' => $DB->sql_like_escape($entrybase) . '%',
                'newname' => $DB->sql_like_escape($entryname) . '%',
            ]);

            if ($existing) {
                $restored = false;
                foreach ($existing as $ex) {
                    $label = $DB->get_record('label', ['id' => $ex->labelid], '*', MUST_EXIST);
                    $label->name = $entryname;
                    $label->intro = $intro;
                    $label->introformat = FORMAT_HTML;
                    $label->timemodified = time();
                    $label = self::filter_object_to_columns('label', $label);
                    $DB->update_record('label', $label);
                    $restored = self::restore_cmid_to_course_section(
                        $courseid,
                        (int)$ex->cmid,
                        $sectionnum
                    ) || $restored;
                }
                $result->success = $restored;
                $result->message = $restored
                    ? $entryname . ': actualizado y verificado para alumnos.'
                    : $entryname . ': la etiqueta existe, pero Moodle no pudo insertarla en la sección del curso.';
                return $result;
            }

            $label = new \stdClass();
            $label->course = $courseid;
            $label->name = $entryname;
            $label->intro = $intro;
            $label->introformat = FORMAT_HTML;
            $label->timemodified = time();
            $label = self::filter_object_to_columns('label', $label);
            $instanceid = $DB->insert_record('label', $label);

            if (function_exists('add_course_module') && function_exists('course_add_cm_to_section')) {
                $cm = new \stdClass();
                $cm->course = $courseid;
                $cm->module = (int)$module->id;
                $cm->instance = (int)$instanceid;
                $cm->section = (int)$section->id;
                $cm->visible = 1;
                $cm->visibleoncoursepage = 1;
                $cm->visibleold = 1;
                $cm->groupmode = 0;
                $cm->groupingid = 0;
                $cm->completion = 0;
                $cm->showdescription = 0;
                $cm->availability = null;
                $cmid = add_course_module($cm);
                course_add_cm_to_section($courseid, $cmid, $sectionnum);
            } else {
                $cm = new \stdClass();
                $cm->course = $courseid;
                $cm->module = (int)$module->id;
                $cm->instance = (int)$instanceid;
                $cm->section = (int)$section->id;
                $cm->idnumber = '';
                $cm->added = time();
                $cm->score = 0;
                $cm->indent = 0;
                $cm->visible = 1;
                $cm->visibleoncoursepage = 1;
                $cm->visibleold = 1;
                $cm->groupmode = 0;
                $cm->groupingid = 0;
                $cm->completion = 0;
                $cm->completiongradeitemnumber = null;
                $cm->completionview = 0;
                $cm->completionexpected = 0;
                $cm->showdescription = 0;
                $cm->availability = null;
                $cm->deletioninprogress = 0;
                $cm = self::filter_object_to_columns('course_modules', $cm);
                $cmid = $DB->insert_record('course_modules', $cm);

                $sequence = trim((string)($section->sequence ?? ''));
                $items = $sequence === '' ? [] : array_filter(array_map('trim', explode(',', $sequence)), 'strlen');
                if (!in_array((string)$cmid, $items, true)) {
                    $items[] = (string)$cmid;
                    $section->sequence = implode(',', $items);
                    $section->timemodified = time();
                    $DB->update_record('course_sections', $section);
                }
            }

            rebuild_course_cache($courseid, true);
            $result->success = true;
            $result->message = $entryname . ': creado para alumnos.';
            return $result;

        } catch (\Throwable $e) {
            $result->message = 'Error generando taller ID ' . $workshopid . ': ' . $e->getMessage();
            return $result;
        }
    }

    public static function get_course_required_activity_options(int $courseid): array {
        global $DB;

        $options = [0 => get_string('norequiredactivity', 'local_gestion_actividades')];

        $sql = "SELECT cm.id,
                       m.name AS modname,
                       cm.instance,
                       cm.completion,
                       cm.visible
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid
                   AND m.name IN ('assign', 'quiz')
              ORDER BY m.name ASC, cm.id ASC";
        $cms = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        foreach ($cms as $cm) {
            $name = '';
            if ($cm->modname === 'assign' && $DB->record_exists('assign', ['id' => $cm->instance])) {
                $name = $DB->get_field('assign', 'name', ['id' => $cm->instance]);
            } else if ($cm->modname === 'quiz' && $DB->record_exists('quiz', ['id' => $cm->instance])) {
                $name = $DB->get_field('quiz', 'name', ['id' => $cm->instance]);
            }
            if ($name !== '') {
                $label = format_string($name) . ' — ' . get_string('modulename', $cm->modname) . ' — CMID ' . $cm->id;
                if (empty($cm->completion)) {
                    $label .= ' — ' . get_string('completionnotenabled', 'local_gestion_actividades');
                }
                $options[$cm->id] = $label;
            }
        }

        return $options;
    }

    public static function get_module_name_from_cmid(int $cmid): string {
        global $DB;
        if ($cmid <= 0) {
            return '';
        }
        $sql = "SELECT m.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.id = :cmid";
        return (string)$DB->get_field_sql($sql, ['cmid' => $cmid]);
    }

    public static function search_course_users(int $courseid, string $query, int $limit = 30): array {
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context, '', 0,
            'u.id, u.firstname, u.lastname, u.email, u.username, u.idnumber',
            'u.lastname ASC, u.firstname ASC', 0, $limit);

        $query = \core_text::strtolower(trim($query));
        if ($query === '') {
            return $users;
        }

        $filtered = [];
        foreach ($users as $user) {
            $haystack = \core_text::strtolower(
                fullname($user) . ' ' . $user->email . ' ' . $user->username . ' ' . $user->idnumber
            );
            if (strpos($haystack, $query) !== false) {
                $filtered[$user->id] = $user;
            }
        }
        return $filtered;
    }

    public static function manually_add_student_to_edition(int $editionid, int $userid, bool $force = false): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop($edition->workshopid);

        if (empty($edition->groupid)) {
            self::create_group_for_edition($editionid);
            $edition = self::get_workshop_edition($editionid);
        }

        $summary = (object)[
            'added' => 0,
            'blockedrepeat' => 0,
            'overplaces' => 0,
            'alreadyingroup' => 0,
            'message' => '',
        ];

        $alreadydone = $DB->record_exists('local_ga_completions', [
            'activitykey' => $workshop->code,
            'userid' => $userid,
        ]);

        if ($alreadydone && !$force) {
            $summary->blockedrepeat = 1;
            $summary->message = get_string('alreadycompletedworkshop', 'local_gestion_actividades');
            return $summary;
        }

        $currentmembers = $DB->count_records('groups_members', ['groupid' => $edition->groupid]);
        if ((int)$edition->places > 0 && $currentmembers >= (int)$edition->places && !$force) {
            $summary->overplaces = 1;
            $summary->message = get_string('editionfull', 'local_gestion_actividades');
            return $summary;
        }

        if (groups_is_member($edition->groupid, $userid)) {
            $summary->alreadyingroup = 1;
        } else {
            groups_add_member($edition->groupid, $userid);
        }

        $existing = $DB->get_record('local_ga_edition_enrolments', ['editionid' => $editionid, 'userid' => $userid]);
        if ($existing) {
            $existing->status = 'manual';
            $existing->reason = get_string('manualexception', 'local_gestion_actividades');
            $existing->timemodified = time();
            $DB->update_record('local_ga_edition_enrolments', $existing);
        } else {
            $DB->insert_record('local_ga_edition_enrolments', (object)[
                'editionid' => $editionid,
                'workshopid' => $edition->workshopid,
                'userid' => $userid,
                'groupid' => $edition->groupid,
                'status' => 'manual',
                'reason' => get_string('manualexception', 'local_gestion_actividades'),
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $summary->added = 1;
        $summary->message = get_string('studentmanualadded', 'local_gestion_actividades');
        return $summary;
    }

    public static function user_completed_required_activity(int $userid, int $cmid): bool {
        if ($cmid <= 0) {
            return true;
        }
        global $DB;
        $completion = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $userid]);
        if ($completion && (int)$completion->completionstate > 0) {
            return true;
        }
        return self::get_user_grade_for_cmid($userid, $cmid) !== null;
    }

    public static function get_edition_students_status(int $editionid): array {
        global $DB;
        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop($edition->workshopid);

        $sql = "SELECT ee.*, u.firstname, u.lastname, u.email, u.username, u.idnumber
                  FROM {local_ga_edition_enrolments} ee
                  JOIN {user} u ON u.id = ee.userid
                 WHERE ee.editionid = :editionid
              ORDER BY u.lastname, u.firstname";
        $rows = $DB->get_records_sql($sql, ['editionid' => $editionid]);

        foreach ($rows as $row) {
            $row->attended = $DB->record_exists('local_ga_completions', [
                'activitykey' => $workshop->code,
                'userid' => $row->userid,
            ]) ? 1 : 0;
            $row->requiredcompleted = self::user_completed_required_activity((int)$row->userid, (int)$edition->requiredcmid) ? 1 : 0;
            $row->activitygrade = self::get_user_grade_for_cmid((int)$row->userid, (int)$edition->requiredcmid);
            $minimumgrade = self::parse_decimal_input($edition->tasknumericgrade ?? null);
            $checkpoints = (($edition->quizgradingmode ?? 'completion') === 'points') || ($minimumgrade !== null && $minimumgrade > 0);
            if (!empty($edition->requiredcmid) && $checkpoints) {
                $row->requiredcompleted = ($row->activitygrade !== null && $row->activitygrade >= (float)$minimumgrade) ? 1 : 0;
            }
            // Hours are stored with the same rule as certificates (Type A task
            // >= 5, quiz >= 5/10, Type B attendance + reflection), never with the
            // legacy completion-only check above.
            try {
                $row->certificateeligible = self::user_is_certificate_eligible($editionid, (int)$row->userid) ? 1 : 0;
            } catch (\Throwable $e) {
                $row->certificateeligible = 0;
            }
            $row->certificatependingstore = $row->certificateeligible;
        }

        return $rows;
    }



    public static function format_action_icon(string $url, string $pix, string $label, string $btnclass = 'btn btn-secondary btn-sm'): string {
        global $OUTPUT;
        return \html_writer::link(
            $url,
            $OUTPUT->pix_icon($pix, $label),
            ['class' => $btnclass, 'title' => $label, 'aria-label' => $label]
        );
    }

    public static function get_authorized_managers(): array {
        $context = \context_system::instance();
        return get_users_by_capability(
            $context,
            'local/gestion_actividades:manage',
            'u.id, u.firstname, u.lastname, u.email, u.username',
            'u.lastname ASC, u.firstname ASC'
        );
    }

    public static function get_student_hour_history(int $userid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_hour_history'))) {
            return [];
        }
        return $DB->get_records('local_ga_hour_history', ['userid' => $userid], 'timecompleted DESC, id DESC');
    }

    /**
     * Net Type A hours of a student (after transfers to Type B), from the
     * canonical hours_calculator.
     */
    public static function get_student_total_hours(int $userid): float {
        if ($userid <= 0) {
            return 0.0;
        }
        return (float)hours_calculator::for_user($userid)->typeahours;
    }

    public static function get_hours_summary_by_student(): array {
        global $DB;

        $summary = [];

        $ensure = function(int $userid) use (&$summary): \stdClass {
            if (!isset($summary[$userid])) {
                $summary[$userid] = (object)[
                    'id' => $userid,
                    'firstname' => '',
                    'lastname' => '',
                    'email' => '',
                    'completedworkshops' => 0,
                    'validatedtypebcount' => 0,
                    'totaltypeahours' => 0.0,
                    'totaltypebhours' => 0.0,
                    'totalhours' => 0.0,
                ];
            }
            return $summary[$userid];
        };

        $hascoursehistory = $DB->get_manager()->table_exists(new \xmldb_table('local_ga_hour_history'));
        if ($hascoursehistory) {
            $history = $DB->get_records_sql("SELECT h.userid, COUNT(h.id) AS completedworkshops, COALESCE(SUM(h.hours), 0) AS totalhours
                                               FROM {local_ga_hour_history} h
                                               JOIN {local_ga_workshops} w ON w.id = h.workshopid
                                              WHERE (w.workshoptype = 'typea' OR w.workshoptype IS NULL OR w.workshoptype = '')
                                           GROUP BY h.userid");
            foreach ($history as $row) {
                $item = $ensure((int)$row->userid);
                $item->completedworkshops += (int)$row->completedworkshops;
                $item->totaltypeahours += (float)$row->totalhours;
            }
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))
            && $DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))) {
            $notexists = '';
            if ($hascoursehistory) {
                $notexists = " AND NOT EXISTS (SELECT 1 FROM {local_ga_hour_history} h WHERE h.userid = cert.userid AND h.editionid = cert.editionid)";
            }
            $certcolumns = $DB->get_columns('local_ga_certificates');
            $typeafilter = isset($certcolumns['certificatetype']) ? " AND (cert.certificatetype = 'typea' OR cert.certificatetype IS NULL OR cert.certificatetype = '')" : '';
            $certsql = "SELECT cert.userid, COUNT(cert.id) AS completedworkshops, COALESCE(SUM(w.hours), 0) AS totalhours
                          FROM {local_ga_certificates} cert
                     LEFT JOIN {local_ga_workshops} w ON w.id = cert.workshopid
                         WHERE cert.userid > 0 $notexists $typeafilter
                      GROUP BY cert.userid";
            $certs = $DB->get_records_sql($certsql);
            foreach ($certs as $row) {
                $item = $ensure((int)$row->userid);
                $item->completedworkshops += (int)$row->completedworkshops;
                $item->totaltypeahours += (float)$row->totalhours;
            }
            if (isset($certcolumns['certificatetype'])) {
                $typebs = $DB->get_records_sql("SELECT cert.userid, COUNT(cert.id) AS validatedtypebcount, COALESCE(SUM(w.hours), 0) AS totalhours
                                                   FROM {local_ga_certificates} cert
                                              LEFT JOIN {local_ga_workshops} w ON w.id = cert.workshopid
                                                  WHERE cert.userid > 0 AND cert.certificatetype = 'typeb'
                                               GROUP BY cert.userid");
                foreach ($typebs as $row) {
                    $item = $ensure((int)$row->userid);
                    $item->validatedtypebcount += (int)$row->validatedtypebcount;
                    $item->totaltypebhours += (float)$row->totalhours;
                }
            }
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_certs'))) {
            $typeb = $DB->get_records_sql("SELECT userid, COUNT(id) AS validatedtypebcount, COALESCE(SUM(hours), 0) AS totalhours
                                             FROM {local_ga_typeb_certs}
                                            WHERE status = :status
                                         GROUP BY userid", ['status' => 'validated']);
            foreach ($typeb as $row) {
                $item = $ensure((int)$row->userid);
                $item->validatedtypebcount += (int)$row->validatedtypebcount;
                $item->totaltypebhours += (float)$row->totalhours;
            }
        }

        if (class_exists('local_gestion_actividades\\local\\institutional_hours')) {
            try {
                institutional_hours::ensure_table();
                $institutional = $DB->get_records_sql("SELECT userid, COALESCE(SUM(typeahours), 0) AS typeahours, COALESCE(SUM(typebhours), 0) AS typebhours
                                                          FROM {local_ga_institutional_hours}
                                                         WHERE userid > 0
                                                      GROUP BY userid");
                foreach ($institutional as $row) {
                    $item = $ensure((int)$row->userid);
                    $item->totaltypeahours += (float)$row->typeahours;
                    $item->totaltypebhours += (float)$row->typebhours;
                }
            } catch (\Throwable $e) {
                if (function_exists('debugging')) {
                    debugging('No se han podido sumar horas de reconocimiento institucional: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_transfers'))) {
            $transfers = $DB->get_records_sql("SELECT userid, COUNT(id) AS cnt, COALESCE(SUM(hours), 0) AS hours
                                                 FROM {local_ga_typeb_transfers}
                                                WHERE status = :status
                                             GROUP BY userid", ['status' => 'active']);
            foreach ($transfers as $row) {
                $item = $ensure((int)$row->userid);
                $hours = (float)($row->hours ?? 0);
                $item->totaltypeahours = max(0.0, (float)$item->totaltypeahours - $hours);
                $item->totaltypebhours += $hours;
                $item->validatedtypebcount += (int)($row->cnt ?? 0);
            }
        }

        if (!$summary) {
            return [];
        }

        list($usersql, $params) = $DB->get_in_or_equal(array_keys($summary), SQL_PARAMS_NAMED);
        $users = $DB->get_records_select('user', 'id ' . $usersql . ' AND deleted = 0', $params, 'lastname ASC, firstname ASC', 'id, firstname, lastname, email');
        // Hours always come from the canonical calculator (same figures as the
        // portfolio, the gradebook and the block); the queries above only count.
        $canonical = hours_calculator::for_users(array_keys($users));
        $out = [];
        foreach ($users as $user) {
            $item = $summary[(int)$user->id];
            $item->firstname = $user->firstname;
            $item->lastname = $user->lastname;
            $item->email = $user->email;
            $hours = $canonical[(int)$user->id];
            $item->totaltypeahours = $hours->typeahours;
            $item->totaltypebhours = $hours->typebhours;
            $item->totalhours = $hours->totalhours;
            if ($item->totalhours > 0 || $item->completedworkshops > 0 || $item->validatedtypebcount > 0) {
                $out[(int)$user->id] = $item;
            }
        }
        return $out;
    }

    public static function store_completed_hour_record(int $editionid, int $userid): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_hour_history'))) {
            return false;
        }

        if ($DB->record_exists('local_ga_hour_history', ['editionid' => $editionid, 'userid' => $userid])) {
            return false;
        }

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop($edition->workshopid);
        $hours = isset($workshop->hours) && $workshop->hours !== null ? (float)$workshop->hours : 0.0;

        $record = (object)[
            'userid' => $userid,
            'courseid' => (int)$workshop->courseid,
            'workshopid' => (int)$workshop->id,
            'editionid' => (int)$editionid,
            'workshopcode' => $workshop->code,
            'workshopname' => $workshop->name,
            'editionname' => $edition->name,
            'hours' => $hours,
            'certificatecmid' => (int)($edition->certificatecmid ?? 0),
            'certificatestatus' => 'pending',
            'timecompleted' => time(),
            'timecreated' => time(),
        ];

        $DB->insert_record('local_ga_hour_history', $record);
        self::invalidate_block_cache_for_user($userid);
        return true;
    }

    public static function refresh_completed_hours_for_edition(int $editionid): int {
        $rows = self::get_edition_students_status($editionid);
        $created = 0;
        foreach ($rows as $row) {
            if (!empty($row->certificateeligible)) {
                if (self::store_completed_hour_record($editionid, (int)$row->userid)) {
                    $created++;
                }
            }
        }
        if ($created > 0) {
            $edition = self::get_workshop_edition($editionid);
            $workshop = self::get_workshop((int)$edition->workshopid);
            grade_manager::sync_course_safely((int)$workshop->courseid);
        }
        return $created;
    }




    public static function delete_workshop(int $workshopid): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $summary = (object)[
            'workshopid' => $workshopid,
            'editionsdeleted' => 0,
            'groupsdeleted' => 0,
            'labelsdeleted' => 0,
            'workshopdeleted' => 0,
        ];

        $workshop = self::get_workshop($workshopid);
        $summary->labelsdeleted = self::delete_workshop_course_entries($workshop);

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            $editions = $DB->get_records('local_ga_workshop_editions', ['workshopid' => $workshopid]);
            foreach ($editions as $edition) {
                if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_teachers'))) {
                    self::invalidate_teacher_block_cache([], [(int)$edition->id]);
                    $DB->delete_records('local_ga_edition_teachers', ['editionid' => $edition->id]);
                }
                if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
                    $DB->delete_records('local_ga_edition_enrolments', ['editionid' => $edition->id]);
                }
                if (!empty($edition->groupid) && $DB->record_exists('groups', ['id' => $edition->groupid])) {
                    try {
                        groups_delete_group($edition->groupid);
                        $summary->groupsdeleted++;
                    } catch (\Throwable $e) {
                        // Continue.
                    }
                }
                $DB->delete_records('local_ga_workshop_editions', ['id' => $edition->id]);
                $summary->editionsdeleted++;
            }
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_hour_history'))) {
            $DB->delete_records('local_ga_hour_history', ['workshopid' => $workshopid]);
        }

        $DB->delete_records('local_ga_workshops', ['id' => $workshopid]);
        $summary->workshopdeleted = 1;
        rebuild_course_cache((int)$workshop->courseid, true);

        return $summary;
    }


    public static function cleanup_generated_course_entries_for_course(int $courseid): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $count = 0;
        $targets = [
            ['mod' => 'label', 'table' => 'label', 'alias' => 'l'],
            ['mod' => 'url', 'table' => 'url', 'alias' => 'u'],
            ['mod' => 'page', 'table' => 'page', 'alias' => 'p'],
        ];

        foreach ($targets as $target) {
            if (!$DB->record_exists('modules', ['name' => $target['mod']]) || !$DB->get_manager()->table_exists(new \xmldb_table($target['table']))) {
                continue;
            }

            $alias = $target['alias'];
            $sql = "SELECT cm.id
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {" . $target['table'] . "} $alias ON $alias.id = cm.instance
                     WHERE cm.course = :courseid
                       AND m.name = :modname
                       AND (" . $DB->sql_like("$alias.name", ':typea', false) . "
                            OR " . $DB->sql_like("$alias.name", ':legacy', false) . ")";
            $records = $DB->get_records_sql($sql, [
                'courseid' => $courseid,
                'modname' => $target['mod'],
                'typea' => '[Taller Tipo A]%',
                'legacy' => 'Taller Tipo A%',
            ]);

            foreach ($records as $record) {
                try {
                    course_delete_module((int)$record->id);
                    $count++;
                } catch (\Throwable $e) {
                    // Continue with the rest.
                }
            }
        }

        if ($count > 0) {
            rebuild_course_cache($courseid, true);
        }
        return $count;
    }

    public static function delete_workshop_course_entries(\stdClass $workshop): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $count = 0;
        $entryname = self::get_workshop_course_entry_name($workshop);
        $courseid = (int)$workshop->courseid;
        $workshopid = (int)$workshop->id;

        $targets = [
            ['mod' => 'label', 'table' => 'label', 'alias' => 'l', 'fields' => ['name', 'intro']],
            ['mod' => 'url', 'table' => 'url', 'alias' => 'u', 'fields' => ['name', 'intro', 'externalurl']],
            ['mod' => 'page', 'table' => 'page', 'alias' => 'p', 'fields' => ['name', 'content']],
        ];

        $patterns = [
            $DB->sql_like_escape($entryname) . '%',
            '%' . $DB->sql_like_escape('workshop_view.php?id=' . $workshopid) . '%',
            '%' . $DB->sql_like_escape('id=' . $workshopid) . '%',
            '%' . $DB->sql_like_escape((string)$workshop->code) . '%',
            '%' . $DB->sql_like_escape((string)$workshop->name) . '%',
        ];

        $deleted = [];

        foreach ($targets as $target) {
            if (!$DB->record_exists('modules', ['name' => $target['mod']]) || !$DB->get_manager()->table_exists(new \xmldb_table($target['table']))) {
                continue;
            }

            $alias = $target['alias'];
            $conditions = [];
            foreach ($target['fields'] as $field) {
                $conditions[] = $DB->sql_like($alias . '.' . $field, ':' . $field, false);
            }

            $sql = "SELECT cm.id
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {" . $target['table'] . "} $alias ON $alias.id = cm.instance
                     WHERE cm.course = :courseid
                       AND m.name = :modname
                       AND (" . implode(' OR ', $conditions) . ")";

            foreach ($patterns as $pattern) {
                $params = [
                    'courseid' => $courseid,
                    'modname' => $target['mod'],
                ];
                foreach ($target['fields'] as $field) {
                    $params[$field] = $pattern;
                }

                $cms = $DB->get_records_sql($sql, $params);
                foreach ($cms as $cm) {
                    $cmid = (int)$cm->id;
                    if (isset($deleted[$cmid])) {
                        continue;
                    }
                    try {
                        course_delete_module($cmid);
                        $deleted[$cmid] = true;
                        $count++;
                    } catch (\Throwable $e) {
                        // Continue.
                    }
                }
            }
        }

        if ($count > 0) {
            rebuild_course_cache($courseid, true);
        }

        return $count;
    }

    public static function delete_workshop_edition(int $editionid, bool $deletegroup = true): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $edition = self::get_workshop_edition($editionid);
        $summary = (object)[
            'editionid' => $editionid,
            'groupid' => (int)$edition->groupid,
            'groupdeleted' => 0,
            'editiondeleted' => 0,
        ];

        self::invalidate_teacher_block_cache([], [(int)$editionid]);
        $DB->delete_records('local_ga_edition_teachers', ['editionid' => $editionid]);
        $DB->delete_records('local_ga_edition_enrolments', ['editionid' => $editionid]);

        if ($deletegroup && !empty($edition->groupid) && $DB->record_exists('groups', ['id' => $edition->groupid])) {
            groups_delete_group($edition->groupid);
            $summary->groupdeleted = 1;
        }

        $DB->delete_records('local_ga_workshop_editions', ['id' => $editionid]);
        $summary->editiondeleted = 1;

        return $summary;
    }



    public static function get_course_group_options(int $courseid, bool $includeempty = true): array {
        global $DB;
        $options = [];
        if ($includeempty) {
            $options[0] = get_string('nogroupselected', 'local_gestion_actividades');
        }

        $groups = self::get_course_groups($courseid);
        foreach ($groups as $group) {
            $count = $DB->count_records('groups_members', ['groupid' => $group->id]);
            $options[$group->id] = format_string($group->name) . ' (' . $count . ' ' . get_string('studentscount', 'local_gestion_actividades') . ')';
        }

        return $options;
    }



    public static function create_group_for_edition(int $editionid): int {
        // Disabled in safe mode. Groups will be re-enabled after basic saving is confirmed.
        return 0;
    }

    public static function get_course_options(): array {
        global $DB;
        $courses = $DB->get_records_sql("SELECT id, fullname, shortname FROM {course} WHERE id <> 1 ORDER BY fullname ASC");
        $options = [];
        foreach ($courses as $course) {
            $options[$course->id] = format_string($course->fullname) . ' [' . s($course->shortname) . '] — ID ' . $course->id;
        }
        return $options;
    }



    public static function list_workshops(int $courseid = 0, string $type = ''): array {
        global $DB;
        $params = [];
        $conds = [];
        if ($courseid > 0) {
            $conds[] = 'courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($type !== '' && $DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))) {
            $columns = $DB->get_columns('local_ga_workshops');
            if (isset($columns['workshoptype'])) {
                $conds[] = 'workshoptype = :workshoptype';
                $params['workshoptype'] = self::normalize_workshop_type($type);
            } else if (self::normalize_workshop_type($type) === 'typeb') {
                return [];
            }
        }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        return $DB->get_records_sql("SELECT * FROM {local_ga_workshops} $where ORDER BY code ASC, name ASC", $params);
    }

    public static function get_workshop(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_ga_workshops', ['id' => $id], '*', MUST_EXIST);
    }

    
    public static function filter_record_to_existing_fields(string $tablename, \stdClass $record): \stdClass {
        global $DB;

        $filtered = new \stdClass();
        $columns = $DB->get_columns($tablename);
        foreach ((array)$record as $key => $value) {
            if (isset($columns[$key])) {
                $filtered->$key = $value;
            }
        }
        return $filtered;
    }

    public static function get_dml_debug_message(\Throwable $e): string {
        $message = $e->getMessage();
        if ($message === '') {
            $message = get_class($e);
        }
        return $message;
    }

    public static function parse_decimal_input($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        $value = trim((string)$value);
        $value = str_replace(' ', '', $value);
        $value = str_replace(',', '.', $value);
        return is_numeric($value) ? (float)$value : null;
    }

    public static function format_date_compact(int $timestamp): string {
        if (empty($timestamp)) {
            return '-';
        }
        return userdate($timestamp, '%A %d/%m/%Y');
    }



    public static function generate_workshop_code(string $name, int $courseid, int $excludeid = 0): string {
        global $DB;

        $clean = \core_text::strtoupper(trim($name));
        $clean = preg_replace('/[^A-ZÁÉÍÓÚÜÑ0-9 ]/u', ' ', $clean);
        $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);

        $stop = ['DE','DEL','LA','LAS','EL','LOS','Y','E','EN','A','PARA','POR','CON','UN','UNA','TIPO'];
        $letters = '';
        foreach ($words as $word) {
            if (in_array($word, $stop, true)) {
                continue;
            }
            $letters .= \core_text::substr($word, 0, 1);
            if (\core_text::strlen($letters) >= 4) {
                break;
            }
        }

        if ($letters === '') {
            $letters = 'TAL';
        }

        $letters = strtr($letters, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N'
        ]);
        $base = preg_replace('/[^A-Z0-9]/', '', $letters);
        if ($base === '') {
            $base = 'TAL';
        }

        $code = $base;
        $i = 2;
        while (true) {
            $params = ['courseid' => $courseid, 'code' => $code];
            $sql = "courseid = :courseid AND code = :code";
            if ($excludeid > 0) {
                $sql .= " AND id <> :excludeid";
                $params['excludeid'] = $excludeid;
            }
            if (!$DB->record_exists_select('local_ga_workshops', $sql, $params)) {
                return $code;
            }
            $code = $base . $i;
            $i++;
        }
    }

    public static function save_workshop(\stdClass $data): int {
        global $DB;
        $now = time();
        $id = !empty($data->id) ? (int)$data->id : 0;

        $code = trim($data->code ?? '');
        if ($code === '') {
            $code = self::generate_workshop_code((string)$data->name, (int)$data->courseid, $id);
        }

        $record = (object)[
            'courseid' => (int)$data->courseid,
            'code' => $code,
            'name' => trim($data->name),
            'description' => clean_param($data->description ?? '', PARAM_TEXT),
            'allowrepeat' => 0,
            'timemodified' => $now,
        ];

        $columns = $DB->get_columns('local_ga_workshops');
        if (isset($columns['hours']) && isset($data->hours) && trim((string)$data->hours) !== '') {
            $record->hours = self::parse_decimal_input($data->hours);
        }
        if (isset($columns['sectionnum'])) {
            $record->sectionnum = (int)($data->sectionnum ?? 0);
        }
        if (isset($columns['workshoptype'])) {
            $record->workshoptype = self::normalize_workshop_type((string)($data->workshoptype ?? 'typea'));
        }

        if ($id > 0) {
            $record->id = $id;
            $DB->update_record('local_ga_workshops', $record);
            $workshopid = $id;
            if (property_exists($record, 'hours')) {
                self::invalidate_block_cache_for_workshop_users($workshopid);
            }
        } else {
            $record->timecreated = $now;
            $workshopid = $DB->insert_record('local_ga_workshops', $record);
        }

        // No publicar todavía en el curso al crear solo la ficha básica del taller.
        // La publicación se hace después de guardar la edición/configuración completa.
        return $workshopid;
    }

    public static function list_workshop_editions(int $workshopid = 0): array {
        global $DB;
        if ($workshopid > 0) {
            return $DB->get_records('local_ga_workshop_editions', ['workshopid' => $workshopid], 'sessiondate ASC, id ASC');
        }
        return $DB->get_records('local_ga_workshop_editions', null, 'sessiondate ASC, id ASC');
    }

    public static function get_workshop_edition(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_ga_workshop_editions', ['id' => $id], '*', MUST_EXIST);
    }

    public static function get_course_teachers(int $courseid): array {
        $context = \context_course::instance($courseid);
        $teachers = get_enrolled_users($context, 'moodle/course:update', 0, 'u.id, u.firstname, u.lastname, u.email', 'u.lastname ASC, u.firstname ASC');
        if (!$teachers) {
            $teachers = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname, u.email', 'u.lastname ASC, u.firstname ASC');
        }
        return $teachers;
    }

    public static function save_workshop_edition(\stdClass $data): int {
        global $DB;
        $now = time();
        $id = !empty($data->id) ? (int)$data->id : 0;
        $workshop = self::get_workshop((int)$data->workshopid);
        $mandatoryactivitytype = self::is_typeb_workshop($workshop) ? '' : 'assign';

        // Minimal safe edition record. Automatic group/activity/certificate creation is disabled in this version.
        $record = (object)[
            'workshopid' => (int)$data->workshopid,
            'name' => trim($data->name),
            'editioncode' => trim($data->editioncode),
            'sessiondate' => (int)($data->sessiondate ?? 0),
            'enrolenddate' => (int)($data->enrolenddate ?? 0),
            'places' => (int)($data->places ?? 0),
            'status' => trim($data->status ?? 'open'),
            'timemodified' => $now,
        ];

        $columns = $DB->get_columns('local_ga_workshop_editions');
        $optional = [
            'activityid' => (int)($data->activityid ?? 0),
            'groupid' => (int)($data->groupid ?? 0),
            'attendancecmid' => (int)($data->attendancecmid ?? 0),
            'certificatecmid' => (int)($data->certificatecmid ?? 0),
            'requiredcmid' => (int)($data->requiredcmid ?? 0),
            'requiredmodname' => $mandatoryactivitytype,
            'activitycreationtype' => $mandatoryactivitytype,
            'tasknumericgrade' => self::parse_decimal_input($data->tasknumericgrade ?? null),
            'quizgradingmode' => trim($data->quizgradingmode ?? 'completion'),
            'archived' => (int)($data->archived ?? 0),
            'timearchived' => (int)($data->timearchived ?? 0),
        ];

        foreach ($optional as $field => $value) {
            if (isset($columns[$field])) {
                $record->$field = $value;
            }
        }

        if ($id > 0) {
            $old = $DB->get_record('local_ga_workshop_editions', ['id' => $id], '*', MUST_EXIST);

            foreach (['groupid'] as $field) {
                if (isset($columns[$field]) && empty($record->$field) && isset($old->$field)) {
                    $record->$field = $old->$field;
                }
            }

            $record->id = $id;
            $DB->update_record('local_ga_workshop_editions', $record);
            $editionid = $id;
        } else {
            $record->timecreated = $now;
            $editionid = $DB->insert_record('local_ga_workshop_editions', $record);
        }

        // Campos base del taller editables desde la pantalla completa de edición.
        if (isset($data->workshophours) || isset($data->workshopname) || isset($data->workshopdescription)) {
            $wrecord = (object)['id' => (int)$data->workshopid, 'timemodified' => $now];
            $wcolumns = $DB->get_columns('local_ga_workshops');
            if (isset($data->workshophours) && trim((string)$data->workshophours) !== '' && isset($wcolumns['hours'])) {
                $wrecord->hours = self::parse_decimal_input($data->workshophours);
            }
            if (isset($data->workshopname) && trim((string)$data->workshopname) !== '') {
                $wrecord->name = trim((string)$data->workshopname);
            }
            if (isset($data->workshopdescription)) {
                $wrecord->description = clean_param($data->workshopdescription, PARAM_TEXT);
            }
            $DB->update_record('local_ga_workshops', self::filter_record_to_existing_fields('local_ga_workshops', $wrecord));
            if (property_exists($wrecord, 'hours')) {
                self::invalidate_block_cache_for_workshop_users((int)$data->workshopid);
            }
        }

        // Los Tipo B no tienen actividad calificable; solo en ellos se desvincula.
        if ($mandatoryactivitytype === '') {
            $clear = (object)[
                'id' => $editionid,
                'requiredcmid' => 0,
                'requiredassigncmid' => 0,
                'requiredquizcmid' => 0,
                'requiredmodname' => '',
                'activitycreationtype' => '',
                'timemodified' => $now,
            ];
            $DB->update_record('local_ga_workshop_editions', self::filter_record_to_existing_fields('local_ga_workshop_editions', $clear));
        }

        // Crear/asegurar el grupo de la edición de forma interna, sin mostrar avisos al profesor.
        try {
            self::get_or_create_edition_group((int)$editionid);
        } catch (\Throwable $e) {
            // Non-blocking.
        }

        // Save teachers only if the table exists; do not let it break the edition save.
        if (isset($data->teachers) && is_array($data->teachers)) {
            try {
                self::save_edition_teachers($editionid, $data->teachers);
            } catch (\Throwable $e) {
                // Non-blocking.
            }
        }

        // Publish or refresh the student-facing card immediately after the complete edition is saved.
        // This keeps the course view in sync without requiring the manual repair action.
        try {
            $freshworkshop = self::get_workshop((int)$data->workshopid);
            if (self::is_workshop_publishable($freshworkshop)) {
                self::ensure_workshop_course_visuals_safely((int)$freshworkshop->id);
            }
        } catch (\Throwable $e) {
            // Non-blocking: the edition remains saved and the repair action can still be used.
        }

        return $editionid;
    }

    /**
     * Refresh the Profesor HEE block counters (vigentes / finalizados) of the
     * given teachers, or of every teacher assigned to the given editions.
     */
    public static function invalidate_teacher_block_cache(array $userids = [], array $editionids = []): void {
        global $DB;
        if (!class_exists('\\block_gestion_hee\\local\\teacher_workshops_cache')) {
            return;
        }
        try {
            $editionids = array_values(array_filter(array_map('intval', $editionids)));
            if ($editionids && $DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_teachers'))) {
                [$insql, $params] = $DB->get_in_or_equal($editionids, SQL_PARAMS_NAMED, 'it');
                $userids = array_merge($userids, $DB->get_fieldset_select('local_ga_edition_teachers', 'userid', "editionid $insql", $params));
            }
            \block_gestion_hee\local\teacher_workshops_cache::invalidate_users($userids);
        } catch (\Throwable $e) {
            debugging('No se pudo refrescar la caché docente de Gestión HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public static function save_edition_teachers(int $editionid, array $teachers): void {
        global $DB;

        $previous = $DB->get_fieldset_select('local_ga_edition_teachers', 'userid', 'editionid = :editionid', ['editionid' => $editionid]);
        $DB->delete_records('local_ga_edition_teachers', ['editionid' => $editionid]);

        $seen = [];
        foreach ($teachers as $userid) {
            $userid = (int)$userid;
            if ($userid <= 0 || isset($seen[$userid]) || !$DB->record_exists('user', ['id' => $userid])) {
                continue;
            }
            $seen[$userid] = true;

            try {
                $DB->insert_record('local_ga_edition_teachers', (object)[
                    'editionid' => $editionid,
                    'userid' => $userid,
                    'timecreated' => time(),
                ]);
            } catch (\Throwable $e) {
                continue;
            }
        }
        self::invalidate_teacher_block_cache(array_merge($previous, array_keys($seen)));
    }

    public static function get_edition_teachers(int $editionid): array {
        global $DB;
        return $DB->get_records_sql("SELECT u.id, u.firstname, u.lastname, u.email
                                       FROM {local_ga_edition_teachers} et
                                       JOIN {user} u ON u.id = et.userid
                                      WHERE et.editionid = :editionid
                                   ORDER BY u.lastname, u.firstname", ['editionid' => $editionid]);
    }

    public static function sync_edition_enrolments_from_group(int $editionid): \stdClass {
        global $DB;
        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop($edition->workshopid);
        if (empty($edition->groupid)) {
            throw new \moodle_exception('editionnogroup', 'local_gestion_actividades');
        }
        $group = $DB->get_record('groups', ['id' => $edition->groupid], '*', MUST_EXIST);
        if ((int)$group->courseid !== (int)$workshop->courseid) {
            throw new \moodle_exception('groupwrongcourse', 'local_gestion_actividades');
        }

        $members = groups_get_members($edition->groupid, 'u.id, u.firstname, u.lastname, u.email, u.username, u.idnumber');
        $summary = (object)[
            'members' => count($members),
            'inserted' => 0,
            'blockedrepeat' => 0,
            'overplaces' => 0,
            'closed' => 0,
        ];

        $DB->delete_records('local_ga_edition_enrolments', ['editionid' => $editionid]);

        $countaccepted = 0;
        foreach ($members as $user) {
            $alreadydone = $DB->record_exists('local_ga_completions', [
                'activitykey' => $workshop->code,
                'userid' => $user->id,
            ]);

            $status = 'enrolled';
            $reason = '';
            if ($alreadydone) {
                $status = 'blocked_repeat';
                $reason = get_string('alreadycompletedworkshop', 'local_gestion_actividades');
                $summary->blockedrepeat++;
            } else if ($edition->places > 0 && $countaccepted >= $edition->places) {
                $status = 'over_places';
                $reason = get_string('editionfull', 'local_gestion_actividades');
                $summary->overplaces++;
            } else {
                $countaccepted++;
                $summary->inserted++;
            }

            $DB->insert_record('local_ga_edition_enrolments', (object)[
                'editionid' => $editionid,
                'workshopid' => $edition->workshopid,
                'userid' => $user->id,
                'groupid' => $edition->groupid,
                'status' => $status,
                'reason' => $reason,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        if ($edition->places > 0 && $countaccepted >= $edition->places) {
            $edition->status = 'closed_full';
            $edition->timemodified = time();
            $DB->update_record('local_ga_workshop_editions', $edition);
            $summary->closed = 1;
        }

        return $summary;
    }



    public static function get_course_groups(int $courseid): array {
        global $DB;
        return $DB->get_records('groups', ['courseid' => $courseid], 'name ASC');
    }

    public static function set_participants_from_group(int $activityid, int $groupid): \stdClass {
        global $DB;

        $activity = self::get_activity($activityid);
        $group = $DB->get_record('groups', ['id' => $groupid], '*', MUST_EXIST);
        if ((int)$group->courseid !== (int)$activity->courseid) {
            throw new \moodle_exception('groupwrongcourse', 'local_gestion_actividades');
        }

        $members = groups_get_members($groupid, 'u.id, u.firstname, u.lastname, u.email, u.username, u.idnumber');

        $summary = (object)[
            'groupid' => $groupid,
            'groupname' => $group->name,
            'members' => count($members),
            'inserted' => 0,
            'overplaces' => 0,
        ];

        // Replace participant list with the real enrolled/self-selected workshop group.
        $DB->delete_records('local_ga_participants', ['activityid' => $activityid]);

        $rank = 1;
        foreach ($members as $user) {
            $candidate = $DB->get_record('local_ga_candidates', ['activityid' => $activityid, 'userid' => $user->id]);
            $grade = $candidate && $candidate->grade !== null ? $candidate->grade : null;

            $record = (object)[
                'activityid' => $activityid,
                'candidateid' => $candidate ? $candidate->id : null,
                'userid' => $user->id,
                'grade' => $grade,
                'rank' => $candidate && $candidate->rank ? $candidate->rank : $rank,
                'groupid' => $groupid,
                'status' => 'enrolled',
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            $DB->insert_record('local_ga_participants', $record);
            $summary->inserted++;
            $rank++;
        }

        if ((int)$activity->places > 0 && $summary->members > (int)$activity->places) {
            $summary->overplaces = $summary->members - (int)$activity->places;
        }

        return $summary;
    }



    public static function attendance_tables_available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('attendance'))
            && $dbman->table_exists(new \xmldb_table('attendance_sessions'))
            && $dbman->table_exists(new \xmldb_table('attendance_log'))
            && $dbman->table_exists(new \xmldb_table('attendance_statuses'));
    }

    public static function get_attendance_sessions(int $courseid): array {
        global $DB;
        if (!self::attendance_tables_available()) {
            return [];
        }
        $sql = "SELECT s.id,
                       a.name AS attendancename,
                       s.sessdate,
                       s.duration
                  FROM {attendance} a
                  JOIN {attendance_sessions} s ON s.attendanceid = a.id
                 WHERE a.course = :courseid
              ORDER BY s.sessdate DESC, s.id DESC";
        return $DB->get_records_sql($sql, ['courseid' => $courseid]);
    }

    public static function sync_attendance_session(int $activityid, int $sessionid): \stdClass {
        global $DB;
        $activity = self::get_activity($activityid);

        $summary = (object)[
            'sessionid' => $sessionid,
            'processed' => 0,
            'attended' => 0,
            'notpresent' => 0,
            'nolog' => 0,
            'alreadycompleted' => 0,
        ];

        if (!self::attendance_tables_available()) {
            throw new \moodle_exception('attendancenotavailable', 'local_gestion_actividades');
        }

        $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $attendance = $DB->get_record('attendance', ['id' => $session->attendanceid], '*', MUST_EXIST);
        if ((int)$attendance->course !== (int)$activity->courseid) {
            throw new \moodle_exception('attendancesessionwrongcourse', 'local_gestion_actividades');
        }

        $participants = $DB->get_records('local_ga_participants', ['activityid' => $activityid]);
        foreach ($participants as $participant) {
            $summary->processed++;

            $log = $DB->get_record('attendance_log', [
                'sessionid' => $sessionid,
                'studentid' => $participant->userid,
            ]);

            if (!$log) {
                $summary->nolog++;
                continue;
            }

            $status = $DB->get_record('attendance_statuses', ['id' => $log->statusid]);
            if (!$status || !empty($status->deleted) || (float)$status->grade <= 0) {
                $summary->notpresent++;
                continue;
            }

            if ($DB->record_exists('local_ga_completions', [
                'activitykey' => $activity->activitykey,
                'userid' => $participant->userid,
            ])) {
                $summary->alreadycompleted++;
            } else {
                $DB->insert_record('local_ga_completions', (object)[
                    'activitykey' => $activity->activitykey,
                    'activityid' => $activityid,
                    'userid' => $participant->userid,
                    'status' => 'attended',
                    'source' => 'attendance_session_' . $sessionid,
                    'timecompleted' => time(),
                ]);
            }

            $participant->status = 'attended';
            $participant->timemodified = time();
            $DB->update_record('local_ga_participants', $participant);

            if (!empty($participant->candidateid)) {
                $candidate = $DB->get_record('local_ga_candidates', ['id' => $participant->candidateid]);
                if ($candidate) {
                    $candidate->status = 'attended';
                    $candidate->reason = 'Asistencia registrada en Moodle Attendance. Sesión ID: ' . $sessionid;
                    $DB->update_record('local_ga_candidates', $candidate);
                }
            }

            $summary->attended++;
        }

        return $summary;
    }



    public static function process_users_csv(string $filepath, string $filename, bool $updateexisting = false): \stdClass {
        global $DB, $CFG;

        // The tool only reconciles the CSV with existing institutional accounts
        // (no account is ever created). Updating existing users is a core
        // administrative action: HEE authorisation alone must never grant it.
        if ($updateexisting) {
            require_capability('moodle/user:update', \context_system::instance());
        }

        $rows = self::read_csv($filepath);
        if (empty($rows)) {
            throw new \moodle_exception('errorcsvempty', 'local_gestion_actividades');
        }

        $headers = array_map([self::class, 'normalise_header'], array_shift($rows));
        $emailindex = array_search('email', $headers, true);
        $usernameindex = array_search('username', $headers, true);
        $firstnameindex = array_search('firstname', $headers, true);
        if ($firstnameindex === false) { $firstnameindex = array_search('nombre', $headers, true); }
        $lastnameindex = array_search('lastname', $headers, true);
        if ($lastnameindex === false) { $lastnameindex = array_search('apellidos', $headers, true); }
        $idnumberindex = array_search('idnumber', $headers, true);
        $cityindex = array_search('city', $headers, true);
        $countryindex = array_search('country', $headers, true);
        $passwordindex = array_search('password', $headers, true);

        if ($emailindex === false || $firstnameindex === false || $lastnameindex === false) {
            throw new \moodle_exception('errormissingusercolumns', 'local_gestion_actividades');
        }

        $summary = (object)[
            'filename' => $filename,
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'invalid' => 0,
            'duplicates' => 0,
            'rows' => [],
        ];

        $seenemails = [];
        $seenusernames = [];
        foreach ($rows as $rownum => $row) {
            $summary->total++;
            $email = \core_text::strtolower(trim(self::cell($row, $emailindex)));
            $firstname = trim(self::cell($row, $firstnameindex));
            $lastname = trim(self::cell($row, $lastnameindex));
            $username = trim(self::cell($row, $usernameindex));
            $idnumber = trim(self::cell($row, $idnumberindex));
            $city = trim(self::cell($row, $cityindex));
            $country = strtoupper(trim(self::cell($row, $countryindex)));
            $password = trim(self::cell($row, $passwordindex));

            if ($username === '' && $email !== '') {
                $username = preg_replace('/@.*/', '', $email);
            }
            $username = \core_text::strtolower(clean_param($username, PARAM_USERNAME));

            $result = (object)[
                'row' => $rownum + 2,
                'email' => $email,
                'username' => $username,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'status' => '',
                'message' => '',
                'userid' => null,
            ];

            if ($email === '' || !validate_email($email) || $firstname === '' || $lastname === '' || $username === '') {
                $result->status = 'invalid';
                $result->message = 'Faltan email/nombre/apellidos/username o el email no es válido.';
                $summary->invalid++;
                $summary->rows[] = $result;
                continue;
            }

            if (isset($seenemails[$email]) || isset($seenusernames[$username])) {
                $result->status = 'duplicate';
                $result->message = 'Duplicado dentro del CSV.';
                $summary->duplicates++;
                $summary->rows[] = $result;
                continue;
            }
            $seenemails[$email] = true;
            $seenusernames[$username] = true;

            $existingbyemail = $DB->get_record('user', ['email' => $email, 'deleted' => 0], 'id, username, email, firstname, lastname, idnumber');
            $existingbyusername = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0], 'id, username, email, firstname, lastname, idnumber');

            if ($existingbyemail && $existingbyusername && (int)$existingbyemail->id !== (int)$existingbyusername->id) {
                $result->status = 'duplicate';
                $result->message = 'El email y el username pertenecen a usuarios Moodle distintos.';
                $summary->duplicates++;
                $summary->rows[] = $result;
                continue;
            }

            $existing = $existingbyemail ?: $existingbyusername;
            if ($existing) {
                $result->userid = $existing->id;
                if ($updateexisting && is_siteadmin((int)$existing->id)) {
                    $result->status = 'skipped';
                    $result->message = 'Administrador del sitio: no se modifica desde Gestión HEE.';
                    $summary->skipped++;
                } else if ($updateexisting) {
                    $update = (object)[
                        'id' => $existing->id,
                        'firstname' => $firstname,
                        'lastname' => $lastname,
                        'idnumber' => $idnumber,
                        'timemodified' => time(),
                    ];
                    if ($city !== '') { $update->city = $city; }
                    if ($country !== '') { $update->country = $country; }
                    user_update_user($update, false, false);
                    $result->status = 'updated';
                    $result->message = 'Usuario existente actualizado.';
                    $summary->updated++;
                } else {
                    $result->status = 'skipped';
                    $result->message = 'Ya existe en Moodle. No se ha modificado.';
                    $summary->skipped++;
                }
                $summary->rows[] = $result;
                continue;
            }

            $result->status = 'notfound';
            $result->message = 'No existe una cuenta Moodle institucional coincidente. Gestión HEE no crea cuentas locales; debe provisionarse primero mediante la administración institucional.';
            $summary->skipped++;
            $summary->rows[] = $result;
        }

        return $summary;
    }


    private static function save_grade_history(\stdClass $activity, int $userid, float $grade, string $academicyear, int $importid): void {
        global $DB, $USER;
        $now = time();
        $existing = $DB->get_record('local_ga_grades', [
            'activitykey' => $activity->activitykey,
            'userid' => $userid,
            'academicyear' => $academicyear,
        ]);
        $record = (object)[
            'activityid' => $activity->id,
            'activitykey' => $activity->activitykey,
            'userid' => $userid,
            'academicyear' => $academicyear,
            'grade' => $grade,
            'importid' => $importid,
            'usermodified' => $USER->id,
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $record->timecreated = $existing->timecreated;
            $DB->update_record('local_ga_grades', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_ga_grades', $record);
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_grade_log'))) {
            $DB->insert_record('local_ga_grade_log', (object)[
                'activityid' => $activity->id,
                'activitykey' => $activity->activitykey,
                'userid' => $userid,
                'academicyear' => $academicyear,
                'grade' => $grade,
                'importid' => $importid,
                'usermodified' => $USER->id,
                'timecreated' => $now,
            ]);
        }
    }

    public static function get_grade_history(string $activitykey, int $limit = 500): array {
        global $DB;
        $sql = "SELECT g.*, u.firstname, u.lastname, u.email, u.username
                  FROM {local_ga_grades} g
                  JOIN {user} u ON u.id = g.userid
                 WHERE g.activitykey = :activitykey
              ORDER BY g.academicyear DESC, g.grade DESC, u.lastname ASC, u.firstname ASC";
        return $DB->get_records_sql($sql, ['activitykey' => $activitykey], 0, $limit);
    }


    public static function get_grade_import_log(string $activitykey, int $limit = 2000): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_grade_log'))) {
            return [];
        }
        $sql = "SELECT g.*, u.firstname, u.lastname, u.email, u.username
                  FROM {local_ga_grade_log} g
                  JOIN {user} u ON u.id = g.userid
                 WHERE g.activitykey = :activitykey
              ORDER BY g.timecreated DESC, g.id DESC";
        return $DB->get_records_sql($sql, ['activitykey' => $activitykey], 0, $limit);
    }

    private static function update_course_gradebook(\stdClass $activity, array $grades, string $gradeitemname = '', string $academicyear = ''): void {
        global $DB;

        if (empty($grades)) {
            return;
        }
        if ($gradeitemname === '') {
            $gradeitemname = get_string('defaultgradeitemname', 'local_gestion_actividades');
        }
        if ($academicyear !== '') {
            $gradeitemname .= ' (' . $academicyear . ')';
        }

        // Create/update a standard MANUAL grade item. This is more compatible than
        // grade_update() with itemtype=local, because some Moodle installations do not
        // accept local-plugin grade sources for visible gradebook columns.
        $idnumber = 'ga_' . (int)$activity->id . '_' . substr(sha1($gradeitemname), 0, 12);

        $gradeitem = \grade_item::fetch([
            'courseid' => (int)$activity->courseid,
            'idnumber' => $idnumber,
        ]);

        if (!$gradeitem) {
            $gradeitem = new \grade_item([
                'courseid' => (int)$activity->courseid,
                'itemtype' => 'manual',
                'itemname' => $gradeitemname,
                'idnumber' => $idnumber,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademin' => 0,
                'grademax' => 10,
                'iteminfo' => 'Creado por Gestion_actividades para guardar la nota de expediente.',
            ], false);
            $gradeitem->insert('local_gestion_actividades');
        } else {
            $changed = false;
            if ($gradeitem->itemname !== $gradeitemname) {
                $gradeitem->itemname = $gradeitemname;
                $changed = true;
            }
            if ((float)$gradeitem->grademax != 10.0) {
                $gradeitem->grademax = 10;
                $changed = true;
            }
            if ((float)$gradeitem->grademin != 0.0) {
                $gradeitem->grademin = 0;
                $changed = true;
            }
            if ($changed) {
                $gradeitem->update('local_gestion_actividades');
            }
        }

        foreach ($grades as $userid => $grade) {
            $ok = $gradeitem->update_final_grade((int)$userid, (float)$grade, 'local_gestion_actividades');
            if (!$ok) {
                throw new \moodle_exception('No se ha podido guardar la calificación del usuario ID ' . (int)$userid . ' en el cuaderno de Moodle.');
            }
        }

        grade_regrade_final_grades((int)$activity->courseid);
    }

    private static function create_missing_user_from_candidate(\stdClass $candidate, string $idfield, string $identifier): ?\stdClass {
        return null;
    }

    private static function find_users_by_field(string $field, string $value): array {
        global $DB;
        if (!in_array($field, ['email', 'username', 'idnumber'], true)) {
            return [];
        }
        return array_values($DB->get_records_select('user', "$field = :value AND deleted = 0", ['value' => $value], '', 'id, firstname, lastname, email, username, idnumber'));
    }

    private static function read_csv(string $filepath): array {
        $rows = [];
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return $rows;
        }
        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            return $rows;
        }
        $delimiter = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
        rewind($handle);
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($data) === 1 && trim($data[0]) === '') {
                continue;
            }
            $rows[] = $data;
        }
        fclose($handle);
        return $rows;
    }

    private static function normalise_header($header): string {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);
        $header = \core_text::strtolower(trim($header));
        $header = str_replace([' ', '-', '.'], '_', $header);
        return $header;
    }

    private static function cell(array $row, $index): string {
        if ($index === false || $index === null || !isset($row[$index])) {
            return '';
        }
        return trim((string)$row[$index]);
    }

    private static function summary(int $activityid, ?int $groupid): \stdClass {
        global $DB;
        $statuses = ['selected', 'reserve', 'notfound', 'completed', 'nograde', 'invalid', 'duplicate'];
        $summary = (object)['total' => 0, 'groupid' => $groupid];
        foreach ($statuses as $status) {
            $summary->{$status} = 0;
        }
        $records = $DB->get_records('local_ga_candidates', ['activityid' => $activityid]);
        foreach ($records as $record) {
            $summary->total++;
            if (isset($summary->{$record->status})) {
                $summary->{$record->status}++;
            }
        }
        return $summary;
    }

    

    public static function list_activities(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_activities'))) {
            return [];
        }
        return $DB->get_records('local_ga_activities', null, 'name ASC, id ASC');
    }

    public static function can_manage_globally(int $userid): bool {
        // Strict dashboard access: only site administrators and users explicitly added
        // in Gestión HEE > Usuarios autorizados. Course teacher roles alone are not enough.
        return self::is_authorized_manager($userid);
    }

    public static function is_teacher_assigned_to_workshop(int $workshopid, int $userid): bool {
        global $DB;
        if ($workshopid <= 0 || $userid <= 0) {
            return false;
        }
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))
            || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_teachers'))) {
            return false;
        }
        $sql = "SELECT 1
                  FROM {local_ga_workshop_editions} e
                  JOIN {local_ga_edition_teachers} et ON et.editionid = e.id
                 WHERE e.workshopid = :workshopid
                   AND et.userid = :userid";
        return $DB->record_exists_sql($sql, ['workshopid' => $workshopid, 'userid' => $userid]);
    }

    public static function can_manage_workshop_instance(int $workshopid, int $userid): bool {
        if ($workshopid <= 0 || $userid <= 0) {
            return false;
        }
        if (function_exists('is_role_switched')) {
            try {
                $workshop = self::get_workshop($workshopid);
                if (is_role_switched((int)$workshop->courseid)) {
                    return false;
                }
            } catch (\Throwable $e) {
                return false;
            }
        }
        // Gestores autorizados y profesores expresamente asignados a alguna edición
        // de este taller pueden gestionar alumnado, asistencia y calificaciones.
        return self::can_manage_globally($userid)
            || self::is_teacher_assigned_to_workshop($workshopid, $userid);
    }

    public static function is_teacher_assigned_to_edition(int $editionid, int $userid): bool {
        global $DB;
        if ($editionid <= 0 || $userid <= 0
                || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_teachers'))) {
            return false;
        }
        return $DB->record_exists('local_ga_edition_teachers', [
            'editionid' => $editionid,
            'userid' => $userid,
        ]);
    }

    public static function can_manage_edition(int $editionid, int $userid): bool {
        if ($editionid <= 0 || $userid <= 0) {
            return false;
        }
        return self::can_manage_globally($userid)
            || self::is_teacher_assigned_to_edition($editionid, $userid);
    }

    public static function can_manage_any_workshop_in_course(int $courseid, int $userid): bool {
        if ($courseid <= 0 || $userid <= 0) {
            return false;
        }
        // Security: course role alone never grants access to the panel from the course menu.
        return self::can_manage_globally($userid);
    }

    public static function can_manage_workshop(\stdClass $course, int $userid): bool {
        if (function_exists('is_role_switched') && is_role_switched((int)$course->id)) {
            return false;
        }
        // Backwards-compatible course-level check: global/authorized managers can manage all.
        // Workshop-specific professor access must use can_manage_workshop_instance().
        return self::can_manage_globally($userid);
    }

    public static function is_authorized_manager(int $userid): bool {
        global $DB;
        if (is_siteadmin($userid)) {
            return true;
        }
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_authorized'))
            && $DB->record_exists('local_ga_authorized', ['userid' => $userid])) {
            return true;
        }
        return false;
    }

    public static function list_authorized_users(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_authorized'))) {
            return [];
        }
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, a.timecreated
                  FROM {local_ga_authorized} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";
        return $DB->get_records_sql($sql);
    }

    public static function add_authorized_user(int $userid, int $addedby): bool {
        global $DB;
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return false;
        }
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_authorized'))) {
            return false;
        }
        if ($DB->record_exists('local_ga_authorized', ['userid' => $userid])) {
            return true;
        }
        $record = (object)[
            'userid' => $userid,
            'addedby' => $addedby,
            'timecreated' => time(),
        ];
        $DB->insert_record('local_ga_authorized', $record);
        return true;
    }

    public static function remove_authorized_user(int $userid): bool {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_authorized'))) {
            return false;
        }
        $DB->delete_records('local_ga_authorized', ['userid' => $userid]);
        return true;
    }


    public static function search_course_teachers(int $courseid, string $query): array {
        global $DB;
        $query = trim($query);
        $context = \context_course::instance($courseid);
        if ($query === '') {
            $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                      FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid JOIN {user} u ON u.id = ra.userid
                     WHERE ra.contextid = :contextid AND u.deleted = 0 AND u.confirmed = 1
                       AND (r.shortname IN ('editingteacher','teacher','manager') OR r.archetype IN ('editingteacher','teacher','manager'))
                  ORDER BY u.lastname ASC, u.firstname ASC";
            return $DB->get_records_sql($sql, ['contextid'=>$context->id], 0, 200);
        }

        // The datalist shows values like "Nombre Apellido <email@ucv.es>". Moodle must
        // search by the email/name parts, not by the whole rendered label, otherwise no
        // result is returned and the "Añadir" button never appears.
        $searchterms = [$query];
        if (preg_match('/<([^>]+)>/', $query, $matches)) {
            $email = trim($matches[1]);
            if ($email !== '') {
                $searchterms[] = $email;
            }
            $namepart = trim(preg_replace('/<[^>]+>/', '', $query));
            if ($namepart !== '') {
                $searchterms[] = $namepart;
            }
        }
        foreach (preg_split('/\s+/', preg_replace('/<[^>]+>/', ' ', $query), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (\core_text::strlen($part) >= 2) {
                $searchterms[] = $part;
            }
        }
        $searchterms = array_values(array_unique(array_filter(array_map('trim', $searchterms))));

        $conditions = [];
        $params = ['contextid' => $context->id];
        $i = 0;
        foreach ($searchterms as $term) {
            $i++;
            $like = '%' . $DB->sql_like_escape($term) . '%';
            $params['qf' . $i] = $like;
            $params['ql' . $i] = $like;
            $params['qe' . $i] = $like;
            $conditions[] = '(' .
                $DB->sql_like('u.firstname', ':qf' . $i, false) . ' OR ' .
                $DB->sql_like('u.lastname', ':ql' . $i, false) . ' OR ' .
                $DB->sql_like('u.email', ':qe' . $i, false) .
                ')';
        }

        if (!$conditions) {
            return [];
        }

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                  FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid JOIN {user} u ON u.id = ra.userid
                 WHERE ra.contextid = :contextid AND u.deleted = 0 AND u.confirmed = 1
                   AND (r.shortname IN ('editingteacher','teacher','manager') OR r.archetype IN ('editingteacher','teacher','manager'))
                   AND (" . implode(' OR ', $conditions) . ")
              ORDER BY u.lastname ASC, u.firstname ASC";
        $records = $DB->get_records_sql($sql, $params, 0, 20);

        // Also support roles inherited from category/system and users selected from the
        // datalist by email. These users may not have a direct role_assignments row in
        // the course context, but Moodle still grants them teaching/management capability.
        $userconditions = [];
        $userparams = [];
        $i = 0;
        foreach ($searchterms as $term) {
            $i++;
            $like = '%' . $DB->sql_like_escape($term) . '%';
            $userparams['uf' . $i] = $like;
            $userparams['ul' . $i] = $like;
            $userparams['ue' . $i] = $like;
            $userconditions[] = '(' .
                $DB->sql_like('firstname', ':uf' . $i, false) . ' OR ' .
                $DB->sql_like('lastname', ':ul' . $i, false) . ' OR ' .
                $DB->sql_like('email', ':ue' . $i, false) .
                ')';
        }
        if ($userconditions) {
            $users = $DB->get_records_select('user',
                'deleted = 0 AND confirmed = 1 AND (' . implode(' OR ', $userconditions) . ')',
                $userparams,
                'lastname ASC, firstname ASC',
                'id, firstname, lastname, email',
                0,
                20
            );
            foreach ($users as $user) {
                if (isset($records[$user->id])) {
                    continue;
                }
                if (has_capability('moodle/course:update', $context, $user->id, false)
                        || has_capability('moodle/course:manageactivities', $context, $user->id, false)
                        || has_capability('mod/assign:grade', $context, $user->id, false)
                        || has_capability('moodle/grade:edit', $context, $user->id, false)) {
                    $records[$user->id] = $user;
                }
            }
        }

        return array_slice($records, 0, 20, true);
    }
    public static function search_course_students(int $courseid, string $query): array {
        global $DB;
        $query = trim($query);
        $context = \context_course::instance($courseid);
        if ($query === '') {
            $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                      FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid JOIN {user} u ON u.id = ra.userid
                     WHERE ra.contextid = :contextid AND u.deleted = 0 AND u.confirmed = 1
                       AND (r.shortname = 'student' OR r.archetype = 'student')
                  ORDER BY u.lastname ASC, u.firstname ASC";
            return $DB->get_records_sql($sql, ['contextid'=>$context->id], 0, 300);
        }
        $like = '%' . $DB->sql_like_escape($query) . '%';
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                  FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid JOIN {user} u ON u.id = ra.userid
                 WHERE ra.contextid = :contextid AND u.deleted = 0 AND u.confirmed = 1
                   AND (r.shortname = 'student' OR r.archetype = 'student')
                   AND (".$DB->sql_like('u.firstname', ':q1', false)." OR ".$DB->sql_like('u.lastname', ':q2', false)." OR ".$DB->sql_like('u.email', ':q3', false).")
              ORDER BY u.lastname ASC, u.firstname ASC";
        return $DB->get_records_sql($sql, ['contextid'=>$context->id,'q1'=>$like,'q2'=>$like,'q3'=>$like], 0, 20);
    }

    public static function list_edition_enrolled_users_safe(int $editionid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            return [];
        }

        $sql = "SELECT e.id AS enrolmentid,
                       u.id AS userid,
                       u.firstname,
                       u.lastname,
                       u.email,
                       e.status,
                       e.source,
                       e.timecreated
                  FROM {local_ga_edition_enrolments} e
                  JOIN {user} u ON u.id = e.userid
                 WHERE e.editionid = :editionid
                   AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC";
        return $DB->get_records_sql($sql, ['editionid' => $editionid]);
    }

    public static function list_edition_enrolled_users(int $editionid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) { return []; }
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, e.status, e.source, e.timecreated
                  FROM {local_ga_edition_enrolments} e JOIN {user} u ON u.id = e.userid
                 WHERE e.editionid = :editionid AND e.status = 'enrolled' AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";
        return $DB->get_records_sql($sql, ['editionid'=>$editionid]);
    }
    public static function get_material_file_url(\stdClass $material, \context $context): string {
        if (empty($material->fileitemid)) { return ''; }
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_gestion_actividades', 'material', (int)$material->fileitemid, 'filename', false);
        if (!$files) { return ''; }
        $file = reset($files);
        return \moodle_url::make_pluginfile_url($context->id, 'local_gestion_actividades', 'material', (int)$material->fileitemid, $file->get_filepath(), $file->get_filename())->out(false);
    }


    public static function search_users_for_authorization(string $query): array {
        global $DB;
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $like = '%' . $DB->sql_like_escape($query) . '%';
        $sql = "SELECT id, firstname, lastname, email
                  FROM {user}
                 WHERE deleted = 0
                   AND confirmed = 1
                   AND (".$DB->sql_like('firstname', ':q1', false)."
                    OR ".$DB->sql_like('lastname', ':q2', false)."
                    OR ".$DB->sql_like('email', ':q3', false).")
              ORDER BY lastname ASC, firstname ASC";
        return $DB->get_records_sql($sql, ['q1' => $like, 'q2' => $like, 'q3' => $like], 0, 20);
    }

    public static function list_materials(int $workshopid, int $editionid = 0, bool $onlyvisible = false): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_materials'))) {
            return [];
        }
        $params = ['workshopid' => $workshopid];
        $where = 'workshopid = :workshopid';
        if ($editionid > 0) {
            $where .= ' AND (editionid = 0 OR editionid = :editionid)';
            $params['editionid'] = $editionid;
        }
        if ($onlyvisible) {
            $where .= ' AND visible = 1';
        }
        return $DB->get_records_select('local_ga_materials', $where, $params, 'timecreated DESC, id DESC');
    }

    public static function save_material(\stdClass $data): int {
        global $DB;
        $now = time();
        $record = (object)[
            'workshopid' => (int)$data->workshopid,
            'editionid' => (int)($data->editionid ?? 0),
            'name' => trim($data->name ?? ''),
            'description' => trim($data->description ?? ''),
            'url' => trim($data->url ?? ''),
            'visible' => !empty($data->visible) ? 1 : 0,
            'fileitemid' => (int)($data->fileitemid ?? 0),
            'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $record->id = (int)$data->id;
            $DB->update_record('local_ga_materials', self::filter_record_to_existing_fields('local_ga_materials', $record));
            return $record->id;
        }
        $record->timecreated = $now;
        return $DB->insert_record('local_ga_materials', self::filter_record_to_existing_fields('local_ga_materials', $record));
    }

    public static function delete_material(int $id): bool {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_materials'))) {
            return false;
        }
        $DB->delete_records('local_ga_materials', ['id' => $id]);
        return true;
    }

    public static function get_material(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_ga_materials', ['id' => $id], '*', MUST_EXIST);
    }

    public static function mark_edition_completed(int $editionid, int $userid): bool {
        global $DB;
        $edition = self::get_workshop_edition($editionid);
        $edition->completed = 1;
        $edition->timecompleted = time();
        $edition->completedby = $userid;
        $edition->status = 'completed';
        $edition->timemodified = time();
        $DB->update_record('local_ga_workshop_editions', self::filter_record_to_existing_fields('local_ga_workshop_editions', $edition));
        return true;
    }



    
    public static function cleanup_all_generated_course_entries(int $courseid = 0): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $summary = (object)[
            'courses' => 0,
            'modulesdeleted' => 0,
            'sectionshidden' => 0,
            'activitieshidden' => 0,
            'messages' => [],
        ];

        $params = [];
        $where = '';
        if ($courseid > 0) {
            $where = 'WHERE courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        $workshops = $DB->get_records_sql("SELECT * FROM {local_ga_workshops} $where ORDER BY courseid ASC, id ASC", $params);
        $courseids = [];

        foreach ($workshops as $workshop) {
            $courseids[(int)$workshop->courseid] = true;
        }

        foreach (array_keys($courseids) as $cid) {
            $summary->courses++;
            $course = $DB->get_record('course', ['id' => $cid], '*', IGNORE_MISSING);
            if (!$course) {
                continue;
            }

            $workshopsincourse = array_filter($workshops, function($w) use ($cid) {
                return (int)$w->courseid === (int)$cid;
            });

            foreach ($workshopsincourse as $workshop) {
                try {
                    if (!empty($workshop->requiredcmid)) {
                        if (self::hide_activity_from_course_page((int)$workshop->requiredcmid)) {
                            $summary->activitieshidden++;
                        }
                    }

                    // Hide assignments/quizzes likely created for this workshop.
                    $summary->activitieshidden += self::hide_candidate_workshop_activities_from_course_page($workshop);

                    // Keep old label cleanup if function exists in this branch.
                    if (method_exists(__CLASS__, 'delete_workshop_course_entries')) {
                        $summary->modulesdeleted += self::delete_workshop_course_entries($workshop);
                    }
                } catch (\Throwable $e) {
                    $summary->messages[] = 'No se pudo limpiar elementos del taller ' . $workshop->id . ': ' . $e->getMessage();
                }
            }

            // Hide old empty generated sections if possible. Do not delete non-empty sections.
            $generatednames = [self::get_archive_workshop_section_name()];
            foreach ($workshopsincourse as $workshop) {
                if (method_exists(__CLASS__, 'get_workshop_section_name')) {
                    $generatednames[] = self::get_workshop_section_name($workshop);
                }
            }

            $sections = $DB->get_records('course_sections', ['course' => $cid]);
            foreach ($sections as $section) {
                $name = trim((string)($section->name ?? ''));
                if (!in_array($name, $generatednames, true)) {
                    continue;
                }
                if (!empty($section->sequence)) {
                    continue;
                }
                try {
                    $section->visible = 0;
                    $section->timemodified = time();
                    $DB->update_record('course_sections', $section);
                    $summary->sectionshidden++;
                } catch (\Throwable $e) {
                    $summary->messages[] = 'No se pudo ocultar sección ' . $name . ': ' . $e->getMessage();
                }
            }

            try { $summary->activitieshidden += self::hide_finished_workshop_cards_in_course((int)$cid); } catch (\Throwable $e) { }
            try { $summary->activitieshidden += self::hard_archive_required_activities_in_course((int)$cid); } catch (\Throwable $e) { }
            rebuild_course_cache($cid, true);
        }

        return $summary;
    }



    public static function list_edition_enrolled_users_ultrasafe(int $editionid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            return [];
        }

        $records = $DB->get_records('local_ga_edition_enrolments', ['editionid' => $editionid], 'id ASC');
        $out = [];

        foreach ($records as $r) {
            $status = isset($r->status) ? (string)$r->status : 'enrolled';
            if ($status !== '' && !in_array($status, ['enrolled', 'attended', 'manual'], true)) {
                continue;
            }

            if (empty($r->userid)) {
                continue;
            }

            $u = $DB->get_record('user', ['id' => (int)$r->userid, 'deleted' => 0], 'id, firstname, lastname, email', IGNORE_MISSING);
            if (!$u) {
                continue;
            }

            $row = new \stdClass();
            $row->enrolmentid = (int)$r->id;
            $row->userid = (int)$u->id;
            $row->firstname = $u->firstname;
            $row->lastname = $u->lastname;
            $row->email = $u->email;
            $row->status = $status ?: 'enrolled';
            $row->source = isset($r->source) ? $r->source : '';
            $row->attended = (!empty($r->attended) || $status === 'attended') ? 1 : 0;
            $out[] = $row;
        }

        usort($out, function($a, $b) {
            return strcasecmp($a->lastname . ' ' . $a->firstname, $b->lastname . ' ' . $b->firstname);
        });

        return $out;
    }

    public static function store_material_upload(int $coursecontextid, int $itemid, string $inputname): int {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (empty($_FILES[$inputname]) || empty($_FILES[$inputname]['tmp_name']) || !is_uploaded_file($_FILES[$inputname]['tmp_name'])) {
            return $itemid;
        }

        if ($itemid <= 0) {
            $itemid = time() + random_int(1000, 999999);
        }

        $fs = get_file_storage();
        $fs->delete_area_files($coursecontextid, 'local_gestion_actividades', 'material', $itemid);

        $filename = clean_param($_FILES[$inputname]['name'], PARAM_FILE);
        if ($filename === '') {
            $filename = 'material';
        }

        $filerecord = [
            'contextid' => $coursecontextid,
            'component' => 'local_gestion_actividades',
            'filearea' => 'material',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => 'application/pdf',
        ];

        $fs->create_file_from_pathname($filerecord, $_FILES[$inputname]['tmp_name']);
        return $itemid;
    }



    public static function set_enrolment_attendance(int $enrolmentid, bool $attended, int $userid): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            return false;
        }

        $columns = $DB->get_columns('local_ga_edition_enrolments');
        $record = $DB->get_record('local_ga_edition_enrolments', ['id' => $enrolmentid], '*', MUST_EXIST);

        // Always store a visible state in status, because this field exists in older installs.
        if (isset($columns['status'])) {
            $record->status = $attended ? 'attended' : 'enrolled';
        }

        if (isset($columns['attended'])) {
            $record->attended = $attended ? 1 : 0;
        }
        if (isset($columns['timeattended'])) {
            $record->timeattended = $attended ? time() : 0;
        }
        if (isset($columns['attendedby'])) {
            $record->attendedby = $attended ? $userid : 0;
        }
        if (isset($columns['timemodified'])) {
            $record->timemodified = time();
        }

        $DB->update_record('local_ga_edition_enrolments', self::filter_record_to_existing_fields('local_ga_edition_enrolments', $record));
        return true;
    }


    public static function get_required_activity_types(\stdClass $edition): array {
        $candidatecmids = [];
        if (!empty($edition->requiredquizcmid)) {
            $candidatecmids[] = (int)$edition->requiredquizcmid;
        }
        if (!empty($edition->requiredassigncmid)) {
            $candidatecmids[] = (int)$edition->requiredassigncmid;
        }
        if (!empty($edition->requiredcmid)) {
            $candidatecmids[] = (int)$edition->requiredcmid;
        }
        foreach (array_unique($candidatecmids) as $cmid) {
            $modname = self::get_module_name_from_cmid($cmid);
            if ($modname === 'quiz') {
                return ['quiz'];
            }
            if ($modname === 'assign') {
                return ['assign'];
            }
        }
        $raw = strtolower((string)($edition->activitycreationtype ?? $edition->requiredmodname ?? ''));
        if (strpos($raw, 'quiz') !== false || strpos($raw, 'cuestion') !== false || strpos($raw, 'test') !== false) {
            return ['quiz'];
        }
        if (strpos($raw, 'assign') !== false || strpos($raw, 'tarea') !== false) {
            return ['assign'];
        }
        return [];
    }

    public static function get_required_activity_for_edition_by_type(int $editionid, string $type): ?\stdClass {
        global $DB;
        if (!in_array($type, ['assign', 'quiz'], true)) {
            return null;
        }
        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $columns = $DB->get_columns('local_ga_workshop_editions');

        $candidatecmids = [];
        if ($type === 'assign' && isset($columns['requiredassigncmid']) && !empty($edition->requiredassigncmid)) {
            $candidatecmids[] = (int)$edition->requiredassigncmid;
        }
        if ($type === 'quiz' && isset($columns['requiredquizcmid']) && !empty($edition->requiredquizcmid)) {
            $candidatecmids[] = (int)$edition->requiredquizcmid;
        }
        if (!empty($edition->requiredcmid)) {
            $candidatecmids[] = (int)$edition->requiredcmid;
        }

        foreach (array_unique($candidatecmids) as $cmid) {
            $sql = "SELECT cm.id AS cmid, cm.instance, m.name AS modname, COALESCE(a.name, q.name) AS activityname
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                 LEFT JOIN {assign} a ON a.id = cm.instance AND m.name = 'assign'
                 LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
                     WHERE cm.id = :cmid AND cm.course = :courseid AND m.name = :modname AND cm.deletioninprogress = 0";
            $linked = $DB->get_record_sql($sql, ['cmid' => $cmid, 'courseid' => (int)$workshop->courseid, 'modname' => $type], IGNORE_MISSING);
            if ($linked && $DB->record_exists($type, ['id' => (int)$linked->instance])) {
                return $linked;
            }
        }

        $candidates = self::find_candidate_required_activities_by_type($edition, $type);
        return $candidates ? reset($candidates) : null;
    }

    public static function find_candidate_required_activities_by_type(\stdClass $edition, string $type): array {
        global $DB;

        if (!in_array($type, ['assign', 'quiz'], true)) {
            return [];
        }

        $workshop = self::get_workshop((int)$edition->workshopid);
        $courseid = (int)$workshop->courseid;
        if (!$DB->get_manager()->table_exists(new \xmldb_table($type))) {
            return [];
        }

        if ($type === 'assign') {
            $sql = "SELECT cm.id AS cmid, cm.added, m.name AS modname, a.name AS activityname
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {assign} a ON a.id = cm.instance
                     WHERE cm.course = :courseid AND m.name = 'assign' AND cm.deletioninprogress = 0
                  ORDER BY cm.added DESC, cm.id DESC";
        } else {
            $sql = "SELECT cm.id AS cmid, cm.added, m.name AS modname, q.name AS activityname
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {quiz} q ON q.id = cm.instance
                     WHERE cm.course = :courseid AND m.name = 'quiz' AND cm.deletioninprogress = 0
                  ORDER BY cm.added DESC, cm.id DESC";
        }

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, 50);
        $candidates = [];
        foreach ($records as $r) {
            $activityname = (string)($r->activityname ?? '');
            $hay = \core_text::strtolower($activityname);
            $needle1 = \core_text::strtolower((string)$workshop->name);
            $needle2 = \core_text::strtolower((string)$workshop->code);
            $needle3 = \core_text::strtolower($type === 'assign' ? 'tarea' : 'cuestionario');

            if (strpos($hay, $needle1) !== false || strpos($hay, $needle2) !== false || strpos($hay, $needle3) !== false || strpos($hay, 'taller') !== false) {
                $candidates[] = $r;
            }
        }
        return $candidates;
    }

    public static function user_can_access_workshop_resources(int $editionid, int $userid): bool {
        if ($editionid <= 0 || $userid <= 0) {
            return false;
        }
        $edition = self::get_workshop_edition($editionid);

        // Staff access is scoped to the concrete edition: a Profesor HEE assigned
        // to another edition of the same base workshop must not reach this one.
        if (self::can_manage_edition($editionid, $userid)) {
            return true;
        }

        $enrolment = self::get_edition_enrolment($editionid, $userid);
        if (!$enrolment || !in_array((string)($enrolment->status ?? ''), ['enrolled', 'attended'], true)) {
            return false;
        }

        // Confirmed attendance proves that the student belongs to the workshop and must
        // never revoke access to materials merely because status changed from enrolled.
        if (self::is_user_attended_edition($editionid, $userid)) {
            return true;
        }

        if (empty($edition->sessiondate) || time() < (int)$edition->sessiondate) {
            return false;
        }

        return true;
    }


    /**
     * Shared materials (editionid = 0) belong to the base workshop and are listed
     * in every edition. A user may open them when they can access resources of
     * at least one edition of that workshop.
     */
    public static function user_can_access_shared_workshop_materials(int $workshopid, int $userid): bool {
        global $DB;
        if ($workshopid <= 0 || $userid <= 0) {
            return false;
        }
        if (self::can_manage_workshop_instance($workshopid, $userid)) {
            return true;
        }
        $editionids = $DB->get_fieldset_select(
            'local_ga_edition_enrolments',
            'DISTINCT editionid',
            'workshopid = :workshopid AND userid = :userid',
            ['workshopid' => $workshopid, 'userid' => $userid]
        );
        foreach ($editionids as $editionid) {
            try {
                if (self::user_can_access_workshop_resources((int)$editionid, $userid)) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return false;
    }

    public static function count_edition_certificates(int $editionid): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))) {
            return 0;
        }
        return (int)$DB->count_records('local_ga_certificates', ['editionid' => $editionid]);
    }

    public static function get_internal_task_submission(int $editionid, int $userid): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_task_submissions'))) {
            return null;
        }
        $record = $DB->get_record('local_ga_task_submissions', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    public static function save_internal_task_config(int $editionid, string $description, string $url, int $duedate, int $fileitemid): void {
        global $DB;
        $edition = self::get_workshop_edition($editionid);
        $edition->activitycreationtype = 'assign';
        $edition->requiredmodname = 'assign';
        $edition->taskdescription = $description;
        $edition->taskurl = trim($url);
        $edition->taskduedate = $duedate;
        $edition->taskfileitemid = $fileitemid;
        $edition->timemodified = time();
        $DB->update_record('local_ga_workshop_editions', self::filter_record_to_existing_fields('local_ga_workshop_editions', $edition));
    }

    public static function save_internal_task_submission(int $editionid, int $userid, int $fileitemid): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_task_submissions'))) {
            return 0;
        }
        $now = time();
        $existing = $DB->get_record('local_ga_task_submissions', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING);
        $record = (object)[
            'editionid' => $editionid,
            'userid' => $userid,
            'fileitemid' => $fileitemid,
            'status' => 'submitted',
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record('local_ga_task_submissions', self::filter_record_to_existing_fields('local_ga_task_submissions', $record));
            return (int)$record->id;
        }
        $record->timecreated = $now;
        return (int)$DB->insert_record('local_ga_task_submissions', self::filter_record_to_existing_fields('local_ga_task_submissions', $record));
    }

    public static function store_named_upload(int $coursecontextid, int $itemid, string $inputname, string $filearea): int {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (empty($_FILES[$inputname]) || empty($_FILES[$inputname]['tmp_name']) || !is_uploaded_file($_FILES[$inputname]['tmp_name'])) {
            return $itemid;
        }

        if ($itemid <= 0) {
            $itemid = time() + random_int(1000, 999999);
        }

        $fs = get_file_storage();
        $fs->delete_area_files($coursecontextid, 'local_gestion_actividades', $filearea, $itemid);

        $filename = clean_param($_FILES[$inputname]['name'], PARAM_FILE);
        if ($filename === '') {
            $filename = 'archivo';
        }

        $filerecord = [
            'contextid' => $coursecontextid,
            'component' => 'local_gestion_actividades',
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ];

        $fs->create_file_from_pathname($filerecord, $_FILES[$inputname]['tmp_name']);
        return $itemid;
    }

    public static function get_filearea_url(\context $context, string $filearea, int $itemid): string {
        if ($itemid <= 0) {
            return '';
        }
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_gestion_actividades', $filearea, $itemid, 'filename', false);
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return \moodle_url::make_pluginfile_url($context->id, 'local_gestion_actividades', $filearea, $itemid, $file->get_filepath(), $file->get_filename())->out(false);
    }

    public static function user_has_submitted_internal_task(int $editionid, int $userid): bool {
        $sub = self::get_internal_task_submission($editionid, $userid);
        return $sub && !empty($sub->fileitemid);
    }

    public static function get_internal_task_grade(int $editionid, int $userid): ?float {
        $sub = self::get_internal_task_submission($editionid, $userid);
        if (!$sub || !property_exists($sub, 'grade') || $sub->grade === null || $sub->grade === '') {
            return null;
        }
        return (float)$sub->grade;
    }

    public static function user_has_passing_internal_task_grade(int $editionid, int $userid): bool {
        $grade = self::get_internal_task_grade($editionid, $userid);
        return $grade !== null && $grade >= 5.0;
    }

    public static function save_internal_task_grade(
        int $editionid,
        int $userid,
        ?float $grade,
        int $graderid,
        bool $syncgradebook = true
    ): bool {
        global $DB;

        if ($editionid <= 0 || $userid <= 0 || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_task_submissions'))) {
            return false;
        }

        $submission = $DB->get_record('local_ga_task_submissions', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING);
        if (!$submission || empty($submission->fileitemid)) {
            // No se guarda nota si todavía no hay entrega real de tarea.
            return false;
        }

        if ($grade !== null) {
            if ($grade < 0) {
                $grade = 0.0;
            }
            if ($grade > 10) {
                $grade = 10.0;
            }
        }

        $submission->grade = $grade;
        $submission->gradedby = $graderid;
        $submission->timegraded = time();
        $submission->timemodified = time();
        $DB->update_record('local_ga_task_submissions', self::filter_record_to_existing_fields('local_ga_task_submissions', $submission));
        if ($syncgradebook) {
            $courseid = (int)$DB->get_field_sql(
                "SELECT w.courseid
                   FROM {local_ga_workshop_editions} e
                   JOIN {local_ga_workshops} w ON w.id = e.workshopid
                  WHERE e.id = :editionid",
                ['editionid' => $editionid]
            );
            if ($courseid > 0) {
                grade_manager::sync_user_for_course_safely($courseid, $userid);
            }
        }
        return true;
    }

    public static function detect_required_activity_type(\stdClass $edition): string {
        // Try all known/possible field names used by previous alpha versions.
        foreach ([
            'requiredactivitytype',
            'required_activity_type',
            'activitytype',
            'activity_type',
            'completiontype',
            'completion_type',
            'requiredtype',
            'required_type',
            'tasktype',
            'task_type',
            'modtype',
            'modulename',
            'requiredmodname',
            'activitycreationtype',
            'requiredmod',
            'requiredmodule'
        ] as $field) {
            if (!empty($edition->$field)) {
                $value = strtolower(trim((string)$edition->$field));
                if (strpos($value, 'quiz') !== false || strpos($value, 'cuestion') !== false || strpos($value, 'test') !== false) {
                    return 'quiz';
                }
                if (strpos($value, 'assign') !== false || strpos($value, 'tarea') !== false || strpos($value, 'task') !== false || strpos($value, 'entrega') !== false) {
                    return 'assign';
                }
            }
        }

        foreach (['createquiz', 'autoquiz', 'quizrequired', 'hasquiz'] as $field) {
            if (!empty($edition->$field)) {
                return 'quiz';
            }
        }

        foreach (['createassignment', 'createassign', 'autocreateactivity', 'autoassign', 'assignmentrequired', 'hasassignment', 'hastask'] as $field) {
            if (!empty($edition->$field)) {
                return 'assign';
            }
        }

        // In this workflow, if the edition reaches this page it means the workshop was configured
        // to require an activity. The safe default agreed for the simulation is "tarea".
        return 'assign';
    }

    public static function update_edition_required_cmid(int $editionid, int $cmid): bool {
        global $DB;
        $edition = self::get_workshop_edition($editionid);
        $cm = $DB->get_record_sql("SELECT cm.id, m.name AS modname
                                      FROM {course_modules} cm
                                      JOIN {modules} m ON m.id = cm.module
                                     WHERE cm.id = :cmid", ['cmid' => $cmid], IGNORE_MISSING);
        if (!$cm || !in_array((string)$cm->modname, ['assign', 'quiz'], true)) {
            return false;
        }

        $columns = $DB->get_columns('local_ga_workshop_editions');
        if ($cm->modname === 'assign' && isset($columns['requiredassigncmid'])) {
            $edition->requiredassigncmid = $cmid;
        } else if ($cm->modname === 'quiz' && isset($columns['requiredquizcmid'])) {
            $edition->requiredquizcmid = $cmid;
        }

        // Campo heredado: mantenerlo para compatibilidad, pero no depender de él para ambos tipos.
        if (isset($columns['requiredcmid'])) {
            $edition->requiredcmid = $cmid;
        }
        if (isset($columns['requiredmodname'])) {
            $edition->requiredmodname = (string)$cm->modname;
        }
        if (isset($columns['activitycreationtype'])) {
            $edition->activitycreationtype = (string)$cm->modname;
        }
        $edition->timemodified = time();
        $DB->update_record('local_ga_workshop_editions', self::filter_record_to_existing_fields('local_ga_workshop_editions', $edition));
        return true;
    }



    public static function find_candidate_required_activities(\stdClass $edition): array {
        global $DB;

        $workshop = self::get_workshop((int)$edition->workshopid);
        $courseid = (int)$workshop->courseid;
        $type = self::detect_required_activity_type($edition);
        if ($type === '') {
            $type = 'assign';
        }
        if ($type !== 'quiz') {
            $type = 'assign';
        }

        $records = [];
        if ($type === 'assign' && $DB->get_manager()->table_exists(new \xmldb_table('assign'))) {
            $sql = "SELECT cm.id AS cmid,
                           cm.added,
                           m.name AS modname,
                           a.name AS activityname
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {assign} a ON a.id = cm.instance
                     WHERE cm.course = :courseid
                       AND m.name = 'assign'
                       AND cm.deletioninprogress = 0
                  ORDER BY cm.added DESC, cm.id DESC";
            $records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, 50);
        } else if ($type === 'quiz' && $DB->get_manager()->table_exists(new \xmldb_table('quiz'))) {
            $sql = "SELECT cm.id AS cmid,
                           cm.added,
                           m.name AS modname,
                           q.name AS activityname
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {quiz} q ON q.id = cm.instance
                     WHERE cm.course = :courseid
                       AND m.name = 'quiz'
                       AND cm.deletioninprogress = 0
                  ORDER BY cm.added DESC, cm.id DESC";
            $records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, 50);
        }

        $candidates = [];
        foreach ($records as $r) {
            $activityname = (string)($r->activityname ?? '');
            if ($activityname === '') {
                continue;
            }

            $hay = \core_text::strtolower($activityname);
            $needle1 = \core_text::strtolower((string)$workshop->name);
            $needle2 = \core_text::strtolower((string)$workshop->code);
            $needle3 = \core_text::strtolower('taller');

            if (strpos($hay, $needle1) !== false || strpos($hay, $needle2) !== false || strpos($hay, $needle3) !== false) {
                $candidates[$r->cmid] = $r;
            }
        }

        // Si no hay coincidencia por nombre, devuelve las últimas actividades de ese tipo.
        // Así se puede vincular la recién creada sin que el nombre sea perfecto.
        if (!$candidates) {
            foreach ($records as $r) {
                $candidates[$r->cmid] = $r;
            }
        }

        return $candidates;
    }

    public static function link_required_activity_to_edition(int $editionid, int $cmid): bool {
        global $DB;

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        if (!$DB->record_exists('course_modules', ['id' => $cmid, 'course' => (int)$workshop->courseid])) {
            return false;
        }

        self::update_edition_required_cmid($editionid, $cmid);
        self::restrict_required_activity_to_edition_group($editionid, $cmid);
        self::hard_archive_cmid_from_course_page((int)$cmid);
        self::hard_archive_required_activities_in_course((int)$workshop->courseid);
        self::add_workshop_backlink_to_required_activity($editionid, $cmid);

        return true;
    }



    public static function hide_activity_from_course_page(int $cmid): bool {
        global $DB, $CFG;

        if (!$DB->record_exists('course_modules', ['id' => $cmid])) {
            return false;
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $columns = $DB->get_columns('course_modules');
        $changed = false;

        if (isset($columns['visibleoncoursepage'])) {
            $cm->visibleoncoursepage = 0;
            $changed = true;
        }

        // Do not set visible=0, because the activity must remain accessible from the workshop.
        if ($changed) {
            $DB->update_record('course_modules', self::filter_record_to_existing_fields('course_modules', $cm));
            require_once($CFG->dirroot . '/course/lib.php');
            rebuild_course_cache((int)$cm->course, true);
        }

        return $changed;
    }

    public static function hide_candidate_workshop_activities_from_course_page(\stdClass $workshop): int {
        global $DB, $CFG;

        $hidden = 0;
        $courseid = (int)$workshop->courseid;

        $sql = "SELECT cm.id AS cmid,
                       cm.course,
                       cm.section,
                       m.name AS modname,
                       COALESCE(a.name, q.name) AS activityname,
                       cs.name AS sectionname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {course_sections} cs ON cs.id = cm.section
             LEFT JOIN {assign} a ON a.id = cm.instance AND m.name = 'assign'
             LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
                 WHERE cm.course = :courseid
                   AND m.name IN ('assign', 'quiz')
                   AND cm.deletioninprogress = 0
              ORDER BY cm.added DESC, cm.id DESC";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $mainsection = self::get_main_workshop_section_name();
        $wname = \core_text::strtolower((string)$workshop->name);
        $wcode = \core_text::strtolower((string)$workshop->code);

        foreach ($records as $r) {
            $activityname = \core_text::strtolower((string)($r->activityname ?? ''));
            $sectionname = \core_text::strtolower((string)($r->sectionname ?? ''));

            $matches = false;

            if ($activityname !== '' && (
                strpos($activityname, $wname) !== false ||
                strpos($activityname, $wcode) !== false ||
                strpos($activityname, 'taller') !== false
            )) {
                $matches = true;
            }

            if ($sectionname === \core_text::strtolower($mainsection)) {
                $matches = true;
            }

            if ($matches && self::hide_activity_from_course_page((int)$r->cmid)) {
                $hidden++;
            }
        }

        if ($hidden > 0) {
            require_once($CFG->dirroot . '/course/lib.php');
            rebuild_course_cache($courseid, true);
        }

        return $hidden;
    }



    public static function restrict_required_activity_to_edition_group(int $editionid, int $cmid): bool {
        global $DB, $CFG;

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $courseid = (int)$workshop->courseid;

        $groupid = self::get_or_create_edition_group($editionid);
        self::sync_edition_group_members($editionid);
        $groupingid = self::get_or_create_edition_grouping($editionid, $groupid);

        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], '*', IGNORE_MISSING);
        if (!$cm) {
            return false;
        }

        $columns = $DB->get_columns('course_modules');

        if (isset($columns['groupmode'])) {
            $cm->groupmode = SEPARATEGROUPS;
        }
        if (isset($columns['groupingid'])) {
            $cm->groupingid = $groupingid;
        }
        if (isset($columns['availability'])) {
            $availability = [
                'op' => '&',
                'c' => [
                    [
                        'type' => 'group',
                        'id' => $groupid,
                    ],
                ],
                'showc' => [false],
            ];
            $cm->availability = json_encode($availability);
        }

        // Best Moodle stealth behaviour: visible, but not shown on course page.
        if (isset($columns['visible'])) {
            $cm->visible = 1;
        }
        if (isset($columns['visibleold'])) {
            $cm->visibleold = 1;
        }
        if (isset($columns['visibleoncoursepage'])) {
            $cm->visibleoncoursepage = 0;
        }

        $DB->update_record('course_modules', self::filter_record_to_existing_fields('course_modules', $cm));

        // Also try to configure assign plugin itself with separate groups/grouping when fields exist.
        try {
            $module = $DB->get_record('modules', ['id' => $cm->module], 'id,name', MUST_EXIST);
            if ($module->name === 'assign' && $DB->record_exists('assign', ['id' => $cm->instance])) {
                $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
                $assigncolumns = $DB->get_columns('assign');
                if (isset($assigncolumns['teamsubmission'])) {
                    $assign->teamsubmission = 0;
                }
                if (isset($assigncolumns['requiresubmissionstatement'])) {
                    $assign->requiresubmissionstatement = 0;
                }
                $DB->update_record('assign', self::filter_record_to_existing_fields('assign', $assign));
            }
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache($courseid, true);

        return true;
    }



    public static function get_or_create_edition_group(int $editionid): int {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/group/lib.php');

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $courseid = (int)$workshop->courseid;

        if (!empty($edition->groupid) && $DB->record_exists('groups', ['id' => (int)$edition->groupid, 'courseid' => $courseid])) {
            return (int)$edition->groupid;
        }

        $editioncode = '';
        foreach (['editioncode', 'code', 'name'] as $f) {
            if (!empty($edition->$f)) {
                $editioncode = clean_param((string)$edition->$f, PARAM_TEXT);
                break;
            }
        }
        if ($editioncode === '') {
            $editioncode = 'E' . (int)$edition->id;
        }

        $groupname = trim('Taller ' . (string)$workshop->code . ' - ' . (string)$workshop->name . ' - ' . $editioncode);
        if (\core_text::strlen($groupname) > 250) {
            $groupname = \core_text::substr($groupname, 0, 250);
        }

        $existing = $DB->get_record('groups', ['courseid' => $courseid, 'name' => $groupname], '*', IGNORE_MISSING);
        if ($existing) {
            $groupid = (int)$existing->id;
        } else {
            $group = new \stdClass();
            $group->courseid = $courseid;
            $group->name = $groupname;
            $group->description = get_string('editiongroupdescription', 'local_gestion_actividades', $workshop->name);
            $group->descriptionformat = FORMAT_HTML;
            $groupid = groups_create_group($group);
        }

        $edition->groupid = $groupid;
        $edition->timemodified = time();
        $DB->update_record('local_ga_workshop_editions', self::filter_record_to_existing_fields('local_ga_workshop_editions', $edition));

        return $groupid;
    }

    public static function sync_edition_group_members(int $editionid): int {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/group/lib.php');

        $groupid = self::get_or_create_edition_group($editionid);
        $users = self::list_edition_enrolled_users_ultrasafe($editionid);

        $added = 0;
        foreach ($users as $user) {
            if (empty($user->userid)) {
                continue;
            }
            if (!groups_is_member($groupid, (int)$user->userid)) {
                groups_add_member($groupid, (int)$user->userid);
                $added++;
            }
        }

        return $added;
    }

    public static function get_or_create_edition_grouping(int $editionid, int $groupid): int {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/group/lib.php');

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $courseid = (int)$workshop->courseid;

        $group = $DB->get_record('groups', ['id' => $groupid], '*', MUST_EXIST);
        $groupingname = 'Agrupación ' . $group->name;
        if (\core_text::strlen($groupingname) > 250) {
            $groupingname = \core_text::substr($groupingname, 0, 250);
        }

        $grouping = $DB->get_record('groupings', ['courseid' => $courseid, 'name' => $groupingname], '*', IGNORE_MISSING);
        if ($grouping) {
            $groupingid = (int)$grouping->id;
        } else {
            $new = new \stdClass();
            $new->courseid = $courseid;
            $new->name = $groupingname;
            $new->description = get_string('editiongroupingdescription', 'local_gestion_actividades', $workshop->name);
            $new->descriptionformat = FORMAT_HTML;
            $groupingid = groups_create_grouping($new);
        }

        if (!$DB->record_exists('groupings_groups', ['groupingid' => $groupingid, 'groupid' => $groupid])) {
            groups_assign_grouping($groupingid, $groupid);
        }

        return $groupingid;
    }



    public static function get_primary_workshop_edition_for_user_view(int $workshopid): ?\stdClass {
        global $DB;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            $records = $DB->get_records('local_ga_workshop_editions', ['workshopid' => $workshopid], 'id ASC', '*', 0, 1);
            if ($records) {
                return reset($records);
            }
        }
        return null;
    }

    public static function get_user_edition_enrolment(int $editionid, int $userid): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            return null;
        }
        return $DB->get_record('local_ga_edition_enrolments', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING) ?: null;
    }

    public static function is_user_attended_edition(int $editionid, int $userid): bool {
        $record = self::get_user_edition_enrolment($editionid, $userid);
        if (!$record) {
            return false;
        }
        if (!empty($record->attended)) {
            return true;
        }
        if (isset($record->status) && (string)$record->status === 'attended') {
            return true;
        }
        return false;
    }

    public static function add_workshop_backlink_to_required_activity(int $editionid, int $cmid): bool {
        global $DB, $CFG;

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
        if (!$cm) {
            return false;
        }
        $module = $DB->get_record('modules', ['id' => (int)$cm->module], 'id,name', IGNORE_MISSING);
        if (!$module) {
            return false;
        }

        $url = new \moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $workshop->id, 'editionid' => $edition ? (int)$edition->id : 0]);
        $backhtml = \html_writer::div(
            \html_writer::link($url, get_string('backtoworkshopfromactivity', 'local_gestion_actividades'), ['class' => 'btn btn-secondary']),
            'local-ga-backtoworkshop',
            ['style' => 'margin: 0 0 1rem 0;']
        );

        try {
            if ($module->name === 'assign' && $DB->record_exists('assign', ['id' => (int)$cm->instance])) {
                $assign = $DB->get_record('assign', ['id' => (int)$cm->instance], '*', MUST_EXIST);
                $intro = (string)($assign->intro ?? '');
                if (strpos($intro, 'local-ga-backtoworkshop') === false) {
                    $assign->intro = $backhtml . $intro;
                    $assign->introformat = FORMAT_HTML;
                    $DB->update_record('assign', self::filter_record_to_existing_fields('assign', $assign));
                    rebuild_course_cache((int)$workshop->courseid, true);
                }
                return true;
            }

            if ($module->name === 'quiz' && $DB->record_exists('quiz', ['id' => (int)$cm->instance])) {
                $quiz = $DB->get_record('quiz', ['id' => (int)$cm->instance], '*', MUST_EXIST);
                $intro = (string)($quiz->intro ?? '');
                if (strpos($intro, 'local-ga-backtoworkshop') === false) {
                    $quiz->intro = $backhtml . $intro;
                    $quiz->introformat = FORMAT_HTML;
                    $DB->update_record('quiz', self::filter_record_to_existing_fields('quiz', $quiz));
                    rebuild_course_cache((int)$workshop->courseid, true);
                }
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    public static function archive_finished_workshop_edition(int $editionid): bool {
        global $DB, $CFG;

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $courseid = (int)$workshop->courseid;

        $now = time();
        $columns = $DB->get_columns('local_ga_workshop_editions');
        if (isset($columns['status'])) {
            $edition->status = 'archived';
        }
        if (isset($columns['archived'])) {
            $edition->archived = 1;
        }
        if (isset($columns['timearchived'])) {
            $edition->timearchived = $now;
        }
        if (isset($columns['timemodified'])) {
            $edition->timemodified = $now;
        }
        $DB->update_record('local_ga_workshop_editions', self::filter_record_to_existing_fields('local_ga_workshop_editions', $edition));
        // The edition moves from "vigentes" to "finalizados" for its teachers.
        self::invalidate_teacher_block_cache([], [$editionid]);
        // Type B: no more reflections without the teacher's explicit permission.
        try {
            typeb_certificate_policy::close_reflection_submissions($editionid);
        } catch (\Throwable $e) {
            debugging('No se pudo cerrar la entrega de reflexiones: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        if (!empty($edition->requiredcmid)) {
            try {
                self::hard_archive_cmid_from_course_page((int)$edition->requiredcmid);
            } catch (\Throwable $e) {
                // Non-fatal.
            }
        }

        try {
            // El taller finalizado no debe seguir apareciendo en la sección del curso.
            self::delete_workshop_course_entries($workshop);
            self::hard_archive_workshop_course_entries($workshop);
            self::hard_archive_required_activities_in_course($courseid);
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        // Rebuild the visible Type A/Type B section immediately. The teacher must not
        // need to run the administrative repair after finishing a workshop.
        try {
            self::sync_workshop_section_summary($courseid, self::get_workshop_type($workshop));
        } catch (\Throwable $e) {
            // Keep the archive operation valid even if the visual refresh fails.
        }

        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache($courseid, true);

        return true;
    }



    public static function is_edition_finished(\stdClass $edition): bool {
        if (!empty($edition->status) && in_array((string)$edition->status, ['finished', 'archived', 'closed_finished'], true)) {
            return true;
        }
        return false;
    }

    public static function get_active_editions_for_workshop(int $workshopid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            return [];
        }
        $editions = $DB->get_records('local_ga_workshop_editions', ['workshopid' => $workshopid], 'id ASC');
        $out = [];
        foreach ($editions as $e) {
            if (!self::is_edition_finished($e)) {
                $out[$e->id] = $e;
            }
        }
        return $out;
    }

    public static function render_workshop_course_card(\stdClass $workshop, ?\stdClass $edition = null): string {
        global $DB;

        $url = new \moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $workshop->id, 'editionid' => $edition ? (int)$edition->id : 0]);

        $hours = !empty($workshop->hours) ? s($workshop->hours) . ' h' : '-';
        $desc = trim((string)($workshop->description ?? ''));
        $date = '';
        $places = '';

        if ($edition) {
            if (!empty($edition->sessiondate)) {
                $date = userdate((int)$edition->sessiondate, get_string('strftimedatefullshort', 'langconfig'));
            }
            if (!empty($edition->places)) {
                $enrolled = 0;
                try {
                    $enrolled = count(self::list_edition_enrolled_users_ultrasafe((int)$edition->id));
                } catch (\Throwable $e) {
                    $enrolled = 0;
                }
                $remaining = max(0, (int)$edition->places - $enrolled);
                $places = $remaining . ' ' . get_string('remainingplacesplain', 'local_gestion_actividades');
            }
        }

        $meta = [];
        if ($date !== '') {
            $meta[] = \html_writer::span(get_string('date') . ': ', 'local-ga-meta-label') . s($date);
        }
        $meta[] = \html_writer::span(get_string('workshophours', 'local_gestion_actividades') . ': ', 'local-ga-meta-label') . $hours;
        if ($places !== '') {
            $meta[] = \html_writer::span(get_string('places', 'local_gestion_actividades') . ': ', 'local-ga-meta-label') . s($places);
        }

        $html = \html_writer::start_div('local-ga-course-card', [
            'style' => 'border-left:4px solid #0f6cbf;background:#f7f9fb;padding:18px 20px;margin:14px 0;border-radius:10px;'
        ]);
        $html .= \html_writer::tag('h4', s($workshop->code . ' - ' . $workshop->name), [
            'style' => 'margin:0 0 6px 0;font-weight:700;'
        ]);
        if ($desc !== '') {
            $html .= \html_writer::tag('p', s($desc), ['style' => 'margin:0 0 10px 0;']);
        }
        $html .= \html_writer::div(implode(' · ', $meta), 'local-ga-course-meta', [
            'style' => 'margin:0 0 12px 0;color:#1f2d3d;'
        ]);
        $html .= \html_writer::link($url, get_string('viewworkshop', 'local_gestion_actividades'), [
            'class' => 'btn btn-secondary btn-sm',
            'style' => 'margin-right:8px;'
        ]);
        if ($edition && method_exists(__CLASS__, 'get_user_edition_enrolment')) {
            global $USER;
            try {
                $enrol = self::get_user_edition_enrolment((int)$edition->id, (int)$USER->id);
                if ($enrol) {
                    $html .= \html_writer::span(get_string('alreadyenrolledshort', 'local_gestion_actividades'), 'badge bg-success', ['style' => 'padding:8px 10px;']);
                } else {
                    $enrolurl = new \moodle_url('/local/gestion_actividades/workshop_view.php', ['id' => $workshop->id, 'enrol' => 1, 'sesskey' => sesskey()]);
                    $html .= \html_writer::link($enrolurl, get_string('enrolme', 'local_gestion_actividades'), ['class' => 'btn btn-primary btn-sm']);
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }
        $html .= \html_writer::end_div();

        return $html;
    }

    public static function hide_finished_workshop_cards_in_course(int $courseid): int {
        global $DB, $CFG;

        $hidden = 0;
        $workshops = $DB->get_records('local_ga_workshops', ['courseid' => $courseid]);
        if (!$workshops) {
            return 0;
        }

        foreach ($workshops as $workshop) {
            $active = self::get_active_editions_for_workshop((int)$workshop->id);
            if ($active) {
                continue;
            }
            $hidden += self::hard_archive_workshop_course_entries($workshop);
        }

        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache($courseid, true);

        return $hidden;
    }



    public static function remove_cmid_from_course_section_sequence(int $cmid): bool {
        global $DB, $CFG;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
        if (!$cm) {
            return false;
        }

        $section = $DB->get_record('course_sections', ['id' => (int)$cm->section], '*', IGNORE_MISSING);
        if (!$section) {
            return false;
        }

        $sequence = trim((string)($section->sequence ?? ''));
        if ($sequence === '') {
            return false;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $sequence)), function($value) use ($cmid) {
            return $value !== '' && (int)$value !== (int)$cmid;
        }));

        $newsequence = implode(',', $parts);
        if ($newsequence === $sequence) {
            return false;
        }

        $section->sequence = $newsequence;
        $section->timemodified = time();
        $DB->update_record('course_sections', $section);

        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache((int)$cm->course, true);

        return true;
    }

    public static function hard_archive_cmid_from_course_page(int $cmid): bool {
        global $DB, $CFG;

        $changed = false;
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
        if (!$cm) {
            return false;
        }

        // Remove from section sequence: it disappears for students, teachers and admins on the course page.
        if (self::remove_cmid_from_course_section_sequence($cmid)) {
            $changed = true;
        }

        // Also mark as not shown on course page where supported.
        $columns = $DB->get_columns('course_modules');
        if (isset($columns['visibleoncoursepage'])) {
            $cm->visibleoncoursepage = 0;
            $changed = true;
        }
        // Keep visible=1 so direct links from the plugin/archive still work for users with access.
        if (isset($columns['visible'])) {
            $cm->visible = 1;
        }

        if ($changed) {
            $DB->update_record('course_modules', self::filter_record_to_existing_fields('course_modules', $cm));
            require_once($CFG->dirroot . '/course/lib.php');
            rebuild_course_cache((int)$cm->course, true);
        }

        return $changed;
    }

    public static function hard_archive_workshop_course_entries(\stdClass $workshop): int {
        global $DB, $CFG;

        $courseid = (int)$workshop->courseid;
        $removed = 0;

        $patterns = [
            '%' . $DB->sql_like_escape((string)$workshop->code) . '%',
            '%' . $DB->sql_like_escape((string)$workshop->name) . '%',
        ];

        $sql = "SELECT cm.id, cm.course, cm.module, cm.instance, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {label} l ON l.id = cm.instance AND m.name = 'label'
             LEFT JOIN {page} p ON p.id = cm.instance AND m.name = 'page'
             LEFT JOIN {assign} a ON a.id = cm.instance AND m.name = 'assign'
             LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND (
                        (m.name = 'label' AND (" . $DB->sql_like('l.intro', ':p1', false) . " OR " . $DB->sql_like('l.name', ':p2', false) . "))
                     OR (m.name = 'page' AND (" . $DB->sql_like('p.content', ':p3', false) . " OR " . $DB->sql_like('p.name', ':p4', false) . "))
                     OR (m.name = 'assign' AND (" . $DB->sql_like('a.name', ':p5', false) . " OR " . $DB->sql_like('a.intro', ':p6', false) . "))
                     OR (m.name = 'quiz' AND (" . $DB->sql_like('q.name', ':p7', false) . " OR " . $DB->sql_like('q.intro', ':p8', false) . "))
                   )";

        foreach ($patterns as $pattern) {
            $mods = $DB->get_records_sql($sql, [
                'courseid' => $courseid,
                'p1' => $pattern,
                'p2' => $pattern,
                'p3' => $pattern,
                'p4' => $pattern,
                'p5' => $pattern,
                'p6' => $pattern,
                'p7' => $pattern,
                'p8' => $pattern,
            ]);
            foreach ($mods as $mod) {
                if (self::hard_archive_cmid_from_course_page((int)$mod->id)) {
                    $removed++;
                }
            }
        }

        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache($courseid, true);

        return $removed;
    }



    public static function hard_archive_required_activities_in_course(int $courseid): int {
        global $DB, $CFG;

        $removed = 0;
        $mainsection = self::get_main_workshop_section_name();

        // 1) Remove every explicitly linked required activity from the visible course sequence.
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))) {
            $editions = $DB->get_records_sql(
                "SELECT e.*
                   FROM {local_ga_workshop_editions} e
                   JOIN {local_ga_workshops} w ON w.id = e.workshopid
                  WHERE w.courseid = :courseid",
                ['courseid' => $courseid]
            );
            foreach ($editions as $edition) {
                if (!empty($edition->requiredcmid)) {
                    if (self::hard_archive_cmid_from_course_page((int)$edition->requiredcmid)) {
                        $removed++;
                    }
                }
            }
        }

        // 2) Remove assignments/quizzes located inside the TALLERES TIPO A section.
        // These are workshop-internal activities and must not live as loose course activities.
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND m.name IN ('assign', 'quiz')
                   AND cs.name = :sectionname";
        $mods = $DB->get_records_sql($sql, ['courseid' => $courseid, 'sectionname' => $mainsection]);
        foreach ($mods as $mod) {
            if (self::hard_archive_cmid_from_course_page((int)$mod->id)) {
                $removed++;
            }
        }

        // 3) Remove assignments/quizzes that are restricted to an automatic workshop group/grouping.
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {groupings} gg ON gg.id = cm.groupingid
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND m.name IN ('assign', 'quiz')
                   AND (
                        cm.availability LIKE :availability
                        OR gg.name LIKE :groupingname
                   )";
        $mods = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'availability' => '%"type":"group"%',
            'groupingname' => 'Agrupación Taller%',
        ]);
        foreach ($mods as $mod) {
            if (self::hard_archive_cmid_from_course_page((int)$mod->id)) {
                $removed++;
            }
        }

        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache($courseid, true);

        return $removed;
    }



    public static function get_default_certificate_template(): string {
        return '<p>Se certifica que <strong>{alumno}</strong> ha participado y completado satisfactoriamente el taller <strong>{taller}</strong>, realizado el día <strong>{fecha}</strong>, con una duración de <strong>{horas}</strong> horas, dentro del programa de <strong>Talleres Tipo A</strong>.</p>';
    }

    public static function get_certificate_template_html(): string {
        $value = get_config('local_gestion_actividades', 'certificatetemplatehtml');
        if ($value === false || trim((string)$value) === '') {
            return self::get_default_certificate_template();
        }
        return (string)$value;
    }

    public static function save_certificate_template_html(string $html): void {
        set_config('certificatetemplatehtml', $html, 'local_gestion_actividades');
    }

    

    public static function user_submitted_required_activity(int $userid, int $cmid): bool {
        global $DB;
        if ($cmid <= 0) {
            return true;
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
        if (!$cm) {
            return false;
        }
        $module = $DB->get_record('modules', ['id' => (int)$cm->module], 'id,name', IGNORE_MISSING);
        if (!$module) {
            return false;
        }

        if ($module->name === 'assign') {
            if (!$DB->get_manager()->table_exists(new \xmldb_table('assign_submission'))) {
                return false;
            }
            return $DB->record_exists_select(
                'assign_submission',
                'assignment = :assignment AND userid = :userid AND status = :status',
                [
                    'assignment' => (int)$cm->instance,
                    'userid' => $userid,
                    'status' => 'submitted',
                ]
            );
        }

        if ($module->name === 'quiz') {
            if (!$DB->get_manager()->table_exists(new \xmldb_table('quiz_attempts'))) {
                return false;
            }
            return $DB->record_exists_select(
                'quiz_attempts',
                'quiz = :quiz AND userid = :userid AND state = :state',
                [
                    'quiz' => (int)$cm->instance,
                    'userid' => $userid,
                    'state' => 'finished',
                ]
            );
        }

        return self::user_completed_required_activity($userid, $cmid);
    }

    public static function user_is_certificate_eligible(int $editionid, int $userid): bool {
        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);

        if (!self::is_user_attended_edition($editionid, $userid)) {
            return false;
        }

        if (self::is_typeb_workshop($workshop)) {
            // Tipo B interno: asistencia confirmada + Tarea Moodle de reflexión
            // entregada. Única regla, compartida con typeb_certificate_policy.
            return typeb_certificate_policy::is_eligible($editionid, $userid);
        }

        $types = self::get_required_activity_types($edition);
        if (in_array('quiz', $types, true)) {
            return self::quiz_missing_requirement($edition, $userid) === null;
        }
        return self::user_has_submitted_internal_task($editionid, $userid)
            && self::user_has_passing_internal_task_grade($editionid, $userid);
    }

    public static function replace_certificate_placeholders(string $html, \stdClass $user, \stdClass $workshop, \stdClass $edition, string $certcode): string {
        $hours = !empty($workshop->hours) ? (string)(float)$workshop->hours : '';
        $date = !empty($edition->sessiondate) ? userdate((int)$edition->sessiondate, get_string('strftimedatefullshort', 'langconfig')) : userdate(time(), get_string('strftimedatefullshort', 'langconfig'));
        $taskgrade = null;
        if (!empty($edition->id) && !empty($user->id) && in_array('assign', self::get_required_activity_types($edition), true)) {
            $taskgrade = self::get_internal_task_grade((int)$edition->id, (int)$user->id);
        }
        $taskgradestring = $taskgrade !== null ? format_float((float)$taskgrade, 2, true) : '';
        $replacements = [
            '{alumno}' => fullname($user),
            '{taller}' => (string)$workshop->name,
            '{codigo_taller}' => (string)$workshop->code,
            '{fecha}' => $date,
            '{horas}' => $hours,
            '{curso_academico}' => userdate(time(), '%Y'),
            '{fecha_emision}' => userdate(time(), get_string('strftimedatefullshort', 'langconfig')),
            '{codigo_certificado}' => $certcode,
            '{nota_tarea}' => $taskgradestring,
            '{nota_tarea_texto}' => $taskgradestring !== '' ? 'Nota de la tarea: ' . $taskgradestring . ' / 10' : '',
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    public static function render_certificate_pdf_string(\stdClass $user, \stdClass $workshop, \stdClass $edition, string $certcode): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new \pdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Gestion_actividades');
        $pdf->SetAuthor('Universidad Católica de Valencia');
        $pdf->SetTitle(get_string('certificate', 'local_gestion_actividades') . ' - ' . fullname($user));
        $pdf->SetMargins(25, 25, 25);
        $pdf->SetAutoPageBreak(false, 20);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $bg = dirname(__DIR__, 2) . '/pix/certificate_ucv_bg.jpg';
        if (file_exists($bg)) {
            $pdf->Image($bg, 0, 0, 210, 297, '', '', '', false, 300, '', false, false, 0);
        }

        $green = [43, 75, 30];

        $pdf->SetTextColor($green[0], $green[1], $green[2]);
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetXY(25, 72);
        $pdf->Cell(160, 12, get_string('certificatetitle', 'local_gestion_actividades'), 0, 1, 'C');

        $template = self::get_certificate_template_html();
        $content = self::replace_certificate_placeholders($template, $user, $workshop, $edition, $certcode);
        if (self::is_typeb_workshop($workshop)) {
            $content .= '<p style="text-align:center;"><strong>Taller Tipo B</strong></p>';
        }

        $pdf->SetTextColor(40, 40, 40);
        $pdf->SetFont('helvetica', '', 13);
        $html = '<div style="font-size:13pt;line-height:1.55;text-align:justify;color:#222;">' . $content . '</div>';
        $pdf->writeHTMLCell(160, 0, 25, 100, $html, 0, 1, false, true, 'J', true);

        if (!empty($edition->id) && !empty($user->id) && in_array('assign', self::get_required_activity_types($edition), true)) {
            $taskgrade = self::get_internal_task_grade((int)$edition->id, (int)$user->id);
            if ($taskgrade !== null) {
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->SetTextColor($green[0], $green[1], $green[2]);
                $gradehtml = '<div style="font-size:11pt;color:#2b4b1e;text-align:center;">'
                    . '<strong>Nota de la tarea:</strong> ' . s(format_float((float)$taskgrade, 2, true)) . ' / 10'
                    . '</div>';
                $pdf->writeHTMLCell(160, 0, 25, 166, $gradehtml, 0, 1, false, true, 'C', true);
            }
        }

        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY(25, 182);
        $pdf->MultiCell(160, 8, get_string('certificateissuedon', 'local_gestion_actividades') . ' ' . userdate(time(), get_string('strftimedatefullshort', 'langconfig')), 0, 'R');

        $pdf->SetDrawColor(80, 80, 80);
        $pdf->Line(125, 212, 180, 212);
        $pdf->SetXY(120, 214);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(65, 5, get_string('certificatesignatureplaceholder', 'local_gestion_actividades'), 0, 'C');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetXY(25, 238);
        $pdf->Cell(160, 5, get_string('certificatecode', 'local_gestion_actividades') . ': ' . $certcode, 0, 1, 'C');

        return $pdf->Output('', 'S');
    }

    public static function generate_certificate_for_user(int $editionid, int $userid, bool $syncgrades = true): ?\stdClass {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))) {
            return null;
        }

        if (!self::user_is_certificate_eligible($editionid, $userid)) {
            return null;
        }

        $existing = $DB->get_record('local_ga_certificates', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING);
        if ($existing) {
            return $existing;
        }

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $course = $DB->get_record('course', ['id' => (int)$workshop->courseid], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $context = \context_course::instance((int)$course->id);

        $now = time();
        $certtype = self::is_typeb_workshop($workshop) ? 'typeb' : 'typea';
        $certprefix = $certtype === 'typeb' ? 'TB-' : 'TA-';
        $certcode = $certprefix . (int)$editionid . '-' . (int)$userid . '-' . strtoupper(substr(sha1($editionid . ':' . $userid . ':' . $now), 0, 8));
        $filename = clean_filename('certificado_' . $workshop->code . '_' . $userid . '.pdf');

        $record = (object)[
            'userid' => $userid,
            'courseid' => (int)$course->id,
            'workshopid' => (int)$workshop->id,
            'editionid' => $editionid,
            'certificatetype' => $certtype,
            'certcode' => $certcode,
            'filename' => $filename,
            'status' => 'generated',
            'timeissued' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $certid = $DB->insert_record('local_ga_certificates', $record);

        $pdf = self::render_certificate_pdf_string($user, $workshop, $edition, $certcode);

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_gestion_actividades', 'certificate', $certid);
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_gestion_actividades',
            'filearea' => 'certificate',
            'itemid' => $certid,
            'filepath' => '/',
            'filename' => $filename,
        ];
        $fs->create_file_from_string($filerecord, $pdf);

        self::invalidate_block_cache_for_user($userid);
        if ($syncgrades) {
            grade_manager::sync_user_for_course_safely((int)$course->id, $userid);
        }

        return $DB->get_record('local_ga_certificates', ['id' => $certid], '*', MUST_EXIST);
    }


    public static function ensure_typeb_reflections_table(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_reflections'));
    }

    public static function get_typeb_reflection(int $editionid, int $userid): ?\stdClass {
        global $DB;
        if (!self::ensure_typeb_reflections_table()) {
            return null;
        }
        $record = $DB->get_record('local_ga_typeb_reflections', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    public static function user_has_typeb_reflection(int $editionid, int $userid): bool {
        $record = self::get_typeb_reflection($editionid, $userid);
        return $record && trim((string)($record->reflectiontext ?? '')) !== '';
    }

    public static function save_typeb_reflection(int $editionid, int $userid, string $text): bool {
        global $DB;
        if (!self::ensure_typeb_reflections_table()) {
            return false;
        }
        $text = trim(clean_param($text, PARAM_TEXT));
        if ($text === '') {
            return false;
        }
        $now = time();
        $existing = $DB->get_record('local_ga_typeb_reflections', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING);
        $record = (object)[
            'editionid' => $editionid,
            'userid' => $userid,
            'reflectiontext' => $text,
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record('local_ga_typeb_reflections', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_ga_typeb_reflections', $record);
        }
        grade_manager::sync_user_safely($userid);
        return true;
    }

    public static function list_user_typeb_workshop_certificates(int $userid): array {
        global $DB;
        if ($userid <= 0 || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))) {
            return [];
        }
        $columns = $DB->get_columns('local_ga_certificates');
        if (!isset($columns['certificatetype'])) {
            return [];
        }
        $reflectionjoin = '';
        $reflectionfield = "'' AS reflectiontext";
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_reflections'))) {
            $reflectionjoin = 'LEFT JOIN {local_ga_typeb_reflections} tr ON tr.editionid = cert.editionid AND tr.userid = cert.userid';
            $reflectionfield = 'tr.reflectiontext';
        }
        $sql = "SELECT cert.id, cert.userid, cert.courseid, cert.workshopid, cert.editionid,
                       cert.certcode, cert.filename, cert.status, cert.timeissued,
                       w.code AS workshopcode, w.name AS workshopname, w.hours,
                       c.fullname AS coursename,
                       e.name AS editionname, e.editioncode, e.requiredcmid, $reflectionfield
                  FROM {local_ga_certificates} cert
                  JOIN {local_ga_workshops} w ON w.id = cert.workshopid
                  JOIN {course} c ON c.id = cert.courseid
             LEFT JOIN {local_ga_workshop_editions} e ON e.id = cert.editionid
                       $reflectionjoin
                 WHERE cert.userid = :userid AND cert.certificatetype = 'typeb'
              ORDER BY cert.timeissued DESC, cert.id DESC";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);
        foreach ($records as $id => $record) {
            if (empty($record->requiredcmid)) {
                continue;
            }
            try {
                $summary = typeb_reflection_activity::submission_summary((int)$record->editionid, (int)$record->userid);
                if (empty($summary->submitted)) {
                    unset($records[$id]);
                    continue;
                }
                if (trim((string)($summary->text ?? '')) !== '') {
                    $record->reflectiontext = (string)$summary->text;
                }
            } catch (\Throwable $e) {
                unset($records[$id]);
            }
        }
        return $records;
    }


    public static function certificate_missing_requirements(int $editionid, int $userid): array {
        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $missing = [];

        if (!self::is_user_attended_edition($editionid, $userid)) {
            $missing[] = 'asistencia';
        }

        if (self::is_typeb_workshop($workshop)) {
            return typeb_certificate_policy::missing_requirements($editionid, $userid);
        }

        $types = self::get_required_activity_types($edition);
        if (in_array('quiz', $types, true)) {
            $quizmissing = self::quiz_missing_requirement($edition, $userid);
            if ($quizmissing !== null) {
                $missing[] = $quizmissing;
            }
        } else if (!self::user_has_submitted_internal_task($editionid, $userid)) {
            $missing[] = 'tarea';
        } else if (!self::user_has_passing_internal_task_grade($editionid, $userid)) {
            $missing[] = 'nota de tarea igual o superior a 5';
        }

        return $missing;
    }

    protected static function mail_from_user(): \stdClass {
        if (class_exists('\core_user')) {
            try {
                return \core_user::get_noreply_user();
            } catch (\Throwable $e) {
                // Fall back below.
            }
        }
        return get_admin();
    }

    protected static function send_plain_notification_email(\stdClass $to, string $subject, string $message, string $htmlmessage = ''): bool {
        if (empty($to->email) || !empty($to->deleted) || (isset($to->suspended) && !empty($to->suspended))) {
            return false;
        }

        try {
            $from = self::mail_from_user();
            return email_to_user($to, $from, $subject, $message, $htmlmessage ?: text_to_html($message));
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function notify_certificate_available(\stdClass $user, \stdClass $workshop, \stdClass $edition, ?\stdClass $certificate): bool {
        $workshopname = trim((string)$workshop->code . ' - ' . (string)$workshop->name);
        $url = $certificate && !empty($certificate->id)
            ? (new \moodle_url('/local/gestion_actividades/certificate_download.php', ['id' => (int)$certificate->id]))->out(false)
            : (new \moodle_url('/local/gestion_actividades/mycertificates.php'))->out(false);

        $subject = 'Certificado disponible: ' . $workshopname;
        $message = "Hola " . fullname($user) . ",\n\n"
            . "Ya está disponible tu certificado del taller " . $workshopname . ".\n\n"
            . "Puedes descargarlo aquí:\n" . $url . "\n\n"
            . "Un saludo.";
        $html = \html_writer::tag('p', 'Hola ' . s(fullname($user)) . ',')
            . \html_writer::tag('p', 'Ya está disponible tu certificado del taller ' . s($workshopname) . '.')
            . \html_writer::tag('p', \html_writer::link($url, 'Descargar certificado'))
            . \html_writer::tag('p', 'Un saludo.');

        return self::send_plain_notification_email($user, $subject, $message, $html);
    }

    protected static function notify_certificate_missing(\stdClass $user, \stdClass $workshop, \stdClass $edition, array $missing): bool {
        $workshopname = trim((string)$workshop->code . ' - ' . (string)$workshop->name);
        $missing = array_values(array_unique($missing));
        $missingtext = count($missing) > 1 ? implode(' y ', $missing) : ($missing[0] ?? 'requisitos');

        $subject = 'Certificado no generado: ' . $workshopname;
        $message = "Hola " . fullname($user) . ",\n\n"
            . "No se ha generado tu certificado del taller " . $workshopname . " porque falta: " . $missingtext . ".\n\n"
            . "Un saludo.";
        $html = \html_writer::tag('p', 'Hola ' . s(fullname($user)) . ',')
            . \html_writer::tag('p', 'No se ha generado tu certificado del taller ' . s($workshopname) . ' porque falta: ' . s($missingtext) . '.')
            . \html_writer::tag('p', 'Un saludo.');

        return self::send_plain_notification_email($user, $subject, $message, $html);
    }

    protected static function notify_certificate_summary_to_staff(int $editionid, \stdClass $summary): int {
        global $DB;

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);
        $workshopname = trim((string)$workshop->code . ' - ' . (string)$workshop->name);
        $url = (new \moodle_url('/local/gestion_actividades/certificates.php', ['editionid' => $editionid]))->out(false);

        $recipients = [];

        foreach (self::get_edition_teachers($editionid) as $teacher) {
            $recipients[(int)$teacher->id] = $teacher;
        }

        foreach (self::list_authorized_users() as $manager) {
            $recipients[(int)$manager->id] = $manager;
        }

        $subject = 'Certificados disponibles: ' . $workshopname;
        $message = "Los certificados del taller " . $workshopname . " ya están disponibles.\n\n"
            . "Generados nuevos: " . (int)$summary->generated . "\n"
            . "Ya existentes: " . (int)$summary->existing . "\n"
            . "No elegibles: " . (int)$summary->skipped . "\n\n"
            . "Ver certificados:\n" . $url . "\n\n"
            . "Un saludo.";
        $html = \html_writer::tag('p', 'Los certificados del taller ' . s($workshopname) . ' ya están disponibles.')
            . \html_writer::tag('ul',
                \html_writer::tag('li', 'Generados nuevos: ' . (int)$summary->generated)
                . \html_writer::tag('li', 'Ya existentes: ' . (int)$summary->existing)
                . \html_writer::tag('li', 'No elegibles: ' . (int)$summary->skipped)
            )
            . \html_writer::tag('p', \html_writer::link($url, 'Ver certificados'));

        $sent = 0;
        foreach ($recipients as $recipient) {
            if (self::send_plain_notification_email($recipient, $subject, $message, $html)) {
                $sent++;
            }
        }

        return $sent;
    }

    public static function generate_certificates_for_edition(int $editionid): \stdClass {
        global $DB;

        $summary = (object)[
            'eligible' => 0,
            'generated' => 0,
            'existing' => 0,
            'skipped' => 0,
            'studentemails' => 0,
            'staffemails' => 0,
        ];

        $edition = self::get_workshop_edition($editionid);
        $workshop = self::get_workshop((int)$edition->workshopid);

        $students = self::list_edition_enrolled_users_ultrasafe($editionid);
        foreach ($students as $student) {
            $userid = (int)$student->userid;
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$user) {
                $summary->skipped++;
                continue;
            }

            if (self::user_is_certificate_eligible($editionid, $userid)) {
                $summary->eligible++;
                $before = self::get_user_certificate_for_edition($editionid, $userid);
                $cert = self::generate_certificate_for_user($editionid, $userid, false);
                if ($cert && $before) {
                    $summary->existing++;
                } else if ($cert) {
                    $summary->generated++;
                }

                // Only notify certificates issued in this run; re-running the
                // generation must not e-mail students who already had one.
                if ($cert && !$before && self::notify_certificate_available($user, $workshop, $edition, $cert)) {
                    $summary->studentemails++;
                }
            } else {
                $summary->skipped++;
                $missing = self::certificate_missing_requirements($editionid, $userid);
                if (self::notify_certificate_missing($user, $workshop, $edition, $missing)) {
                    $summary->studentemails++;
                }
            }
        }

        if ($summary->generated > 0) {
            grade_manager::sync_course_safely((int)$workshop->courseid);
        }
        $summary->staffemails = self::notify_certificate_summary_to_staff($editionid, $summary);

        return $summary;
    }



    public static function ensure_typeb_transfers_table(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_transfers'));
    }

    public static function get_user_typeb_transfer_totals(int $userid): \stdClass {
        global $DB;
        $out = (object)['count' => 0, 'hours' => 0.0];
        if ($userid <= 0 || !self::ensure_typeb_transfers_table()) {
            return $out;
        }
        $row = $DB->get_record_sql("SELECT COUNT(id) AS cnt, COALESCE(SUM(hours), 0) AS hours
                                      FROM {local_ga_typeb_transfers}
                                     WHERE userid = :userid AND status = :status", ['userid' => $userid, 'status' => 'active'], IGNORE_MISSING);
        if ($row) {
            $out->count = (int)($row->cnt ?? 0);
            $out->hours = (float)($row->hours ?? 0);
        }
        return $out;
    }

    public static function list_user_typeb_transfers(int $userid): array {
        global $DB;
        if ($userid <= 0 || !self::ensure_typeb_transfers_table()) {
            return [];
        }
        $sql = "SELECT t.*, w.name AS workshopname, w.code AS workshopcode, e.name AS editionname, e.sessiondate, c.certcode
                  FROM {local_ga_typeb_transfers} t
             LEFT JOIN {local_ga_workshops} w ON w.id = t.workshopid
             LEFT JOIN {local_ga_workshop_editions} e ON e.id = t.editionid
             LEFT JOIN {local_ga_certificates} c ON c.id = t.certificateid
                 WHERE t.userid = :userid AND t.status = :status
              ORDER BY t.timecreated DESC, t.id DESC";
        return $DB->get_records_sql($sql, ['userid' => $userid, 'status' => 'active']);
    }

    public static function list_all_typeb_transfers(): array {
        global $DB;
        if (!self::ensure_typeb_transfers_table()) {
            return [];
        }
        $sql = "SELECT t.*, u.firstname, u.lastname, u.email,
                       w.name AS workshopname, w.code AS workshopcode, w.hours AS originalhours,
                       e.name AS editionname, e.sessiondate
                  FROM {local_ga_typeb_transfers} t
                  JOIN {user} u ON u.id = t.userid
             LEFT JOIN {local_ga_workshops} w ON w.id = t.workshopid
             LEFT JOIN {local_ga_workshop_editions} e ON e.id = t.editionid
                 WHERE t.status = :status
              ORDER BY t.timecreated DESC, u.lastname ASC, u.firstname ASC";
        return $DB->get_records_sql($sql, ['status' => 'active']);
    }

    public static function list_user_transferable_typea_certificates(int $userid): array {
        global $DB;
        if ($userid <= 0 || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))
            || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))
            || !self::ensure_typeb_transfers_table()) {
            return [];
        }
        $columns = $DB->get_columns('local_ga_certificates');
        $typefilter = isset($columns['certificatetype']) ? " AND (c.certificatetype = 'typea' OR c.certificatetype IS NULL OR c.certificatetype = '')" : '';
        $sql = "SELECT c.id, c.userid, c.courseid, c.workshopid, c.editionid, c.certcode, c.timeissued,
                       w.name AS workshopname, w.code AS workshopcode, w.hours,
                       e.name AS editionname, e.sessiondate
                  FROM {local_ga_certificates} c
                  JOIN {local_ga_workshops} w ON w.id = c.workshopid
                  JOIN {local_ga_workshop_editions} e ON e.id = c.editionid
             LEFT JOIN {local_ga_typeb_transfers} t ON t.certificateid = c.id AND t.status = 'active'
                 WHERE c.userid = :userid
                   $typefilter
                   AND t.id IS NULL
              ORDER BY c.timeissued DESC, e.sessiondate DESC";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);
        $window = self::get_user_transfer_window($userid);
        $maximum = (float)($window->maxtransfer ?? 0);
        foreach ($records as $id => $record) {
            $hours = round(max(0.0, (float)($record->hours ?? 0)), 2);
            if ($hours <= 0 || $hours > $maximum + 0.001) {
                unset($records[$id]);
            }
        }
        return $records;
    }

    public static function get_user_transfer_window(int $userid): \stdClass {
        // Same net A/B figures as the portfolio, the block and the gradebook.
        $hours = hours_calculator::for_user($userid);
        $typeahours = (float)$hours->typeahours;
        $typebhours = (float)$hours->typebhours;

        $excessa = max(0.0, $typeahours - 32.0);
        $remainingb = max(0.0, 22.0 - $typebhours);
        $maxtransfer = min($excessa, $remainingb);

        return (object)[
            'typeahours' => round($typeahours, 2),
            'typebhours' => round($typebhours, 2),
            'excessa' => round($excessa, 2),
            'remainingb' => round($remainingb, 2),
            'maxtransfer' => round($maxtransfer, 2),
            'cantransfer' => $maxtransfer > 0.0,
        ];
    }

    public static function transfer_typea_certificate_to_typeb(int $userid, int $certificateid, string $reflectiontext): int {
        global $DB;

        $userid = max(0, $userid);
        $certificateid = max(0, $certificateid);
        $reflectiontext = trim($reflectiontext);
        if ($userid <= 0 || $certificateid <= 0 || $reflectiontext === '' || !self::ensure_typeb_transfers_table()) {
            return 0;
        }
        if ($DB->record_exists('local_ga_typeb_transfers', ['certificateid' => $certificateid, 'status' => 'active'])) {
            return 0;
        }

        $options = self::list_user_transferable_typea_certificates($userid);
        if (empty($options[$certificateid])) {
            return 0;
        }
        $cert = $options[$certificateid];
        $window = self::get_user_transfer_window($userid);
        if (empty($window->cantransfer)) {
            return 0;
        }

        $hours = round(max(0.0, (float)($cert->hours ?? 0)), 2);
        // Solo se permiten talleres completos; nunca se recortan horas para encajar.
        if ($hours <= 0 || $hours > (float)$window->maxtransfer + 0.001) {
            return 0;
        }

        $now = time();
        $record = (object)[
            'userid' => $userid,
            'certificateid' => $certificateid,
            'workshopid' => (int)$cert->workshopid,
            'editionid' => (int)$cert->editionid,
            'courseid' => (int)$cert->courseid,
            'hours' => $hours,
            'reflectiontext' => $reflectiontext,
            'status' => 'active',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $id = (int)$DB->insert_record('local_ga_typeb_transfers', $record);
        self::invalidate_block_cache_for_user($userid);
        grade_manager::sync_user_safely($userid);
        return $id;
    }


    public static function get_user_certificate_for_edition(int $editionid, int $userid): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))) {
            return null;
        }
        return $DB->get_record('local_ga_certificates', ['editionid' => $editionid, 'userid' => $userid], '*', IGNORE_MISSING) ?: null;
    }

    public static function list_user_certificates(int $userid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))) {
            return [];
        }
        $columns = $DB->get_columns('local_ga_certificates');
        $typefilter = isset($columns['certificatetype']) ? " AND (c.certificatetype = 'typea' OR c.certificatetype IS NULL OR c.certificatetype = '')" : '';
        $sql = "SELECT c.*, w.name AS workshopname, w.code AS workshopcode, w.hours, e.name AS editionname, e.sessiondate, co.fullname AS coursename
                  FROM {local_ga_certificates} c
                  JOIN {local_ga_workshops} w ON w.id = c.workshopid
                  JOIN {local_ga_workshop_editions} e ON e.id = c.editionid
                  JOIN {course} co ON co.id = c.courseid
                 WHERE c.userid = :userid $typefilter
              ORDER BY c.timeissued DESC";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    public static function list_edition_certificates(int $editionid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))) {
            return [];
        }
        $sql = "SELECT c.*, u.firstname, u.lastname, u.email, w.name AS workshopname, w.code AS workshopcode
                  FROM {local_ga_certificates} c
                  JOIN {user} u ON u.id = c.userid
                  JOIN {local_ga_workshops} w ON w.id = c.workshopid
                 WHERE c.editionid = :editionid
              ORDER BY u.lastname, u.firstname";
        return $DB->get_records_sql($sql, ['editionid' => $editionid]);
    }



    private static function invalidate_block_cache_for_user(int $userid): void {
        global $CFG;

        $userid = max(0, $userid);
        if ($userid <= 0) {
            return;
        }

        try {
            if (!function_exists('block_gestion_hee_invalidate_user_cache')) {
                $blocklib = $CFG->dirroot . '/blocks/gestion_hee/lib.php';
                if (is_readable($blocklib)) {
                    require_once($blocklib);
                }
            }
            if (function_exists('block_gestion_hee_invalidate_user_cache')) {
                block_gestion_hee_invalidate_user_cache($userid);
            }
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se ha podido invalidar la caché del bloque Gestión HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    private static function invalidate_block_cache_for_workshop_users(int $workshopid): void {
        global $DB;

        $workshopid = max(0, $workshopid);
        if ($workshopid <= 0) {
            return;
        }

        $userids = [];
        $dbman = $DB->get_manager();

        try {
            if ($dbman->table_exists(new \xmldb_table('local_ga_certificates'))) {
                $rows = $DB->get_records('local_ga_certificates', ['workshopid' => $workshopid], '', 'id, userid');
                foreach ($rows as $row) {
                    if (!empty($row->userid)) {
                        $userids[] = (int)$row->userid;
                    }
                }
            }

            if ($dbman->table_exists(new \xmldb_table('local_ga_hour_history'))) {
                $rows = $DB->get_records('local_ga_hour_history', ['workshopid' => $workshopid], '', 'id, userid');
                foreach ($rows as $row) {
                    if (!empty($row->userid)) {
                        $userids[] = (int)$row->userid;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se han podido localizar usuarios para invalidar caché del bloque Gestión HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        foreach (array_unique($userids) as $userid) {
            self::invalidate_block_cache_for_user((int)$userid);
        }
    }

}
