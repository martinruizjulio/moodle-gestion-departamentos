<?php
namespace local_hee_demo;

defined('MOODLE_INTERNAL') || die();

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\workshop_series;
use local_gestion_actividades\local\attendance_sync;
use local_gestion_actividades\local\auto_certificates;
use local_gestion_actividades\local\course_layout;
use local_gestion_actividades\local\grade_manager;

/**
 * Demo data for presentations: fictitious students with real movement in the
 * Ediciones de talleres (enrolments, attendance list, quiz grades, Type B
 * reflections). Everything goes through the same tables/APIs the real flow
 * uses, so lists, certificates and hours behave exactly as in production.
 *
 * Demo accounts are marked with idnumber HEE_DEMO and username heedemoNNN;
 * purge() removes them and everything they generated.
 */
class generator {
    public const IDNUMBER = 'HEE_DEMO';
    public const PREFIX = 'heedemo';

    private const FIRSTNAMES = ['Lucía', 'Hugo', 'Martina', 'Mateo', 'Sofía', 'Leo', 'María', 'Daniel', 'Julia', 'Pablo',
        'Paula', 'Álvaro', 'Valeria', 'Adrián', 'Emma', 'Manuel', 'Daniela', 'Diego', 'Carla', 'Mario', 'Alba', 'Javier',
        'Noa', 'Marcos', 'Sara', 'Izan', 'Carmen', 'Alejandro', 'Vega', 'Bruno', 'Lola', 'Gonzalo', 'Claudia', 'Nicolás',
        'Irene', 'Sergio', 'Elena', 'Jorge', 'Marta', 'Andrés', 'Laura', 'Rubén', 'Ainhoa', 'Iván', 'Nerea', 'Raúl'];
    private const SURNAMES = ['García', 'Martínez', 'López', 'Sánchez', 'Pérez', 'Gómez', 'Martín', 'Jiménez', 'Ruiz',
        'Hernández', 'Díaz', 'Moreno', 'Muñoz', 'Álvarez', 'Romero', 'Alonso', 'Gutiérrez', 'Navarro', 'Torres',
        'Domínguez', 'Vázquez', 'Ramos', 'Gil', 'Ramírez', 'Serrano', 'Blanco', 'Molina', 'Morales', 'Suárez', 'Ortega',
        'Delgado', 'Castro', 'Ortiz', 'Rubio', 'Marín', 'Sanz', 'Núñez', 'Iglesias', 'Medina', 'Garrido', 'Cortés',
        'Castillo', 'Santos', 'Lozano', 'Guerrero', 'Cano', 'Prieto', 'Méndez', 'Cruz', 'Calvo'];
    private const REFLECTIONS = [
        'El taller me ha ayudado a entender mejor cómo aplicar lo aprendido en situaciones reales del aula.',
        'Me ha parecido muy útil la parte práctica; me llevo varias ideas para trabajar en grupo.',
        'Lo más valioso ha sido el intercambio de experiencias con los compañeros y el profesor.',
        'He descubierto recursos que no conocía y que pienso usar en las prácticas.',
        'La sesión ha sido dinámica y bien organizada. Me habría gustado tener algo más de tiempo para los casos.',
        'Me ha hecho reflexionar sobre la importancia de la comunicación y la escucha activa.',
        'He aprendido a planificar mejor y a evaluar el trabajo propio con criterios claros.',
        'Ha sido una experiencia enriquecedora que conecta la teoría con la práctica profesional.',
    ];

    /** Demo accounts still present: id => user. */
    public static function demo_users(): array {
        global $DB;
        return $DB->get_records_select('user', 'idnumber = :idn AND deleted = 0 AND ' . $DB->sql_like('username', ':p'),
            ['idn' => self::IDNUMBER, 'p' => self::PREFIX . '%'], 'username ASC');
    }

    /** Courses that have Ediciones de talleres. */
    public static function courses_with_series(): array {
        global $DB;
        $ids = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {' . workshop_series::TABLE . '}');
        if (!$ids) {
            return [];
        }
        return $DB->get_records_list('course', 'id', $ids, 'fullname ASC', 'id, fullname, shortname');
    }

