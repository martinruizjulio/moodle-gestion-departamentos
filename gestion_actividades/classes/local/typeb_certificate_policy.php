<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Certificate policy for internal Type B workshops.
 *
 * Type B is Apto only when attendance is confirmed and the Moodle reflection
 * assignment has been submitted/completed. There is no numeric mark.
 */
class typeb_certificate_policy {
    public static function is_eligible(int $editionid, int $userid): bool {
        $edition = manager::get_workshop_edition($editionid);
        $workshop = manager::get_workshop((int)$edition->workshopid);
        if (!manager::is_typeb_workshop($workshop)) {
            return false;
        }
        if (!manager::is_user_attended_edition($editionid, $userid)) {
            return false;
        }
        return self::has_reflection($editionid, $userid);
    }

    /**
     * Reflection = the edition's Moodle assignment submitted (latest attempt).
     * Both attendance and reflection are mandatory for Type B.
     */
    public static function has_reflection(int $editionid, int $userid): bool {
        $edition = manager::get_workshop_edition($editionid);
        if ((int)($edition->requiredcmid ?? 0) <= 0) {
            return false;
        }
        return !empty(typeb_reflection_activity::submission_summary($editionid, $userid)->submitted);
    }

    public static function missing_requirements(int $editionid, int $userid): array {
        $edition = manager::get_workshop_edition($editionid);
        $missing = [];
        if (!manager::is_user_attended_edition($editionid, $userid)) {
            $missing[] = 'asistencia';
        }
        if (!self::has_reflection($editionid, $userid)) {
            $missing[] = 'reflexión';
        }
        return $missing;
    }

    /**
     * Generate only eligible Type B certificates. Existing certificates are
     * preserved. This wrapper prevents the legacy attendance-only rule from
     * generating new Type B certificates prematurely.
     */
    public static function generate_for_edition(int $editionid): \stdClass {
        $edition = manager::get_workshop_edition($editionid);
        $workshop = manager::get_workshop((int)$edition->workshopid);
        if (!manager::is_typeb_workshop($workshop)) {
            throw new \coding_exception('typeb_certificate_policy solo admite talleres Tipo B.');
        }

        $summary = (object)[
            'eligible' => 0,
            'generated' => 0,
            'existing' => 0,
            'skipped' => 0,
            'missingattendance' => 0,
            'missingreflection' => 0,
        ];

        foreach (manager::list_edition_enrolled_users_ultrasafe($editionid) as $student) {
            $userid = (int)$student->userid;
            if (!self::is_eligible($editionid, $userid)) {
                $summary->skipped++;
                $missing = self::missing_requirements($editionid, $userid);
                if (in_array('asistencia', $missing, true)) {
                    $summary->missingattendance++;
                }
                if (in_array('reflexión', $missing, true)) {
                    $summary->missingreflection++;
                }
                continue;
            }

            // The Moodle Assignment is the source of truth. Synchronise the
            // compatibility record before the gradebook/portfolio calculation.
            typeb_reflection_activity::sync_legacy_compatibility($editionid, $userid);

            $summary->eligible++;
            $before = manager::get_user_certificate_for_edition($editionid, $userid);
            $certificate = manager::generate_certificate_for_user($editionid, $userid, false);
            if ($certificate && $before) {
                $summary->existing++;
            } else if ($certificate) {
                $summary->generated++;
            } else {
                $summary->skipped++;
            }
        }

        if ($summary->eligible > 0 && class_exists(grade_manager::class)) {
            grade_manager::sync_course_safely((int)$workshop->courseid);
        }
        return $summary;
    }
}
