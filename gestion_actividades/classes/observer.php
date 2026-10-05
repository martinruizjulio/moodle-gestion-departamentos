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
     * Keep internal Type B reflection state aligned with the canonical Moodle
     * Assignment submission as soon as a student creates or updates it.
     */
    public static function assign_submission_changed(\core\event\base $event): void {
        global $DB;

        $cmid = (int)$event->contextinstanceid;
        $userid = (int)$event->relateduserid;
        $courseid = (int)$event->courseid;
        if ($cmid <= 0 || $userid <= 0 || $courseid <= 0) {
            return;
        }
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))
                || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))) {
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
