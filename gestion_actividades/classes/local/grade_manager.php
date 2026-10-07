<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');

/**
 * Centralised HEE grade calculation and gradebook synchronisation.
 *
 * The class deliberately uses bulk queries for reports and only writes grades
 * whose value has actually changed.
 */
class grade_manager {
    public const SETTINGS_TABLE = 'local_ga_course_settings';
    public const ITEM_TYPEA = 'gestion_hee_typea';
    public const ITEM_PORTFOLIO = 'gestion_hee_portfolio';
    public const ITEM_FINAL = 'gestion_hee_final';
    public const ITEM_HOURS_ACCESS = 'gestion_hee_hours_access';
    public const REQUIRED_HOURS = 54.0;

    /**
     * Return courses that currently contain HEE workshops.
     *
     * @return array
     */
    public static function get_managed_courses(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))) {
            return [];
        }

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
                  FROM {course} c
                  JOIN {local_ga_workshops} w ON w.courseid = c.id
                 WHERE c.id > 1
              ORDER BY c.fullname ASC";
        return $DB->get_records_sql($sql);
    }

    /**
     * Ensure the per-course settings table exists defensively.
     */
    public static function ensure_settings_table(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::SETTINGS_TABLE))) {
            throw new \coding_exception('El esquema de configuración HEE no está instalado. Ejecuta la actualización de Moodle.');
        }
    }

    /**
     * Return the stored settings for a course.
     */
    public static function get_course_settings(int $courseid): \stdClass {
        global $DB;

        self::ensure_settings_table();
        $record = $DB->get_record(self::SETTINGS_TABLE, ['courseid' => $courseid], '*', IGNORE_MISSING);
        if ($record) {
            return $record;
        }

        return (object)[
            'id' => 0,
            'courseid' => $courseid,
            'selfassessmentcmid' => 0,
            'usermodified' => 0,
            'timecreated' => 0,
            'timemodified' => 0,
        ];
    }

    /**
     * Return valid quiz course modules for the selector.
     */
    public static function get_course_quizzes(int $courseid): array {
        global $DB;

        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return [];
        }

        $sql = "SELECT cm.id AS cmid, q.id AS quizid, q.name, cm.visible, gi.id AS gradeitemid,
                       gi.grademin, gi.grademax
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :quizmodule
                  JOIN {quiz} q ON q.id = cm.instance
             LEFT JOIN {grade_items} gi ON gi.courseid = cm.course
                                        AND gi.itemmodule = :quizitemmodule
                                        AND gi.iteminstance = q.id
                                        AND (gi.itemnumber = 0 OR gi.itemnumber IS NULL)
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
              ORDER BY q.name ASC, cm.id ASC";
        return $DB->get_records_sql($sql, [
            'quizmodule' => 'quiz',
            'quizitemmodule' => 'quiz',
            'courseid' => $courseid,
        ]);
    }

    /**
     * Save the selected self-assessment quiz for a course.
     */
    /**
     * Course-module ids of quizzes that belong to a taller (Edición activity).
     * They must never be used as the self-assessment quiz.
     *
     * @return int[] cmid => cmid
     */
    public static function workshop_quiz_cmids(int $courseid): array {
        global $DB;
        $out = [];
        $cols = $DB->get_columns('local_ga_workshop_editions');
        foreach (['requiredcmid', 'requiredquizcmid'] as $col) {
            if (!isset($cols[$col])) {
                continue;
            }
            $ids = $DB->get_fieldset_sql("SELECT DISTINCT e.$col FROM {local_ga_workshop_editions} e
                                            JOIN {local_ga_workshops} w ON w.id = e.workshopid
                                           WHERE w.courseid = :courseid AND e.$col > 0", ['courseid' => $courseid]);
            foreach ($ids as $id) {
                $out[(int)$id] = (int)$id;
            }
        }
        $ids = $DB->get_fieldset_select('course_modules', 'id', 'course = :courseid AND ' . $DB->sql_like('idnumber', ':p'),
            ['courseid' => $courseid, 'p' => 'HEE\_EDITION\_%']);
        foreach ($ids as $id) {
            $out[(int)$id] = (int)$id;
        }
        return $out;
    }

    public static function save_selfassessment_quiz(int $courseid, int $cmid, int $userid): void {
        global $DB;

        self::ensure_settings_table();
        if ($courseid <= 0) {
            throw new \moodle_exception('invalidcourseid');
        }
        if ($cmid > 0 && !self::is_valid_quiz_cmid($courseid, $cmid)) {
            throw new \invalid_parameter_exception('El cuestionario seleccionado no pertenece al curso o ya no está disponible.');
        }
        if ($cmid > 0 && isset(self::workshop_quiz_cmids($courseid)[$cmid])) {
            throw new \invalid_parameter_exception('Ese cuestionario pertenece a un taller y no puede ser la autoevaluación (quedaría oculto a sus alumnos hasta las 54 horas). Crea el cuestionario de autoevaluación HEE.');
        }

        $now = time();
        $existing = $DB->get_record(self::SETTINGS_TABLE, ['courseid' => $courseid], '*', IGNORE_MISSING);
        $oldcmid = $existing ? (int)($existing->selfassessmentcmid ?? 0) : 0;
        $record = (object)[
            'courseid' => $courseid,
            'selfassessmentcmid' => max(0, $cmid),
            'usermodified' => max(0, $userid),
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record(self::SETTINGS_TABLE, $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record(self::SETTINGS_TABLE, $record);
        }

        $items = self::ensure_grade_items($courseid);
        $hoursitemid = (int)$items['hoursaccess']->id;
        if ($oldcmid > 0 && $oldcmid !== $cmid) {
            self::update_quiz_hours_restriction($courseid, $oldcmid, $hoursitemid, false);
        }
        if ($cmid > 0) {
            self::update_quiz_hours_restriction($courseid, $cmid, $hoursitemid, true);
        }
    }

    /**
     * Whether a CM is a live quiz in the requested course.
     */
    public static function is_valid_quiz_cmid(int $courseid, int $cmid): bool {
        global $DB;

        if ($courseid <= 0 || $cmid <= 0) {
            return false;
        }
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {quiz} q ON q.id = cm.instance
                 WHERE cm.id = :cmid
                   AND cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND m.name = :modname";
        return (bool)$DB->get_field_sql($sql, [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'modname' => 'quiz',
        ]);
    }

    /**
     * Return selected self-assessment quiz and grade item information.
     */
    public static function get_selfassessment_info(int $courseid): ?\stdClass {
        global $DB;

        $settings = self::get_course_settings($courseid);
        $cmid = (int)($settings->selfassessmentcmid ?? 0);
        if ($cmid <= 0) {
            return null;
        }

        $sql = "SELECT cm.id AS cmid, cm.course, q.id AS quizid, q.name,
                       gi.id AS gradeitemid, gi.grademin, gi.grademax
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
             LEFT JOIN {grade_items} gi ON gi.courseid = cm.course
                                        AND gi.itemmodule = :itemmodule
                                        AND gi.iteminstance = q.id
                                        AND (gi.itemnumber = 0 OR gi.itemnumber IS NULL)
                 WHERE cm.id = :cmid
                   AND cm.course = :courseid
                   AND cm.deletioninprogress = 0";
        $record = $DB->get_record_sql($sql, [
            'modname' => 'quiz',
            'itemmodule' => 'quiz',
            'cmid' => $cmid,
            'courseid' => $courseid,
        ], IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Return students enrolled in a course, sorted by surname.
     */
    public static function get_course_students(int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return [];
        }

        // mod/assign:submit is a reliable student capability in standard Moodle roles.
        $users = get_enrolled_users(
            $context,
            'mod/assign:submit',
            0,
            'u.id,u.firstname,u.lastname,u.email,u.idnumber',
            'u.lastname ASC,u.firstname ASC'
        );

        // Defensive fallback for installations with heavily customised roles.
        if (!$users) {
            $users = get_enrolled_users(
                $context,
                '',
                0,
                'u.id,u.firstname,u.lastname,u.email,u.idnumber',
                'u.lastname ASC,u.firstname ASC'
            );
        }
        return $users ?: [];
    }

    /**
     * Calculate and optionally synchronise all grade rows for a course.
     */
    public static function get_course_grade_rows(int $courseid, bool $syncgradebook = false): array {
        $users = self::get_course_students($courseid);
        if (!$users) {
            if ($syncgradebook) {
                self::ensure_grade_items($courseid);
            }
            return [];
        }

        $userids = array_map('intval', array_keys($users));
        $typeamap = self::get_typea_average_map($courseid, $userids);
        $hoursmap = self::get_hours_map($userids);
        $missingcomments = self::get_missing_typeb_comments_map($userids);
        $autoevaluationmap = self::get_selfassessment_grade_map($courseid, $userids);

        $rows = [];
        foreach ($users as $user) {
            $userid = (int)$user->id;
            $typeagrade = $typeamap[$userid] ?? null;
            $hours = $hoursmap[$userid] ?? (object)['typeahours' => 0.0, 'typebhours' => 0.0, 'totalhours' => 0.0];
            $missing = (int)($missingcomments[$userid] ?? 0);
            $portfoliograde = ((float)$hours->totalhours >= self::REQUIRED_HOURS && $missing === 0) ? 10.0 : null;
            $autoevaluation = $autoevaluationmap[$userid] ?? null;
            $finalgrade = ($typeagrade !== null && $portfoliograde !== null && $autoevaluation !== null)
                ? round(($typeagrade * 0.60) + ($portfoliograde * 0.30) + ($autoevaluation * 0.10), 2)
                : null;

            $row = clone $user;
            $row->typeagrade = $typeagrade;
            $row->portfoliograde = $portfoliograde;
            $row->autoevaluationgrade = $autoevaluation;
            $row->finalgrade = $finalgrade;
            $row->typeahours = (float)$hours->typeahours;
            $row->typebhours = (float)$hours->typebhours;
            $row->totalhours = (float)$hours->totalhours;
            $row->missingtypebcomments = $missing;
            $rows[$userid] = $row;
        }

        if ($syncgradebook) {
            self::sync_rows_to_gradebook($courseid, $rows);
        }
        return $rows;
    }

    /**
     * Calculate one user's grade row and optionally store it in the gradebook.
     */
    public static function get_user_grade_summary(int $courseid, int $userid, bool $syncgradebook = false): \stdClass {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,email,idnumber', IGNORE_MISSING);
        if (!$user) {
            return (object)[
                'id' => $userid,
                'typeagrade' => null,
                'portfoliograde' => null,
                'autoevaluationgrade' => null,
                'finalgrade' => null,
                'typeahours' => 0.0,
                'typebhours' => 0.0,
                'totalhours' => 0.0,
                'missingtypebcomments' => 0,
            ];
        }

        $typeamap = self::get_typea_average_map($courseid, [$userid]);
        $hoursmap = self::get_hours_map([$userid]);
        $missingmap = self::get_missing_typeb_comments_map([$userid]);
        $automap = self::get_selfassessment_grade_map($courseid, [$userid]);
        $hours = $hoursmap[$userid] ?? (object)['typeahours' => 0.0, 'typebhours' => 0.0, 'totalhours' => 0.0];
        $typeagrade = $typeamap[$userid] ?? null;
        $missing = (int)($missingmap[$userid] ?? 0);
        $portfolio = ((float)$hours->totalhours >= self::REQUIRED_HOURS && $missing === 0) ? 10.0 : null;
        $auto = $automap[$userid] ?? null;
        $final = ($typeagrade !== null && $portfolio !== null && $auto !== null)
            ? round(($typeagrade * 0.60) + ($portfolio * 0.30) + ($auto * 0.10), 2)
            : null;

        $row = clone $user;
        $row->typeagrade = $typeagrade;
        $row->portfoliograde = $portfolio;
        $row->autoevaluationgrade = $auto;
        $row->finalgrade = $final;
        $row->typeahours = (float)$hours->typeahours;
        $row->typebhours = (float)$hours->typebhours;
        $row->totalhours = (float)$hours->totalhours;
        $row->missingtypebcomments = $missing;

        if ($syncgradebook) {
            self::sync_rows_to_gradebook($courseid, [$userid => $row]);
        }
        return $row;
    }

    /**
     * Synchronise a user in every HEE course in which they are enrolled.
     */
    public static function sync_user_in_managed_courses(int $userid): void {
        foreach (self::get_managed_courses() as $course) {
            $context = \context_course::instance((int)$course->id, IGNORE_MISSING);
            if (!$context || !is_enrolled($context, $userid, '', true)) {
                continue;
            }
            self::get_user_grade_summary((int)$course->id, $userid, true);
        }
    }

    /**
     * Synchronise one user in one known HEE course.
     */
    public static function sync_user_for_course_safely(int $courseid, int $userid): void {
        try {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context || !is_enrolled($context, $userid, '', true)) {
                return;
            }
            self::get_user_grade_summary($courseid, $userid, true);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se han podido sincronizar las calificaciones HEE del usuario ' . $userid
                    . ' en el curso ' . $courseid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Defensive wrapper used by write paths that must never fail because of grade synchronisation.
     */
    public static function sync_user_safely(int $userid): void {
        try {
            self::sync_user_in_managed_courses($userid);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se han podido sincronizar las calificaciones HEE del usuario ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Synchronise one complete HEE course with bulk queries.
     */
    public static function sync_course_safely(int $courseid): void {
        try {
            self::get_course_grade_rows($courseid, true);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se han podido sincronizar las calificaciones HEE del curso ' . $courseid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Batch synchronisation after institutional imports or configuration changes.
     */
    public static function sync_all_managed_courses_safely(): void {
        foreach (self::get_managed_courses() as $course) {
            try {
                self::get_course_grade_rows((int)$course->id, true);
            } catch (\Throwable $e) {
                if (function_exists('debugging')) {
                    debugging('No se han podido sincronizar las calificaciones HEE del curso ' . (int)$course->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * Create or refresh the HEE grade items.
     */
    public static function ensure_grade_items(int $courseid): array {
        global $CFG;
        require_once($CFG->libdir . '/grade/grade_item.php');

        return [
            'typea' => self::ensure_manual_grade_item(
                $courseid,
                self::ITEM_TYPEA,
                'Nota Talleres A',
                'Media de las notas de los talleres Tipo A superados (asistencia todos los días y 5 o más) y del reconocimiento institucional.'
            ),
            'portfolio' => self::ensure_manual_grade_item(
                $courseid,
                self::ITEM_PORTFOLIO,
                'Portafolio',
                'Calificación del portafolio HEE: 10 al completar 54 horas y todos los comentarios Tipo B aplicables.'
            ),
            'final' => self::ensure_manual_grade_item(
                $courseid,
                self::ITEM_FINAL,
                'Nota Final',
                'Nota Talleres A × 60% + Portafolio × 30% + Autoevaluación × 10%.'
            ),
            'hoursaccess' => self::ensure_manual_grade_item(
                $courseid,
                self::ITEM_HOURS_ACCESS,
                'Horas HEE (control de acceso)',
                'Ítem técnico oculto utilizado para mostrar la autoevaluación únicamente al alcanzar 54 horas.',
                self::REQUIRED_HOURS,
                true
            ),
        ];
    }

    /**
     * Ensure that the configured quiz is completely hidden until 54 HEE hours are reached.
     */
    public static function ensure_selfassessment_availability(int $courseid): bool {
        $settings = self::get_course_settings($courseid);
        $cmid = (int)($settings->selfassessmentcmid ?? 0);
        if ($cmid <= 0 || !self::is_valid_quiz_cmid($courseid, $cmid)) {
            return false;
        }
        $items = self::ensure_grade_items($courseid);
        $updated = self::update_quiz_hours_restriction($courseid, $cmid, (int)$items['hoursaccess']->id, true);
        self::move_selfassessment_section_to_end($courseid, $cmid);
        return $updated;
    }

    /**
     * Keep the auto-assessment section as the last course section.
     */
    private static function move_selfassessment_section_to_end(int $courseid, int $cmid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], 'id,section', IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $section = $DB->get_record('course_sections', ['id' => (int)$cm->section, 'course' => $courseid], 'id,section', IGNORE_MISSING);
        if (!$section) {
            return;
        }
        $maxsection = course_layout::last_regular_section_number($courseid);
        if ((int)$section->section >= $maxsection || !function_exists('move_section_to')) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        move_section_to($course, (int)$section->section, $maxsection);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Add or remove this plugin's hours condition without discarding other restrictions.
     */
    private static function update_quiz_hours_restriction(
        int $courseid,
        int $cmid,
        int $gradeitemid,
        bool $add
    ): bool {
        global $DB, $CFG;

        if ($courseid <= 0 || $cmid <= 0 || $gradeitemid <= 0) {
            return false;
        }
        $cm = $DB->get_record(
            'course_modules',
            ['id' => $cmid],
            'id,course,section,visible,availability,deletioninprogress',
            IGNORE_MISSING
        );
        if (!$cm || (int)$cm->course !== $courseid || !empty($cm->deletioninprogress)) {
            return false;
        }

        $condition = \availability_grade\condition::get_json($gradeitemid, 100.0, null);
        $cmavailability = self::build_hours_availability((string)($cm->availability ?? ''), $gradeitemid, $condition, $add);
        if ($cmavailability === false) {
            return false;
        }

        $changed = false;
        if ($cmavailability !== self::normalise_availability((string)($cm->availability ?? ''))) {
            $DB->set_field('course_modules', 'availability', $cmavailability, ['id' => $cmid]);
            $changed = true;
        }

        // Apply the same condition to the containing section so neither the title nor an empty section is shown.
        // Existing section restrictions are preserved and combined using AND.
        $section = $DB->get_record(
            'course_sections',
            ['id' => (int)$cm->section, 'course' => $courseid],
            'id,course,availability',
            IGNORE_MISSING
        );
        if ($section) {
            $sectionavailability = self::build_hours_availability(
                (string)($section->availability ?? ''),
                $gradeitemid,
                $condition,
                $add
            );
            if ($sectionavailability === false) {
                return false;
            }
            if ($sectionavailability !== self::normalise_availability((string)($section->availability ?? ''))) {
                require_once($CFG->dirroot . '/course/lib.php');
                course_update_section($courseid, $section, ['availability' => $sectionavailability]);
                $changed = true;
            }
        }

        $visibilitychanged = false;
        if ($add && empty($cm->visible)) {
            require_once($CFG->dirroot . '/course/lib.php');
            set_coursemodule_visible($cmid, 1);
            $visibilitychanged = true;
        }
        if ($changed && !$visibilitychanged) {
            rebuild_course_cache($courseid, true);
        }
        return $changed || $visibilitychanged;
    }

    /**
     * Return a normalised availability JSON value suitable for DB comparison.
     */
    private static function normalise_availability(string $availability): ?string {
        $availability = trim($availability);
        return $availability === '' ? null : $availability;
    }

    /**
     * Build an availability tree after replacing this plugin's hours condition.
     * Returns false when existing JSON is malformed so no restriction is accidentally destroyed.
     *
     * @return string|null|false
     */
    private static function build_hours_availability(
        string $oldavailability,
        int $gradeitemid,
        \stdClass $condition,
        bool $add
    ) {
        $tree = null;
        $oldavailability = trim($oldavailability);
        if ($oldavailability !== '') {
            $tree = json_decode($oldavailability);
            if (!is_object($tree)) {
                if (function_exists('debugging')) {
                    debugging('No se ha modificado la disponibilidad HEE porque su JSON no es válido.', DEBUG_DEVELOPER);
                }
                return false;
            }
            $tree = self::remove_hours_grade_condition($tree, $gradeitemid, true);
        }
        if ($add) {
            $tree = self::append_hidden_condition($tree, $condition);
        }
        return $tree ? json_encode($tree) : null;
    }

    /**
     * Remove matching grade conditions recursively, keeping root display flags aligned.
     */
    private static function remove_hours_grade_condition(?\stdClass $node, int $gradeitemid, bool $root): ?\stdClass {
        if (!$node) {
            return null;
        }
        if (isset($node->type)) {
            if ((string)$node->type === 'grade' && (int)($node->id ?? 0) === $gradeitemid) {
                return null;
            }
            return $node;
        }
        if (!isset($node->c) || !is_array($node->c)) {
            return $node;
        }

        $oldshow = isset($node->showc) && is_array($node->showc) ? $node->showc : [];
        $children = [];
        $showchildren = [];
        foreach ($node->c as $index => $child) {
            if (!is_object($child)) {
                continue;
            }
            $clean = self::remove_hours_grade_condition($child, $gradeitemid, false);
            if (!$clean) {
                continue;
            }
            $children[] = $clean;
            $showchildren[] = array_key_exists($index, $oldshow) ? (bool)$oldshow[$index] : true;
        }
        if (!$children) {
            return null;
        }
        $node->c = $children;

        if ($root) {
            $op = (string)($node->op ?? '&');
            if ($op === '&' || $op === '!|') {
                $node->showc = $showchildren;
                unset($node->show);
            } else {
                $node->show = isset($node->show) ? (bool)$node->show : true;
                unset($node->showc);
            }
        } else {
            unset($node->show, $node->showc);
        }
        return $node;
    }

    /**
     * Combine a new hidden condition with an existing availability tree using AND.
     */
    private static function append_hidden_condition(?\stdClass $tree, \stdClass $condition): \stdClass {
        if (!$tree) {
            return \core_availability\tree::get_root_json(
                [$condition],
                \core_availability\tree::OP_AND,
                false
            );
        }

        if ((string)($tree->op ?? '') === '&' && isset($tree->c) && is_array($tree->c)) {
            $showchildren = isset($tree->showc) && is_array($tree->showc)
                ? array_map('boolval', $tree->showc)
                : array_fill(0, count($tree->c), true);
            while (count($showchildren) < count($tree->c)) {
                $showchildren[] = true;
            }
            $tree->c[] = $condition;
            $showchildren[] = false;
            $tree->showc = $showchildren;
            unset($tree->show);
            return $tree;
        }

        $showexisting = true;
        if (isset($tree->show)) {
            $showexisting = (bool)$tree->show;
        } else if (isset($tree->showc) && is_array($tree->showc) && in_array(false, $tree->showc, true)) {
            $showexisting = false;
        }
        $nested = json_decode(json_encode($tree));
        unset($nested->show, $nested->showc);
        return \core_availability\tree::get_root_json(
            [$nested, $condition],
            \core_availability\tree::OP_AND,
            [$showexisting, false]
        );
    }

    /**
     * Reapply and verify the self-assessment restriction for every configured course.
     *
     * @param int $courseid Optional course filter.
     * @return array<int, bool> Course id => success.
     */
    public static function repair_configured_selfassessment_availability(int $courseid = 0): array {
        global $DB;

        self::ensure_settings_table();
        $params = [];
        $where = 'selfassessmentcmid > 0';
        if ($courseid > 0) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $settings = $DB->get_records_select(self::SETTINGS_TABLE, $where, $params, 'courseid ASC');
        $results = [];
        foreach ($settings as $setting) {
            $cid = (int)$setting->courseid;
            try {
                self::sync_course_safely($cid);
                self::ensure_selfassessment_availability($cid);
                $results[$cid] = self::selfassessment_section_has_hours_condition($cid);
            } catch (\Throwable $e) {
                $results[$cid] = false;
            }
        }
        return $results;
    }

    /**
     * Verify that the containing section stores the technical 54-hour condition as hidden.
     */
    private static function selfassessment_section_has_hours_condition(int $courseid): bool {
        global $DB;

        $settings = self::get_course_settings($courseid);
        $cmid = (int)($settings->selfassessmentcmid ?? 0);
        if ($cmid <= 0) {
            return false;
        }
        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], 'id,section', IGNORE_MISSING);
        if (!$cm) {
            return false;
        }
        $items = self::ensure_grade_items($courseid);
        $gradeitemid = (int)$items['hoursaccess']->id;
        $availability = (string)$DB->get_field('course_sections', 'availability', ['id' => (int)$cm->section]);
        $tree = json_decode($availability);
        return self::availability_tree_contains_hidden_grade($tree, $gradeitemid, true);
    }

    /**
     * Recursively find the grade condition and ensure its root display flag hides the section.
     */
    private static function availability_tree_contains_hidden_grade($node, int $gradeitemid, bool $root): bool {
        if (!is_object($node)) {
            return false;
        }
        if (isset($node->type)) {
            return (string)$node->type === 'grade' && (int)($node->id ?? 0) === $gradeitemid;
        }
        if (!isset($node->c) || !is_array($node->c)) {
            return false;
        }
        foreach ($node->c as $index => $child) {
            if (!self::availability_tree_contains_hidden_grade($child, $gradeitemid, false)) {
                continue;
            }
            if (!$root) {
                return true;
            }
            $op = (string)($node->op ?? '&');
            if ($op === '&' || $op === '!|') {
                return isset($node->showc[$index]) && $node->showc[$index] === false;
            }
            return isset($node->show) && $node->show === false;
        }
        return false;
    }

    /**
     * Find the Moodle grade item of the configured self-assessment quiz.
     */
    public static function get_selfassessment_grade_item_id(int $courseid): int {
        $info = self::get_selfassessment_info($courseid);
        return $info ? (int)($info->gradeitemid ?? 0) : 0;
    }

    /**
     * Whether a gradebook item can alter an HEE calculated grade.
     */
    public static function is_relevant_source_grade_item(int $courseid, int $itemid): bool {
        global $DB;

        if ($courseid <= 0 || $itemid <= 0) {
            return false;
        }
        if ($itemid === self::get_selfassessment_grade_item_id($courseid)) {
            return true;
        }
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table('local_ga_workshop_editions'))
            || !$dbman->table_exists(new \xmldb_table('local_ga_workshops'))) {
            return false;
        }

        $item = $DB->get_record('grade_items', ['id' => $itemid, 'courseid' => $courseid], 'id,itemmodule,iteminstance', IGNORE_MISSING);
        if (!$item || empty($item->itemmodule) || empty($item->iteminstance)) {
            return false;
        }
        $sql = "SELECT e.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {local_ga_workshop_editions} e ON e.requiredcmid = cm.id
                  JOIN {local_ga_workshops} w ON w.id = e.workshopid
                 WHERE cm.course = :courseid
                   AND m.name = :itemmodule
                   AND cm.instance = :iteminstance
                   AND (w.workshoptype = 'typea' OR w.workshoptype IS NULL OR w.workshoptype = '')";
        return (bool)$DB->get_field_sql($sql, [
            'courseid' => $courseid,
            'itemmodule' => $item->itemmodule,
            'iteminstance' => (int)$item->iteminstance,
        ]);
    }

    /**
     * Store computed rows in the Moodle gradebook, skipping unchanged values.
     */
    private static function sync_rows_to_gradebook(int $courseid, array $rows): void {
        global $DB;

        if ($courseid <= 0) {
            return;
        }
        if (!$rows) {
            self::ensure_grade_items($courseid);
            return;
        }
        $items = self::ensure_grade_items($courseid);
        $itemids = [
            (int)$items['typea']->id,
            (int)$items['portfolio']->id,
            (int)$items['final']->id,
            (int)$items['hoursaccess']->id,
        ];
        $userids = array_map('intval', array_keys($rows));

        list($itemsql, $itemparams) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'gi');
        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
        $existing = $DB->get_records_sql(
            "SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade
               FROM {grade_grades} gg
              WHERE gg.itemid $itemsql AND gg.userid $usersql",
            array_merge($itemparams, $userparams)
        );
        $current = [];
        foreach ($existing as $grade) {
            $current[(int)$grade->itemid][(int)$grade->userid] = $grade->finalgrade === null ? null : (float)$grade->finalgrade;
        }

        $admin = get_admin();
        $usermodified = $admin ? (int)$admin->id : null;
        $changed = false;
        foreach ($rows as $userid => $row) {
            $userid = (int)$userid;
            $values = [
                'typea' => $row->typeagrade,
                'portfolio' => $row->portfoliograde,
                'final' => $row->finalgrade,
                'hoursaccess' => min(self::REQUIRED_HOURS, max(0.0, (float)$row->totalhours)),
            ];
            $userchanged = false;
            foreach ($values as $key => $value) {
                $item = $items[$key];
                $old = $current[(int)$item->id][$userid] ?? null;
                $new = $value === null ? null : round((float)$value, 2);
                if (self::same_grade($old, $new)) {
                    continue;
                }
                $item->update_final_grade(
                    $userid,
                    $new,
                    'local_gestion_actividades',
                    false,
                    FORMAT_MOODLE,
                    $usermodified,
                    null,
                    true
                );
                $changed = true;
                $userchanged = true;
            }
            if ($userchanged) {
                try {
                    \cache::make('availability_grade', 'scores')->delete($userid);
                } catch (\Throwable $e) {
                    // Availability cache will also expire normally; grade synchronisation must not fail here.
                }
            }
        }

        if ($changed) {
            grade_regrade_final_grades($courseid);
        }
    }

    /**
     * Create or update one manual grade item.
     */
    private static function ensure_manual_grade_item(
        int $courseid,
        string $idnumber,
        string $name,
        string $info,
        float $grademax = 10.0,
        bool $hidden = false
    ): \grade_item {
        $item = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'manual',
            'idnumber' => $idnumber,
        ]);
        if (!$item) {
            $item = new \grade_item([
                'courseid' => $courseid,
                'itemtype' => 'manual',
                'itemname' => $name,
                'idnumber' => $idnumber,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademin' => 0,
                'grademax' => $grademax,
                'decimals' => 2,
                'hidden' => $hidden ? 1 : 0,
                'iteminfo' => $info,
            ], false);
            $item->insert('local_gestion_actividades');
            return $item;
        }

        $changed = false;
        foreach ([
            'itemname' => $name,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademin' => 0,
            'grademax' => $grademax,
            'decimals' => 2,
            'hidden' => $hidden ? 1 : 0,
            'iteminfo' => $info,
        ] as $field => $value) {
            if ($item->$field != $value) {
                $item->$field = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $item->update('local_gestion_actividades');
        }
        return $item;
    }

    /**
     * Compute average Type A grades by user.
     */
    private static function get_typea_average_map(int $courseid, array $userids): array {
        global $DB;

        $grades = [];
        if (!$userids) {
            return [];
        }
        list($usersql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ta');
        $params['courseid'] = $courseid;
        $transferexclusion = '';
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_transfers'))) {
            $transferexclusion = " AND NOT EXISTS (SELECT 1
                                                      FROM {local_ga_typeb_transfers} tr
                                                     WHERE tr.userid = ts.userid
                                                       AND tr.editionid = ts.editionid
                                                       AND tr.status = :typeatransferstatus)";
            $params['typeatransferstatus'] = 'active';
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_task_submissions'))) {
            $sql = "SELECT ts.id, ts.userid, ts.editionid, ts.grade
                      FROM {local_ga_task_submissions} ts
                      JOIN {local_ga_workshop_editions} e ON e.id = ts.editionid
                      JOIN {local_ga_workshops} w ON w.id = e.workshopid
                     WHERE ts.userid $usersql
                       AND w.courseid = :courseid
                       AND (w.workshoptype = 'typea' OR w.workshoptype IS NULL OR w.workshoptype = '')
                       AND ts.grade IS NOT NULL
                       $transferexclusion";
            foreach ($DB->get_records_sql($sql, $params) as $record) {
                $grades[(int)$record->userid]['edition:' . (int)$record->editionid] = self::clamp_grade((float)$record->grade);
            }
        }

        // Compatibility with older Type A editions linked to a native assignment or quiz.
        $nativeexclusion = '';
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_transfers'))) {
            $nativeexclusion = " AND NOT EXISTS (SELECT 1
                                                    FROM {local_ga_typeb_transfers} tr
                                                   WHERE tr.userid = gg.userid
                                                     AND tr.editionid = e.id
                                                     AND tr.status = :typeatransferstatus)";
        }
        $sql = "SELECT gg.id, gg.userid, e.id AS editionid, gg.finalgrade, gi.grademin, gi.grademax
                  FROM {local_ga_workshop_editions} e
                  JOIN {local_ga_workshops} w ON w.id = e.workshopid
                  JOIN {course_modules} cm ON cm.id = e.requiredcmid
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {grade_items} gi ON gi.courseid = cm.course
                                       AND gi.itemmodule = m.name
                                       AND gi.iteminstance = cm.instance
                                       AND (gi.itemnumber = 0 OR gi.itemnumber IS NULL)
                  JOIN {grade_grades} gg ON gg.itemid = gi.id
                 WHERE gg.userid $usersql
                   AND w.courseid = :courseid
                   AND (w.workshoptype = 'typea' OR w.workshoptype IS NULL OR w.workshoptype = '')
                   AND gg.finalgrade IS NOT NULL
                   $nativeexclusion";
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $key = 'edition:' . (int)$record->editionid;
            if (isset($grades[(int)$record->userid][$key])) {
                continue;
            }
            $grades[(int)$record->userid][$key] = self::normalise_to_ten(
                (float)$record->finalgrade,
                (float)$record->grademin,
                (float)$record->grademax
            );
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_institutional_hours'))) {
            $sql = "SELECT id, userid, taskgrade
                      FROM {local_ga_institutional_hours}
                     WHERE userid $usersql
                       AND typeahours > 0
                       AND taskgrade IS NOT NULL";
            $institutionalparams = $params;
            unset($institutionalparams['courseid'], $institutionalparams['typeatransferstatus']);
            foreach ($DB->get_records_sql($sql, $institutionalparams) as $record) {
                $grades[(int)$record->userid]['institutional:' . (int)$record->id] = self::clamp_grade((float)$record->taskgrade);
            }
        }

        // Only talleres the student PASSED count towards «Nota Talleres A»:
        // «Presente» on every day of the taller (or a certificate already
        // issued) AND at least 5/10. Attending and failing, or passing the
        // quiz without attending, does not give the taller, so its grade is
        // left out. Institutional grades are kept as imported.
        $attended = [];
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            $columns = $DB->get_columns('local_ga_edition_enrolments');
            $attendancecondition = isset($columns['attended'])
                ? "(ee.attended = 1 OR ee.status = 'attended')"
                : "ee.status = 'attended'";
            [$asql, $aparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ap');
            $rs = $DB->get_recordset_sql("SELECT ee.id, ee.userid, ee.editionid FROM {local_ga_edition_enrolments} ee
                                           WHERE ee.userid $asql AND $attendancecondition AND " . manager::active_enrolment_sql('ee'), $aparams);
            foreach ($rs as $r) {
                $attended[(int)$r->userid][(int)$r->editionid] = true;
            }
            $rs->close();
        }
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_certificates'))) {
            [$csql, $cparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cp');
            $rs = $DB->get_recordset_select('local_ga_certificates', "userid $csql", $cparams, '', 'id, userid, editionid');
            foreach ($rs as $r) {
                $attended[(int)$r->userid][(int)$r->editionid] = true;
            }
            $rs->close();
        }
        foreach ($grades as $userid => $usergrades) {
            foreach ($usergrades as $key => $grade) {
                if (strpos($key, 'edition:') !== 0) {
                    continue;
                }
                $editionid = (int)substr($key, strlen('edition:'));
                if ((float)$grade < 5.0 || empty($attended[(int)$userid][$editionid])) {
                    unset($grades[$userid][$key]);
                }
            }
        }

        $out = [];
        foreach ($grades as $userid => $usergrades) {
            if (!$usergrades) {
                continue;
            }
            $out[(int)$userid] = round(array_sum($usergrades) / count($usergrades), 2);
        }
        return $out;
    }

    /**
     * Compute Type A, Type B and total hours for selected users.
     */
    private static function get_hours_map(array $userids): array {
        // Same figures as the portfolio and block_gestion_hee.
        $out = [];
        foreach (hours_calculator::for_users($userids) as $userid => $hours) {
            $out[(int)$userid] = (object)[
                'typeahours' => $hours->typeahours,
                'typebhours' => $hours->typebhours,
                'totalhours' => $hours->totalhours,
            ];
        }
        foreach ($userids as $userid) {
            if (!isset($out[(int)$userid])) {
                $out[(int)$userid] = (object)['typeahours' => 0.0, 'typebhours' => 0.0, 'totalhours' => 0.0];
            }
        }
        return $out;
    }

    /**
     * Count Type B comments that are still required for selected users.
     */
    private static function get_missing_typeb_comments_map(array $userids): array {
        global $DB;

        $out = array_fill_keys(array_map('intval', $userids), 0);
        if (!$userids) {
            return $out;
        }
        list($usersql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'rf');

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            $columns = $DB->get_columns('local_ga_edition_enrolments');
            $attendancecondition = isset($columns['attended'])
                ? "(ee.attended = 1 OR ee.status = 'attended')"
                : "ee.status = 'attended'";
            $sql = "SELECT ee.id, ee.userid, ee.editionid, e.requiredcmid
                      FROM {local_ga_edition_enrolments} ee
                      JOIN {local_ga_workshop_editions} e ON e.id = ee.editionid
                      JOIN {local_ga_workshops} w ON w.id = e.workshopid
                     WHERE ee.userid $usersql
                       AND w.workshoptype = 'typeb'
                       AND $attendancecondition
                       AND " . manager::active_enrolment_sql('ee');
            foreach ($DB->get_records_sql($sql, $params) as $record) {
                $submitted = false;
                if (!empty($record->requiredcmid)) {
                    try {
                        $summary = typeb_reflection_activity::submission_summary((int)$record->editionid, (int)$record->userid);
                        $submitted = !empty($summary->submitted);
                    } catch (\Throwable $e) {
                        $submitted = false;
                    }
                } else {
                    $legacy = manager::get_typeb_reflection((int)$record->editionid, (int)$record->userid);
                    $submitted = $legacy && trim((string)($legacy->reflectiontext ?? '')) !== '';
                }
                if (!$submitted) {
                    $out[(int)$record->userid]++;
                }
            }
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_institutional_hours'))) {
            $columns = $DB->get_columns('local_ga_institutional_hours');
            if (isset($columns['typebreflection'])) {
                $sql = "SELECT userid, COUNT(id) AS missingcount
                          FROM {local_ga_institutional_hours}
                         WHERE userid $usersql
                           AND typebhours > 0
                           AND (typebreflection IS NULL OR typebreflection = '')
                      GROUP BY userid";
                foreach ($DB->get_records_sql($sql, $params) as $record) {
                    $out[(int)$record->userid] += (int)$record->missingcount;
                }
            }
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_transfers'))) {
            $transferparams = $params;
            $transferparams['activestatus'] = 'active';
            $sql = "SELECT userid, COUNT(id) AS missingcount
                      FROM {local_ga_typeb_transfers}
                     WHERE userid $usersql
                       AND status = :activestatus
                       AND (reflectiontext IS NULL OR reflectiontext = '')
                  GROUP BY userid";
            foreach ($DB->get_records_sql($sql, $transferparams) as $record) {
                $out[(int)$record->userid] += (int)$record->missingcount;
            }
        }
        return $out;
    }

    /**
     * Read and normalise the selected quiz grades to a 0-10 scale.
     */
    private static function get_selfassessment_grade_map(int $courseid, array $userids): array {
        global $DB;

        $info = self::get_selfassessment_info($courseid);
        if (!$info || empty($info->gradeitemid) || !$userids) {
            return [];
        }
        list($usersql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'sa');
        $params['itemid'] = (int)$info->gradeitemid;
        $sql = "SELECT id, userid, finalgrade
                  FROM {grade_grades}
                 WHERE itemid = :itemid
                   AND userid $usersql
                   AND finalgrade IS NOT NULL";
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $out[(int)$record->userid] = self::normalise_to_ten(
                (float)$record->finalgrade,
                (float)($info->grademin ?? 0),
                (float)($info->grademax ?? 10)
            );
        }
        return $out;
    }

    private static function normalise_to_ten(float $grade, float $min, float $max): float {
        if ($max <= $min) {
            return self::clamp_grade($grade);
        }
        return self::clamp_grade((($grade - $min) / ($max - $min)) * 10.0);
    }

    private static function clamp_grade(float $grade): float {
        return round(max(0.0, min(10.0, $grade)), 2);
    }

    private static function same_grade($old, $new): bool {
        if ($old === null && $new === null) {
            return true;
        }
        if ($old === null || $new === null) {
            return false;
        }
        return abs((float)$old - (float)$new) < 0.00001;
    }
}
