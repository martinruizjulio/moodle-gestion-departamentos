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
 * Class selfassessment_quiz for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
    public static function create_and_link(int $courseid, int $userid, bool $replace = false): int {
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
            throw new \coding_exception('El módulo Cuestionario está deshabilitado en esta instalación Moodle.');
        }

        // Idempotency: if HEE already has a valid selected quiz, keep it.
        $settings = grade_manager::get_course_settings($courseid);
        $selectedcmid = (int)($settings->selfassessmentcmid ?? 0);
        // $replace: the selected quiz is not a proper self-assessment (e.g. a
        // taller quiz chosen by mistake); create/recover the HEE one instead.
        if (!$replace && $selectedcmid > 0 && grade_manager::is_valid_quiz_cmid($courseid, $selectedcmid)) {
            grade_manager::ensure_selfassessment_availability($courseid);
            course_layout::synchronise_course($courseid);
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
            course_layout::synchronise_course($courseid);
            return $cmid;
        }

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        // Use a dedicated final section because the 54-hour restriction is
        // deliberately applied to both the module and its containing section.
        // Append a regular section; core places it before any delegated
        // (mod_subsection) sections and returns its final number.
        $created = course_create_section($course, 0);
        $section = $DB->get_record('course_sections', ['id' => (int)$created->id], '*', MUST_EXIST);
        $sectionnum = (int)$section->section;
        course_update_section($courseid, $section, [
            'name' => self::SECTION_NAME,
            'summary' => '<p>Cuestionario final de autoevaluación HEE. Se muestra automáticamente al alcanzar 54 horas reconocidas.</p>',
            'summaryformat' => FORMAT_HTML,
            'visible' => 1,
        ]);

        $quizconfig = (object)get_config('quiz');
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
            'sumgrades' => 0.0,
            'attempts' => (int)self::config_value($quizconfig, 'attempts', 1),
            'grademethod' => (int)self::config_value($quizconfig, 'grademethod', 1),
            'questionsperpage' => (int)self::config_value($quizconfig, 'questionsperpage', 1),
            'navmethod' => (string)self::config_value($quizconfig, 'navmethod', 'free'),
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
            'allowofflineattempts' => 0,
            'precreateattempts' => 0,
        ];
        self::apply_review_defaults($moduleinfo, $quizconfig);

        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int)($created->coursemodule ?? 0);
        if ($cmid <= 0 || !grade_manager::is_valid_quiz_cmid($courseid, $cmid)) {
            throw new \moodle_exception('cannotaddnewmodule', 'error', '', 'quiz');
        }

        grade_manager::save_selfassessment_quiz($courseid, $cmid, $userid);
        grade_manager::ensure_selfassessment_availability($courseid);
        course_layout::synchronise_course($courseid);
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
