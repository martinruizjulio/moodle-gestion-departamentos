<?php
namespace block_gestion_hee\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Lightweight cached summary of workshops assigned to a teacher.
 */
class teacher_workshops_cache {
    private const FRESH_TTL = 300;
    private const LOCK_TIMEOUT = 1;

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
                debugging('No se ha podido calcular el resumen docente de Gestión HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return is_array($cached) ? $cached : self::empty_summary();
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    // Nothing else to do.
                }
            }
        }
    }

    public static function invalidate_user(int $userid): void {
        if ($userid <= 0) {
            return;
        }
        try {
            \cache::make('block_gestion_hee', 'teacher_workshops')->delete('u' . $userid);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se ha podido invalidar la caché docente de Gestión HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    public static function invalidate_users(array $userids): void {
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            self::invalidate_user($userid);
        }
    }

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
        $seriesfinished = $hasseries ? " OR s.status = 'finished'" : '';
        $finished = "(e.archived = 1 OR e.status IN ('archived','finished','completed','closed_full','closed_finished')$seriesfinished)";

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

    private static function empty_summary(): array {
        return ['activecount' => 0, 'finishedcount' => 0, 'total' => 0, 'timecreated' => time()];
    }

    private static function is_fresh($summary): bool {
        return is_array($summary)
            && isset($summary['timecreated'])
            && (time() - (int)$summary['timecreated']) <= self::FRESH_TTL;
    }
}
