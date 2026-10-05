<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Creation of the final HEE self-assessment quiz.
 *
 * The plugin only creates the empty Moodle quiz container and links the
 * 54-hour availability rule. Questions remain fully editable with Moodle's
 * native quiz/question-bank interface.
 */
class selfassessment_quiz {
    private const CM_IDNUMBER = 'gestion_hee_selfassessment';
    private const SECTION_NAME = 'Autoevaluación final HEE';
    private const QUIZ_NAME = 'Autoevaluación final HEE';

    /**
     * Create (or recover) the course self-assessment quiz and link it to HEE.
     *
     * @return int Course-module id.
     */
    public static function create_and_link(int $courseid, int $userid): int {
        global $CFG, $DB;

        if ($courseid <= 0) {
            throw new \moodle_exception('invalidcourseid');
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = \context_course::instance($courseid);

        // Creating a native Moodle activity remains a native course capability;
        // HEE authorisation by itself must never grant general editing rights.
        require_capability('moodle/course:manageactivities', $context);

        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        if (property_exists($module, 'visible') && empty($module->visible)) {
            throw new \moodle_exception('moduledisable', 'error', '', 'quiz');
        }

        // Idempotency: if HEE already has a valid selected quiz, keep it.
        $settings = grade_manager::get_course_settings($courseid);
        $selectedcmid = (int)($settings->selfassessmentcmid ?? 0);
        if ($selectedcmid > 0 && grade_manager::is_valid_quiz_cmid($courseid, $selectedcmid)) {
            grade_manager::ensure_selfassessment_availability($courseid);
            return $selectedcmid;
        }

        // Recover a quiz created by a previous interrupted request before
        // creating another one.
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                 WHERE cm.course = :courseid
                   AND cm.idnumber = :idnumber
                   AND cm.deletioninprogress = 0
              ORDER BY cm.id DESC";
        $existing = $DB->get_field_sql($sql, [
            'modname' => 'quiz',
            'courseid' => $courseid,
            'idnumber' => self::CM_IDNUMBER,
        ], IGNORE_MULTIPLE);
        if ($existing) {
            $cmid = (int)$existing;
            grade_manager::save_selfassessment_quiz($courseid, $cmid, $userid);
            grade_manager::ensure_selfassessment_availability($courseid);
            return $cmid;
        }

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        // Use a dedicated final section because the 54-hour restriction is
        // deliberately applied to both the module and its containing section.
        $lastsection = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(section), 0) FROM {course_sections} WHERE course = :courseid',
            ['courseid' => $courseid]
        );
        $sectionnum = $lastsection + 1;
        course_create_sections_if_missing($course, $sectionnum);
        $section = $DB->get_record(
            'course_sections',
            ['course' => $courseid, 'section' => $sectionnum],
            '*',
            MUST_EXIST
        );
        course_update_section($courseid, $section, [
            'name' => self::SECTION_NAME,
            'summary' => '<p>Cuestionario final de autoevaluación HEE. Se muestra automáticamente al alcanzar 54 horas reconocidas.</p>',
            'summaryformat' => FORMAT_HTML,
            'visible' => 1,
        ]);

        $quizconfig = get_config('quiz');
        $moduleinfo = (object)[
            'modulename' => 'quiz',
            'module' => (int)$module->id,
            'section' => $sectionnum,
            'name' => self::QUIZ_NAME,
            'intro' => 'Autoevaluación final HEE. Edita este cuestionario desde Moodle para añadir las preguntas.',
            'introformat' => FORMAT_HTML,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => self::CM_IDNUMBER,
            'groupmode' => 0,
            'groupingid' => 0,
            'availability' => null,
            'showdescription' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionpassgrade' => 0,
            'completiongradeitemnumber' => '',
            'completionattemptsexhausted' => 0,
            'completionminattempts' => 0,
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => 0,
            'overduehandling' => self::config_value($quizconfig, 'overduehandling', 'autosubmit'),
            'graceperiod' => (int)self::config_value($quizconfig, 'graceperiod', 0),
            'grade' => (float)self::config_value($quizconfig, 'maximumgrade', 10),
            'attempts' => (int)self::config_value($quizconfig, 'attempts', 1),
            'grademethod' => (int)self::config_value($quizconfig, 'grademethod', QUIZ_GRADEHIGHEST),
            'questionsperpage' => (int)self::config_value($quizconfig, 'questionsperpage', 1),
            'navmethod' => (string)self::config_value($quizconfig, 'navmethod', QUIZ_NAVMETHOD_FREE),
            'shuffleanswers' => (int)self::config_value($quizconfig, 'shuffleanswers', 1),
            'preferredbehaviour' => (string)self::config_value($quizconfig, 'preferredbehaviour', 'deferredfeedback'),
            'canredoquestions' => (int)self::config_value($quizconfig, 'canredoquestions', 0),
            'attemptonlast' => (int)self::config_value($quizconfig, 'attemptonlast', 0),
            'decimalpoints' => (int)self::config_value($quizconfig, 'decimalpoints', 2),
            'questiondecimalpoints' => (int)self::config_value($quizconfig, 'questiondecimalpoints', -1),
            'showuserpicture' => (int)self::config_value($quizconfig, 'showuserpicture', 0),
            'showblocks' => (int)self::config_value($quizconfig, 'showblocks', 0),
            'quizpassword' => '',
            'subnet' => '',
            'delay1' => 0,
            'delay2' => 0,
            'browsersecurity' => (string)self::config_value($quizconfig, 'browsersecurity', '-'),
        ];
        self::apply_review_defaults($moduleinfo, $quizconfig);

        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int)($created->coursemodule ?? 0);
        if ($cmid <= 0 || !grade_manager::is_valid_quiz_cmid($courseid, $cmid)) {
            throw new \moodle_exception('cannotaddnewmodule', 'error', '', 'quiz');
        }

        grade_manager::save_selfassessment_quiz($courseid, $cmid, $userid);
        grade_manager::ensure_selfassessment_availability($courseid);
        rebuild_course_cache($courseid, true);
        return $cmid;
    }

    /**
     * Use the site's quiz review defaults so the generated quiz behaves like a
     * normal Moodle quiz before the manager customises it.
     */
    private static function apply_review_defaults(\stdClass $moduleinfo, \stdClass $quizconfig): void {
        $fields = [
            'attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback',
            'generalfeedback', 'rightanswer', 'overallfeedback',
        ];
        $times = [
            'during' => \mod_quiz\question\display_options::DURING,
            'immediately' => \mod_quiz\question\display_options::IMMEDIATELY_AFTER,
            'open' => \mod_quiz\question\display_options::LATER_WHILE_OPEN,
            'closed' => \mod_quiz\question\display_options::AFTER_CLOSE,
        ];
        foreach ($fields as $field) {
            $mask = (int)self::config_value($quizconfig, 'review' . $field, 0);
            foreach ($times as $suffix => $bit) {
                $property = $field . $suffix;
                $moduleinfo->$property = ($mask & $bit) ? 1 : 0;
            }
        }
    }

    private static function config_value(\stdClass $config, string $property, $fallback) {
        return property_exists($config, $property) && $config->$property !== null && $config->$property !== ''
            ? $config->$property
            : $fallback;
    }
}
