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
 * Class teacher_workshops_cache for block_gestion_hee.
 *
 * @package    block_gestion_hee
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_gestion_hee\local;

/**
 * Lightweight cached summary of workshops assigned to a teacher.
 */
class teacher_workshops_cache {
    /**
     * Seconds a cached teacher summary is considered fresh.
     */
    private const FRESH_TTL = 300;
    /**
     * Seconds to wait for the recalculation lock.
     */
    private const LOCK_TIMEOUT = 1;

    /**
     * Return the cached summary, recalculating it when it is stale.
     *
     * @param int $userid
     * @return array
     */
    public static function get_summary(int $userid): array {
        $userid = max(0, $userid);
        if ($userid <= 0) {
            return self::empty_summary();
        }

        $cache = \cache::make('block_gestion_hee', 'teacher_workshops');
        $key = 'u' . $userid;
        $cached = $cache->get($key);
        if (self::is_fresh($cached)) {
            return $cached;
        }

        $lock = null;
        try {
            $factory = \core\lock\lock_config::get_lock_factory('block_gestion_hee');
            $lock = $factory->get_lock('teacher_workshops_' . $userid, self::LOCK_TIMEOUT);
            if (!$lock) {
                return is_array($cached) ? $cached : self::empty_summary();
            }
            $again = $cache->get($key);
            if (self::is_fresh($again)) {
                return $again;
            }
            $summary = self::calculate($userid);
            $cache->set($key, $summary);
            return $summary;
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('Could not calculate the Gestión HEE teacher summary: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return is_array($cached) ? $cached : self::empty_summary();
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    debugging('Could not release the Gestión HEE teacher lock: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * Invalidate the cached summary of one user.
     *
     * @param int $userid
     */
    public static function invalidate_user(int $userid): void {
        if ($userid <= 0) {
            return;
        }
        try {
            \cache::make('block_gestion_hee', 'teacher_workshops')->delete('u' . $userid);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('Could not invalidate the Gestión HEE teacher cache: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Invalidate the cached summaries of several users.
     *
     * @param array $userids
     */
    public static function invalidate_users(array $userids): void {
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            self::invalidate_user($userid);
        }
    }

    /**
     * Calculate the summary of workshops assigned to a teacher.
     *
     * @param int $userid
     * @return array
     */
    private static function calculate(int $userid): array {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['local_ga_edition_teachers', 'local_ga_workshop_editions', 'local_ga_workshops'] as $tablename) {
            if (!$dbman->table_exists(new \xmldb_table($tablename))) {
                return self::empty_summary();
            }
        }

        $hasseries = $dbman->table_exists(new \xmldb_table('local_ga_workshop_series'))
            && array_key_exists('seriesid', $DB->get_columns('local_ga_workshop_editions'));
        $seriesjoin = $hasseries
            ? ' LEFT JOIN {local_ga_workshop_series} s ON s.id = e.seriesid'
            : '';
        $seriesfinished = $hasseries ? " OR COALESCE(s.status, '') = 'finished'" : '';
        $finished = "(COALESCE(e.archived, 0) = 1"
            . " OR COALESCE(e.status, '') IN ('archived','finished','completed','closed_finished')$seriesfinished)";

        $sql = "SELECT
                    SUM(CASE WHEN $finished THEN 0 ELSE 1 END) AS activecount,
                    SUM(CASE WHEN $finished THEN 1 ELSE 0 END) AS finishedcount
                  FROM {local_ga_edition_teachers} et
                  JOIN {local_ga_workshop_editions} e ON e.id = et.editionid
                  JOIN {local_ga_workshops} w ON w.id = e.workshopid
                  $seriesjoin
                 WHERE et.userid = :userid";
        $row = $DB->get_record_sql($sql, ['userid' => $userid]);

        return [
            'activecount' => (int)($row->activecount ?? 0),
            'finishedcount' => (int)($row->finishedcount ?? 0),
            'total' => (int)($row->activecount ?? 0) + (int)($row->finishedcount ?? 0),
            'timecreated' => time(),
        ];
    }

    /**
     * Summary used when there is no data.
     *
     * @return array
     */
    private static function empty_summary(): array {
        return ['activecount' => 0, 'finishedcount' => 0, 'total' => 0, 'timecreated' => time()];
    }

    /**
     * Whether a cached summary is still fresh.
     *
     * @param mixed $summary
     * @return bool
     */
    private static function is_fresh($summary): bool {
        return is_array($summary)
            && isset($summary['timecreated'])
            && (time() - (int)$summary['timecreated']) <= self::FRESH_TTL;
    }
}
