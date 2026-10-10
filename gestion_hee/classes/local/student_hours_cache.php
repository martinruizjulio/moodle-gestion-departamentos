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
 * Class student_hours_cache for block_gestion_hee.
 *
 * @package    block_gestion_hee
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_gestion_hee\local;

/**
 * Short-lived cache of the hour summary shown to students in the block.
 */
class student_hours_cache {
    /**
     * Seconds a cached hour summary is considered fresh.
     */
    private const HOURS_FRESH_TTL = 300;
    /**
     * Seconds the detected database schema is cached.
     */
    private const SCHEMA_CACHE_TTL = 3600;
    /**
     * Hours required to complete HEE.
     */
    private const TARGET_HOURS = 54.0;
    /**
     * Seconds to wait for the recalculation lock.
     */
    private const LOCK_TIMEOUT_SECONDS = 1;
    /**
     * Prefix of the lock resource name.
     */
    private const LOCK_RESOURCE_PREFIX = 'student_hours_';

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

        $cache = \cache::make('block_gestion_hee', 'student_hours');
        $key = self::user_key($userid);
        $cached = $cache->get($key);

        if (self::is_fresh_summary($cached)) {
            return $cached;
        }

        $lock = null;
        $lockfactory = null;

        try {
            $lockfactory = \core\lock\lock_config::get_lock_factory('block_gestion_hee');
            $lock = $lockfactory->get_lock(self::LOCK_RESOURCE_PREFIX . $userid, self::LOCK_TIMEOUT_SECONDS);

            if (!$lock) {
                // Another request is already refreshing this user. Re-read once in case it finished
                // while we were waiting; otherwise return the last known value, even if stale.
                $recent = $cache->get($key);
                if (self::is_valid_summary($recent)) {
                    $recent['stale'] = !self::is_fresh_summary($recent);
                    return $recent;
                }

                $summary = self::empty_summary();
                $summary['stale'] = true;
                return $summary;
            }

            // A previous request may have populated the cache while this request was waiting for the lock.
            $cachedafterlock = $cache->get($key);
            if (self::is_fresh_summary($cachedafterlock)) {
                return $cachedafterlock;
            }

            $summary = self::calculate_summary($userid);
            $cache->set($key, $summary);
            return $summary;
        } catch (\Throwable $e) {
            self::debug_error('Could not calculate the Gestión HEE block hours.', $e);
            if (is_array($cached) && self::is_valid_summary($cached)) {
                $cached['stale'] = true;
                return $cached;
            }

            $summary = self::empty_summary();
            $summary['error'] = true;
            return $summary;
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    self::debug_error(
                        'Could not release the Gestión HEE block lock.',
                        $e
                    );
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
        $userid = max(0, $userid);
        if ($userid <= 0) {
            return;
        }
        try {
            \cache::make('block_gestion_hee', 'student_hours')->delete(self::user_key($userid));
        } catch (\Throwable $e) {
            self::debug_error('Could not invalidate the Gestión HEE block user cache.', $e);
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
     * Invalidate every cached hour summary.
     */
    public static function invalidate_all(): void {
        try {
            \cache::make('block_gestion_hee', 'student_hours')->purge();
        } catch (\Throwable $e) {
            self::debug_error('Could not purge the Gestión HEE block hours cache.', $e);
        }
    }

    /**
     * Invalidate the cached schema information.
     */
    public static function invalidate_schema(): void {
        try {
            \cache::make('block_gestion_hee', 'schema')->purge();
        } catch (\Throwable $e) {
            self::debug_error('Could not purge the Gestión HEE block schema cache.', $e);
        }
    }

    /**
     * Calculate the same academic hours represented by the current HEE model.
     *
     * Hour history is the canonical stored result for finished editions. When a
     * certificate exists for an edition that is not yet represented in history,
     * it is added once. Internal Type B is classified by workshop type, external
     * Type B only counts after validation + reflection, and active transfers move
     * hours from A to B without changing the total.
     */
    private static function calculate_summary(int $userid): array {
        global $DB;

        // Preferred path: the canonical calculator of local_gestion_actividades,
        // so the block shows exactly the portfolio/gradebook figures (Type B
        // internal only with a submitted reflection, external only validated +
        // reflection, transfers A -> B). The SQL below is a fallback for an
        // older local plugin without that class.
        if (class_exists('\\local_gestion_actividades\\local\\hours_calculator')) {
            $hours = \local_gestion_actividades\local\hours_calculator::for_user($userid);
            return self::build_summary((float)$hours->typeahours, (float)$hours->typebhours);
        }

        $schema = self::get_schema();
        $typeahours = 0.0;
        $typebhours = 0.0;

        $hashistory = !empty($schema['hour_history']);
        $hasworkshops = !empty($schema['workshops']);

        if ($hashistory && $hasworkshops) {
            $sql = "SELECT
                        COALESCE(SUM(CASE WHEN w.workshoptype = 'typeb' THEN 0 ELSE h.hours END), 0) AS typeahours,
                        COALESCE(SUM(CASE WHEN w.workshoptype = 'typeb' THEN h.hours ELSE 0 END), 0) AS typebhours
                      FROM {local_ga_hour_history} h
                 LEFT JOIN {local_ga_workshops} w ON w.id = h.workshopid
                     WHERE h.userid = :userid";
            $history = $DB->get_record_sql($sql, ['userid' => $userid], IGNORE_MISSING);
            if ($history) {
                $typeahours += (float)($history->typeahours ?? 0);
                $typebhours += (float)($history->typebhours ?? 0);
            }
        } else if ($hashistory) {
            // Legacy fallback when the workshop catalogue is not available.
            $typeahours += (float)$DB->get_field_sql(
                'SELECT COALESCE(SUM(hours), 0) FROM {local_ga_hour_history} WHERE userid = :userid',
                ['userid' => $userid]
            );
        }

        if (!empty($schema['certificates']) && $hasworkshops) {
            $certcolumns = $DB->get_columns('local_ga_certificates');
            $historycolumns = $hashistory ? $DB->get_columns('local_ga_hour_history') : [];
            $candedupe = $hashistory && isset($certcolumns['editionid']) && isset($historycolumns['editionid']);
            $notexists = $candedupe
                ? ' AND NOT EXISTS (SELECT 1 FROM {local_ga_hour_history} h'
                    . ' WHERE h.userid = c.userid AND h.editionid = c.editionid)'
                : '';
            $typeafilter = isset($certcolumns['certificatetype'])
                ? " AND (c.certificatetype = 'typea' OR c.certificatetype IS NULL OR c.certificatetype = '')"
                : '';

            $sql = "SELECT COALESCE(SUM(COALESCE(w.hours, 0)), 0)
                      FROM {local_ga_certificates} c
                      JOIN {local_ga_workshops} w ON w.id = c.workshopid
                     WHERE c.userid = :userid $typeafilter $notexists";
            $typeahours += (float)$DB->get_field_sql($sql, ['userid' => $userid]);

            if (isset($certcolumns['certificatetype'])) {
                $sql = "SELECT COALESCE(SUM(COALESCE(w.hours, 0)), 0)
                          FROM {local_ga_certificates} c
                          JOIN {local_ga_workshops} w ON w.id = c.workshopid
                         WHERE c.userid = :userid
                           AND c.certificatetype = 'typeb'
                           $notexists";
                $typebhours += (float)$DB->get_field_sql($sql, ['userid' => $userid]);
            }
        }

        if (!empty($schema['typeb_certs'])) {
            $columns = $DB->get_columns('local_ga_typeb_certs');
            $reflectionfilter = isset($columns['reflectiontext'])
                ? " AND reflectiontext IS NOT NULL AND reflectiontext <> ''"
                : '';
            $sql = "SELECT COALESCE(SUM(hours), 0)
                      FROM {local_ga_typeb_certs}
                     WHERE userid = :userid
                       AND status = :status
                       $reflectionfilter";
            $typebhours += (float)$DB->get_field_sql($sql, [
                'userid' => $userid,
                'status' => 'validated',
            ]);
        }

        if (!empty($schema['institutional_hours'])) {
            $sql = "SELECT COALESCE(SUM(typeahours), 0) AS typeahours, COALESCE(SUM(typebhours), 0) AS typebhours
                      FROM {local_ga_institutional_hours}
                     WHERE userid = :userid";
            $institutional = $DB->get_record_sql($sql, ['userid' => $userid], IGNORE_MISSING);
            if ($institutional) {
                $typeahours += (float)($institutional->typeahours ?? 0);
                $typebhours += (float)($institutional->typebhours ?? 0);
            }
        }

        if (!empty($schema['typeb_transfers'])) {
            $sql = "SELECT COALESCE(SUM(hours), 0)
                      FROM {local_ga_typeb_transfers}
                     WHERE userid = :userid
                       AND status = :status";
            $transferhours = (float)$DB->get_field_sql($sql, ['userid' => $userid, 'status' => 'active']);
            $typeahours = max(0.0, $typeahours - $transferhours);
            $typebhours += $transferhours;
        }

        return self::build_summary($typeahours, $typebhours);
    }

    /**
     * Detect which tables and columns of local_gestion_actividades are available.
     *
     * @return array
     */
    private static function get_schema(): array {
        $cache = \cache::make('block_gestion_hee', 'schema');
        $cached = $cache->get('tables');
        if (
            is_array($cached) && !empty($cached['timecreated'])
                && (time() - (int)$cached['timecreated']) <= self::SCHEMA_CACHE_TTL
        ) {
            return $cached;
        }

        global $DB;
        $dbman = $DB->get_manager();
        $schema = [
            'certificates' => $dbman->table_exists(new \xmldb_table('local_ga_certificates')),
            'workshops' => $dbman->table_exists(new \xmldb_table('local_ga_workshops')),
            'hour_history' => $dbman->table_exists(new \xmldb_table('local_ga_hour_history')),
            'typeb_certs' => $dbman->table_exists(new \xmldb_table('local_ga_typeb_certs')),
            'institutional_hours' => $dbman->table_exists(new \xmldb_table('local_ga_institutional_hours')),
            'typeb_transfers' => $dbman->table_exists(new \xmldb_table('local_ga_typeb_transfers')),
            'timecreated' => time(),
        ];
        $cache->set('tables', $schema);
        return $schema;
    }

    /**
     * Build the summary array returned to the block.
     *
     * @param float $typeahours
     * @param float $typebhours
     * @return array
     */
    private static function build_summary(float $typeahours, float $typebhours): array {
        $typeahours = round(max(0.0, $typeahours), 2);
        $typebhours = round(max(0.0, $typebhours), 2);
        $total = round($typeahours + $typebhours, 2);
        $remaining = round(max(0.0, self::TARGET_HOURS - $total), 2);

        return [
            'typeahours' => $typeahours,
            'typebhours' => $typebhours,
            'total' => $total,
            'remaining' => $remaining,
            'target' => self::TARGET_HOURS,
            'timecreated' => time(),
            'error' => false,
            'stale' => false,
        ];
    }

    /**
     * Summary used when there is no data.
     *
     * @return array
     */
    private static function empty_summary(): array {
        return self::build_summary(0.0, 0.0);
    }

    /**
     * Whether a cached hour summary is still fresh.
     *
     * @param mixed $summary
     * @return bool
     */
    private static function is_fresh_summary($summary): bool {
        return self::is_valid_summary($summary)
            && !empty($summary['timecreated'])
            && (time() - (int)$summary['timecreated']) <= self::HOURS_FRESH_TTL;
    }

    /**
     * Whether a cached value has the expected structure.
     *
     * @param mixed $summary
     * @return bool
     */
    private static function is_valid_summary($summary): bool {
        return is_array($summary)
            && array_key_exists('typeahours', $summary)
            && array_key_exists('typebhours', $summary)
            && array_key_exists('total', $summary)
            && array_key_exists('remaining', $summary);
    }

    /**
     * Cache key for a user.
     *
     * @param int $userid
     * @return string
     */
    private static function user_key(int $userid): string {
        return 'u' . $userid;
    }

    /**
     * Report an error to developers without breaking the page.
     *
     * @param string $message
     * @param \Throwable $e
     */
    private static function debug_error(string $message, \Throwable $e): void {
        if (function_exists('debugging')) {
            debugging($message . ' ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
