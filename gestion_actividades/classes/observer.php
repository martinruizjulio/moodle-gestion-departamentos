<?php
namespace local_gestion_actividades;

defined('MOODLE_INTERNAL') || die();

use local_gestion_actividades\local\grade_manager;
use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\typeb_reflection_activity;

/**
 * Event observers for lightweight HEE grade synchronisation.
 */
class observer {
    /**
     * Attendance taken in a workshop attendance list (teacher or student):
     * apply it to the HEE enrolments of the editions using that list.
     */
    public static function attendance_taken(\core\event\base $event): void {
        try {
            \local_gestion_actividades\local\attendance_sync::sync_attendance_instance((int)$event->objectid);
        } catch (\Throwable $e) {
            debugging('No se pudo sincronizar la asistencia HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /** @var bool Prevent recursive processing of grade events created by our own manual items. */
    private static $processing = false;

    /**
     * Refresh HEE grades when the selected self-assessment or a linked Type A activity is graded.
     */
    public static function user_graded(\core\event\user_graded $event): void {
        if (self::$processing) {
            return;
        }
        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        $itemid = (int)($event->other['itemid'] ?? 0);
        if ($courseid <= 0 || $userid <= 0 || $itemid <= 0) {
            return;
        }
        if (!grade_manager::is_relevant_source_grade_item($courseid, $itemid)) {
            return;
        }

        self::$processing = true;
        try {
            grade_manager::get_user_grade_summary($courseid, $userid, true);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se han podido sincronizar las notas HEE tras actualizar una calificación: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        } finally {
            self::$processing = false;
        }
    }

    /**
     * Resolve the student that owns an assignment submission event.
     *
     * mod_assign only fills relateduserid when someone acts on behalf of another
     * user; when a student submits their own reflection relateduserid is empty.
     * The assign_submission record is therefore the reliable owner.
     */
    private static function resolve_submission_userid(\core\event\base $event): int {
        global $DB;

        $submissionid = (int)($event->other['submissionid'] ?? 0);
        if ($submissionid <= 0 && $event->objecttable === 'assign_submission') {
            $submissionid = (int)$event->objectid;
        }
        if ($submissionid > 0) {
            $owner = (int)$DB->get_field('assign_submission', 'userid', ['id' => $submissionid], IGNORE_MISSING);
            if ($owner > 0) {
                return $owner;
            }
        }
        if (!empty($event->relateduserid)) {
            return (int)$event->relateduserid;
        }
        return (int)$event->userid;
    }

    /**
     * Keep internal Type B reflection state aligned with the canonical Moodle
     * Assignment submission as soon as a student creates or updates it.
     */
    public static function assign_submission_changed(\core\event\base $event): void {
        global $DB;

        $cmid = (int)$event->contextinstanceid;
        $courseid = (int)$event->courseid;
        if ($cmid <= 0 || $courseid <= 0) {
            return;
        }
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))
                || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))) {
            return;
        }
        $userid = self::resolve_submission_userid($event);
        if ($userid <= 0) {
            return;
        }

        $sql = "SELECT e.id, e.workshopid
                  FROM {local_ga_workshop_editions} e
                  JOIN {local_ga_workshops} w ON w.id = e.workshopid
                 WHERE e.requiredcmid = :cmid
                   AND w.workshoptype = :typeb";
        $editions = $DB->get_records_sql($sql, ['cmid' => $cmid, 'typeb' => 'typeb']);
        if (!$editions) {
            return;
        }

        foreach ($editions as $edition) {
            try {
                typeb_reflection_activity::sync_legacy_compatibility((int)$edition->id, $userid);
            } catch (\Throwable $e) {
                if (function_exists('debugging')) {
                    debugging('No se pudo sincronizar la reflexión Tipo B desde la Tarea Moodle: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        // Type B internal hours depend on the reflection: refresh the block now.
        if (class_exists('\\block_gestion_hee\\local\\student_hours_cache')) {
            \block_gestion_hee\local\student_hours_cache::invalidate_user($userid);
        }

        // Recalculate only this user/course. If the submission is still a draft,
        // the canonical eligibility check leaves the reflection pending.
        try {
            grade_manager::sync_user_for_course_safely($courseid, $userid);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se pudieron recalcular las notas HEE tras la reflexión Tipo B: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
