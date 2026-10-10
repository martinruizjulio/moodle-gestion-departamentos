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
 * Class auto_certificates for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Automatic certificates when a workshop ends (agreed 2026-10-06).
 *
 * When a workshop has finished, every enrolled student who meets the
 * certificate rule gets the certificate (Type A: attendance "Presente" +
 * quiz finished with at least 5/10; Type B: attendance + reflection
 * submitted) and the hours are recorded, so they appear in the student's
 * record (portfolio, block and HEE grades, all from hours_calculator).
 *
 * "Finished" = quiz close date (Type A) or reflection deadline (Type B); if
 * that activity has no closing date, the end of the workshop session. The
 * check keeps running for AUTO_WINDOW days afterwards, so students graded or
 * marked later still receive their certificate. Only newly issued
 * certificates are e-mailed; nothing else is sent, so repeated runs are quiet.
 */
class auto_certificates {
    /** Days after the end during which late eligibility is still picked up. */
    public const AUTO_WINDOW = 30 * DAYSECS;

    /**
     * Moment the workshop counts as finished (0 = unknown).
     */
    public static function finish_time(\stdClass $edition): int {
        global $DB;
        [$start, $close] = materials_folder::window($edition);
        if ($close > 0) {
            return $close;
        }
        $workshop = manager::get_workshop((int)$edition->workshopid);
        // End of the last day (second day for two-day talleres).
        $end = workshop_series::last_end(workshop_series::item_for_edition($edition));
        if ($end <= 0 && $start > 0) {
            $hours = (float)($workshop->hours ?? 0);
            $end = $start + (int)round(($hours > 0 ? $hours : 1) * HOURSECS);
        }
        return $end;
    }

    /**
     * Issue the missing certificates of one edition and record the hours.
     *
     * @return int number of certificates issued now
     */
    public static function process_edition(int $editionid): int {
        global $DB;
        $edition = manager::get_workshop_edition($editionid);
        $workshop = manager::get_workshop((int)$edition->workshopid);
        attendance_sync::sync_edition_once($editionid);

        $issued = 0;
        foreach (manager::list_edition_enrolled_users_ultrasafe($editionid) as $row) {
            $userid = (int)($row->userid ?? 0);
            if ($userid <= 0 || manager::get_user_certificate_for_edition($editionid, $userid)) {
                continue;
            }
            if (!manager::user_is_certificate_eligible($editionid, $userid)) {
                continue;
            }
            $cert = manager::generate_certificate_for_user($editionid, $userid, false);
            if (!$cert) {
                continue;
            }
            $issued++;
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if ($user) {
                try {
                    manager::notify_new_certificate($user, $workshop, $edition, $cert);
                } catch (\Throwable $e) {
                    debugging('No se pudo avisar del certificado: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
        // Hour history + HEE grades of the course.
        manager::refresh_completed_hours_for_edition($editionid);
        if ($issued > 0) {
            grade_manager::sync_course_safely((int)$workshop->courseid);
        }
        return $issued;
    }

    /**
     * Scheduled run: every non-archived edition whose workshop finished within
     * the last AUTO_WINDOW.
     *
     * @return int certificates issued
     */
    public static function run(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $issued = 0;
        $columns = $DB->get_columns('local_ga_workshop_editions');
        $where = 'sessiondate > 0 AND sessiondate <= :now AND sessiondate >= :from';
        if (isset($columns['archived'])) {
            $where .= ' AND (archived = 0 OR archived IS NULL)';
        }
        $params = ['now' => $now, 'from' => $now - self::AUTO_WINDOW - 60 * DAYSECS];
        foreach ($DB->get_records_select('local_ga_workshop_editions', $where, $params, 'sessiondate ASC', 'id') as $row) {
            try {
                $edition = manager::get_workshop_edition((int)$row->id);
                $finish = self::finish_time($edition);
                if ($finish <= 0 || $finish > $now || $finish < $now - self::AUTO_WINDOW) {
                    continue;
                }
                $issued += self::process_edition((int)$row->id);
            } catch (\Throwable $e) {
                mtrace('  Edición ' . (int)$row->id . ': ' . $e->getMessage());
            }
        }
        return $issued;
    }
}