    /** Workshop editions (one per taller) of an Edición. */
    public static function series_editions(int $seriesid): array {
        global $DB;
        $out = [];
        foreach (workshop_series::items($seriesid) as $item) {
            $eds = $DB->get_records('local_ga_workshop_editions', ['workshopid' => (int)$item->workshopid, 'seriesid' => $seriesid], 'id ASC');
            foreach ($eds as $e) {
                $out[(int)$e->id] = (object)['edition' => $e, 'item' => $item];
            }
        }
        return $out;
    }

    /**
     * Create (or reuse) the pool of demo students and enrol them in the course.
     *
     * @return int[] user ids
     */
    public static function ensure_pool(int $courseid, int $size, string $password): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $course = get_course($courseid);
            $enrol->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        }
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $existing = [];
        foreach (self::demo_users() as $u) {
            $existing[$u->username] = $u;
        }
        // Deterministic but varied names: firstname + two surnames.
        $names = [];
        $nf = count(self::FIRSTNAMES);
        $ns = count(self::SURNAMES);
        for ($i = 0; count($names) < $size; $i++) {
            $first = self::FIRSTNAMES[($i * 7) % $nf];
            $last = self::SURNAMES[($i * 11) % $ns] . ' ' . self::SURNAMES[($i * 17 + 5) % $ns];
            $names[] = [$first, $last];
        }

        $ids = [];
        for ($n = 1; $n <= $size; $n++) {
            $username = self::PREFIX . sprintf('%03d', $n);
            if (isset($existing[$username])) {
                $uid = (int)$existing[$username]->id;
            } else {
                [$first, $last] = $names[$n - 1];
                $uid = (int)user_create_user((object)[
                    'username' => $username,
                    'password' => $password,
                    'firstname' => $first,
                    'lastname' => $last,
                    'email' => $username . '@example.invalid',
                    'idnumber' => self::IDNUMBER,
                    'auth' => 'manual',
                    'confirmed' => 1,
                    'emailstop' => 1,
                    'mnethostid' => $CFG->mnet_localhost_id,
                    'lang' => $CFG->lang ?? 'es',
                ], true, false);
            }
            if (!is_enrolled(\context_course::instance($courseid), $uid)) {
                $enrol->enrol_user($instance, $uid, $studentrole);
            }
            $ids[] = $uid;
        }
        return $ids;
    }

    /**
     * Generate the movement in the chosen Ediciones.
     *
     * @param array $opts perworkshop, poolsize, present (%), fail (%), reflect (%), password, finish (bool)
     */
    public static function generate(int $courseid, array $seriesids, array $opts): \stdClass {
        global $DB, $USER;
        \core_php_time_limit::raise(1800);
        raise_memory_limit(MEMORY_HUGE);

        $report = (object)['users' => 0, 'enrolments' => 0, 'present' => 0, 'absent' => 0, 'quizpass' => 0,
            'quizfail' => 0, 'reflections' => 0, 'certificates' => 0, 'lines' => []];
        $pool = self::ensure_pool($courseid, (int)$opts['poolsize'], (string)$opts['password']);
        $report->users = count($pool);
        $touched = [];

        foreach ($seriesids as $sid) {
            $series = workshop_series::get((int)$sid);
            if ((int)$series->courseid !== $courseid) {
                continue;
            }
            foreach (self::series_editions((int)$sid) as $eid => $row) {
                $edition = manager::get_workshop_edition($eid);
                $workshop = manager::get_workshop((int)$edition->workshopid);
                $isb = manager::is_typeb_workshop($workshop);

                // 1. Enrolments: random students, several repeat across talleres.
                $enrolled = self::enrolled_demo_users($eid, $pool);
                $candidates = array_values(array_diff($pool, $enrolled));
                shuffle($candidates);
                $need = max(0, (int)$opts['perworkshop'] - count($enrolled));
                foreach (array_slice($candidates, 0, $need) as $uid) {
                    $res = manager::enrol_user_in_edition($eid, $uid, 'self');
                    if (empty($res->success)) {
                        $res = manager::enrol_user_in_edition($eid, $uid, 'manual');
                    }
                    if (!empty($res->success)) {
                        $report->enrolments++;
                    }
                }
                $enrolled = self::enrolled_demo_users($eid, $pool);
                $touched = array_merge($touched, $enrolled);

                // 2. Attendance list: most "Presente", a few "Falta".
                [$p, $a] = self::take_attendance($edition, $enrolled, (int)$opts['present']);
                $report->present += $p;
                $report->absent += $a;

                // 3. Quiz (Type A) or reflection (Type B).
                if ($isb) {
                    $report->reflections += self::submit_reflections($edition, $enrolled, (int)$opts['reflect']);
                    $detail = '';
                } else {
                    [$ok, $ko] = self::grade_quiz($edition, $enrolled, (int)$opts['fail']);
                    $report->quizpass += $ok;
                    $report->quizfail += $ko;
                    $detail = " · cuestionario: $ok aprobados, $ko suspensos";
                }
                $report->lines[] = format_string($workshop->name) . ' (' . ($isb ? 'B' : 'A') . '): ' . count($enrolled)
                    . " inscritos · asistencia: $p presentes, $a faltas" . $detail;
            }
            if (!empty($opts['finish'])) {
                foreach (array_keys(self::series_editions((int)$sid)) as $eid) {
                    $report->certificates += auto_certificates::process_edition($eid);
                }
                workshop_series::set_finished((int)$sid, true);
                course_layout::synchronise_course($courseid);
            }
        }
        $touched = array_values(array_unique($touched));
        if ($touched) {
            manager::invalidate_block_caches_for_users($touched);
        }
        manager::invalidate_teacher_block_cache();
        grade_manager::sync_course_safely($courseid);
        return $report;
    }

    /** Demo users with an active enrolment in an edition. */
    private static function enrolled_demo_users(int $editionid, array $pool): array {
        $out = [];
        $poolset = array_flip($pool);
        foreach (manager::list_edition_enrolled_users_ultrasafe($editionid) as $row) {
            $uid = (int)($row->userid ?? 0);
            if (isset($poolset[$uid])) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    /** @return int[] [present, absent] newly recorded */
    private static function take_attendance(\stdClass $edition, array $userids, int $presentpct): array {
        global $DB, $USER, $CFG;
        if (empty($edition->attendancecmid) || !$userids) {
            return [0, 0];
        }
        attendance_sync::ensure_session((int)$edition->id);
        $attid = (int)$DB->get_field('course_modules', 'instance', ['id' => (int)$edition->attendancecmid]);
        if (!$attid) {
            return [0, 0];
        }
        $groupid = (int)($edition->groupid ?? 0);
        // One session per day (two-day talleres have two).
        $sessions = array_values($DB->get_records_select('attendance_sessions', 'attendanceid = :a AND (groupid = :g OR groupid = 0)',
            ['a' => $attid, 'g' => $groupid], 'sessdate ASC, id ASC'));
        if (!$sessions) {
            return [0, 0];
        }
        $statuses = $DB->get_records('attendance_statuses', ['attendanceid' => $attid, 'deleted' => 0, 'setnumber' => 0], 'grade DESC, id ASC');
        if (count($statuses) < 2) {
            return [0, 0];
        }
        $presentst = reset($statuses);
        $absentst = end($statuses);
        $now = time();
        $p = 0;
        $a = 0;
        foreach ($userids as $uid) {
            if ($DB->record_exists('attendance_log', ['sessionid' => (int)$sessions[0]->id, 'studentid' => $uid])) {
                continue;
            }
            $present = random_int(1, 100) <= $presentpct;
            // Absent students miss one day (or the only day).
            $missday = $present ? -1 : random_int(0, count($sessions) - 1);
            foreach ($sessions as $i => $session) {
                $DB->insert_record('attendance_log', (object)[
                    'sessionid' => (int)$session->id, 'studentid' => $uid,
                    'statusid' => $i === $missday ? (int)$absentst->id : (int)$presentst->id,
                    'statusset' => implode(',', array_keys($statuses)), 'timetaken' => $now, 'takenby' => (int)$USER->id,
                    'remarks' => '', 'ipaddress' => '',
                ]);
            }
            $present ? $p++ : $a++;
        }
        foreach ($sessions as $session) {
            $DB->update_record('attendance_sessions', (object)['id' => (int)$session->id, 'lasttaken' => $now,
                'lasttakenby' => (int)$USER->id, 'timemodified' => $now]);
        }
        try {
            require_once($CFG->dirroot . '/mod/attendance/locallib.php');
            $att = $DB->get_record('attendance', ['id' => $attid], '*', MUST_EXIST);
            attendance_update_users_grade($att, $userids);
        } catch (\Throwable $e) {
            debugging('Demo: no se pudo actualizar la nota de asistencia: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        attendance_sync::sync_edition((int)$edition->id);
        return [$p, $a];
    }

    /** Finished quiz attempt + grade for everyone (a few below 5). @return int[] [pass, fail] */
    private static function grade_quiz(\stdClass $edition, array $userids, int $failpct): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $cmid = manager::get_quiz_requirement($edition)->cmid;
        $cm = $cmid > 0 ? get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING) : null;
        if (!$cm || !$userids) {
            return [0, 0];
        }
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $max = (float)$quiz->grade > 0 ? (float)$quiz->grade : 10.0;
        $context = \context_module::instance($cm->id);
        $now = time();
        $ok = 0;
        $ko = 0;
        foreach ($userids as $uid) {
            if ($DB->record_exists('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $uid])) {
                continue;
            }
            $fail = random_int(1, 100) <= $failpct;
            // Passing marks lean high (5-10), fails between 2 and 4.5.
            $mark = $fail ? random_int(20, 45) / 10 : max(random_int(50, 100), random_int(50, 100)) / 10;
            $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $context);
            $quba->set_preferred_behaviour('deferredfeedback');
            \question_engine::save_questions_usage_by_activity($quba);
            $start = $now - random_int(20, 90) * MINSECS;
            $DB->insert_record('quiz_attempts', (object)[
                'quiz' => $quiz->id, 'userid' => $uid, 'attempt' => 1, 'uniqueid' => $quba->get_id(), 'layout' => '',
                'currentpage' => 0, 'preview' => 0, 'state' => 'finished', 'timestart' => $start,
                'timefinish' => $start + random_int(8, 25) * MINSECS, 'timemodified' => $now, 'timemodifiedoffline' => 0,
                'sumgrades' => $mark,
            ]);
            $grade = round($mark / 10 * $max, 5);
            if ($old = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $uid])) {
                $DB->update_record('quiz_grades', (object)['id' => $old->id, 'grade' => $grade, 'timemodified' => $now]);
            } else {
                $DB->insert_record('quiz_grades', (object)['quiz' => $quiz->id, 'userid' => $uid, 'grade' => $grade, 'timemodified' => $now]);
            }
            $fail ? $ko++ : $ok++;
        }
        $quiz->cmidnumber = $cm->idnumber;
        quiz_update_grades($quiz);
        return [$ok, $ko];
    }

    /** Online-text reflection submitted (Type B). @return int submitted now */
    private static function submit_reflections(\stdClass $edition, array $userids, int $pct): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cmid = (int)($edition->requiredcmid ?? 0);
        $cm = $cmid > 0 ? get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING) : null;
        if (!$cm || !$userids) {
            return 0;
        }
        $course = get_course((int)$cm->course);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $n = 0;
        foreach ($userids as $uid) {
            // Stable per student and taller, so running it again never adds
            // reflections for those who "did not submit".
            if ((crc32('reflect-' . $uid . '-' . (int)$edition->id) % 100) >= $pct) {
                continue;
            }
            $existing = $assign->get_user_submission($uid, false);
            if ($existing && $existing->status === 'submitted') {
                continue;
            }
            $text = '<p>' . self::REFLECTIONS[array_rand(self::REFLECTIONS)] . '</p>';
            $notices = [];
            $saved = false;
            try {
                $saved = $assign->save_submission((object)['userid' => $uid,
                    'onlinetext_editor' => ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => 0]], $notices);
            } catch (\Throwable $e) {
                $saved = false;
            }
            $submission = $assign->get_user_submission($uid, true);
            if (!$saved || $submission->status !== 'submitted') {
                // Submissions closed (date): record it directly, as the API would.
                if (!$DB->record_exists('assignsubmission_onlinetext', ['submission' => $submission->id])) {
                    $DB->insert_record('assignsubmission_onlinetext', (object)['assignment' => $cm->instance,
                        'submission' => $submission->id, 'onlinetext' => $text, 'onlineformat' => FORMAT_HTML]);
                }
                $DB->update_record('assign_submission', (object)['id' => $submission->id, 'status' => 'submitted',
                    'timemodified' => time()]);
            }
            $n++;
        }
        return $n;
    }

    /** Counts of what purge() would remove. */
    public static function summary(): \stdClass {
        global $DB;
        $users = self::demo_users();
        $ids = array_keys($users);
        $s = (object)['users' => count($ids), 'enrolments' => 0, 'certificates' => 0];
        if ($ids) {
            [$in, $p] = $DB->get_in_or_equal($ids);
            $s->enrolments = $DB->count_records_select('local_ga_edition_enrolments', "userid $in", $p);
            $s->certificates = $DB->count_records_select('local_ga_certificates', "userid $in", $p);
        }
        return $s;
    }

    /** Remove every demo account and everything it generated. */
    public static function purge(): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/questionlib.php');
        \core_php_time_limit::raise(1800);
        $users = self::demo_users();
        if (!$users) {
            return 0;
        }
        $ids = array_keys($users);
        [$in, $p] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $editionids = $DB->get_fieldset_select('local_ga_edition_enrolments', 'DISTINCT editionid', "userid $in", $p);

        // Certificate PDFs.
        $fs = get_file_storage();
        foreach (['local_ga_certificates' => 'certificate', 'local_ga_typeb_certs' => 'typeb_certificate'] as $table => $area) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            foreach ($DB->get_fieldset_select($table, 'id', "userid $in", $p) as $certid) {
                $files = $DB->get_records('files', ['component' => 'local_gestion_actividades', 'filearea' => $area, 'itemid' => $certid]);
                foreach ($files as $f) {
                    $fs->get_file_instance($f)->delete();
                }
            }
        }
        // Gestión HEE records.
        foreach (['local_ga_certificates', 'local_ga_typeb_certs', 'local_ga_hour_history', 'local_ga_edition_enrolments',
                'local_ga_grades', 'local_ga_grade_log', 'local_ga_completions', 'local_ga_participants',
                'local_ga_typeb_reflections', 'local_ga_typeb_transfers', 'local_ga_task_submissions',
                'local_ga_institutional_hours', 'local_ga_candidates'] as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $DB->delete_records_select($table, "userid $in", $p);
            }
        }
        // Activity data: attendance, quiz attempts, reflections.
        $DB->delete_records_select('attendance_log', "studentid $in", $p);
        foreach ($DB->get_records_select('quiz_attempts', "userid $in", $p, '', 'id, uniqueid') as $att) {
            \question_engine::delete_questions_usage_by_activity((int)$att->uniqueid);
        }
        $DB->delete_records_select('quiz_attempts', "userid $in", $p);
        $DB->delete_records_select('quiz_grades', "userid $in", $p);
        $subids = $DB->get_fieldset_select('assign_submission', 'id', "userid $in", $p);
        if ($subids) {
            $DB->delete_records_list('assignsubmission_onlinetext', 'submission', $subids);
        }
        foreach (['assign_submission', 'assign_grades', 'assign_user_flags', 'assign_user_mapping'] as $table) {
            $DB->delete_records_select($table, "userid $in", $p);
        }
        // Accounts (enrolments, groups and gradebook go with them).
        foreach ($users as $u) {
            delete_user($u);
        }
        if ($editionids) {
            manager::invalidate_teacher_block_cache([], array_map('intval', $editionids));
        }
        return count($users);
    }
}
