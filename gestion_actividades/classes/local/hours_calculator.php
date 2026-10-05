<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Single source of truth for HEE recognised hours.
 *
 * Used by the student portfolio, the manager portfolio, the PDF, the gradebook
 * hours item (which unlocks the self-assessment at 54 h) and block_gestion_hee,
 * so every screen shows the same figures.
 *
 * Rules:
 * - Workshop hours come from the stored hour history and from certificates whose
 *   edition is not in the history yet (one entry per user and edition).
 * - Type A / Type B is decided by the workshop type.
 * - Internal Type B only counts with a submitted Moodle reflection (latest
 *   assignment submission in status 'submitted'); legacy editions without a
 *   reflection assignment keep counting as before.
 * - External Type B only counts when validated AND with a reflection.
 * - Institutional recognition adds its Type A / Type B hours.
 * - Active transfers move hours from A to B without changing the total.
 */
class hours_calculator {
    public const TARGET_HOURS = 54.0;

    /**
     * Summary for one user.
     */
    public static function for_user(int $userid): \stdClass {
        $all = self::for_users([$userid]);
        return $all[$userid] ?? self::empty_summary();
    }

    /**
     * Summaries for several users, indexed by user id.
     *
     * @param int[] $userids
     * @return \stdClass[]
     */
    public static function for_users(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        $out = [];
        foreach ($userids as $userid) {
            $out[$userid] = self::empty_summary();
        }
        if (!$userids) {
            return $out;
        }

        $dbman = $DB->get_manager();
        $has = static function(string $table) use ($dbman): bool {
            return $dbman->table_exists(new \xmldb_table($table));
        };

        // 1. Workshop entries: hour history + certificates not yet in history.
        $entries = [];
        if ($has('local_ga_hour_history') && $has('local_ga_workshops')) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'hh');
            $sql = "SELECT h.id, h.userid, h.editionid, h.hours, w.workshoptype
                      FROM {local_ga_hour_history} h
                 LEFT JOIN {local_ga_workshops} w ON w.id = h.workshopid
                     WHERE h.userid $insql";
            foreach ($DB->get_records_sql($sql, $params) as $r) {
                $key = (int)$r->editionid > 0 ? 'e' . (int)$r->editionid : 'h' . (int)$r->id;
                $entries[(int)$r->userid][$key] = (object)[
                    'editionid' => (int)$r->editionid,
                    'hours' => (float)$r->hours,
                    'typeb' => (string)$r->workshoptype === 'typeb',
                ];
            }
        }
        if ($has('local_ga_certificates') && $has('local_ga_workshops')) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ce');
            $sql = "SELECT c.id, c.userid, c.editionid, c.certificatetype, w.hours, w.workshoptype
                      FROM {local_ga_certificates} c
                      JOIN {local_ga_workshops} w ON w.id = c.workshopid
                     WHERE c.userid $insql";
            foreach ($DB->get_records_sql($sql, $params) as $r) {
                $key = (int)$r->editionid > 0 ? 'e' . (int)$r->editionid : 'c' . (int)$r->id;
                if (isset($entries[(int)$r->userid][$key])) {
                    continue;
                }
                $typeb = (string)$r->workshoptype === 'typeb' || (string)($r->certificatetype ?? '') === 'typeb';
                $entries[(int)$r->userid][$key] = (object)[
                    'editionid' => (int)$r->editionid,
                    'hours' => (float)($r->hours ?? 0),
                    'typeb' => $typeb,
                ];
            }
        }

        // Internal Type B editions: which ones require a Moodle reflection and
        // which users have it submitted.
        $typebeditions = [];
        foreach ($entries as $userentries) {
            foreach ($userentries as $entry) {
                if ($entry->typeb && $entry->editionid > 0) {
                    $typebeditions[$entry->editionid] = $entry->editionid;
                }
            }
        }
        $reflectioncm = [];
        $reflected = [];
        if ($typebeditions && $has('local_ga_workshop_editions')) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($typebeditions), SQL_PARAMS_NAMED, 'te');
            foreach ($DB->get_records_select('local_ga_workshop_editions', "id $insql", $params, '', 'id, requiredcmid') as $e) {
                if ((int)$e->requiredcmid > 0) {
                    $reflectioncm[(int)$e->id] = (int)$e->requiredcmid;
                }
            }
            if ($reflectioncm) {
                [$cmsql, $cmparams] = $DB->get_in_or_equal(array_values(array_unique($reflectioncm)), SQL_PARAMS_NAMED, 'rc');
                [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ru');
                $sql = "SELECT s.id, cm.id AS cmid, s.userid
                          FROM {course_modules} cm
                          JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                          JOIN {assign_submission} s ON s.assignment = cm.instance
                         WHERE cm.id $cmsql
                           AND s.userid $usql
                           AND s.latest = 1
                           AND s.status = 'submitted'";
                foreach ($DB->get_records_sql($sql, $cmparams + $uparams) as $r) {
                    $reflected[(int)$r->cmid][(int)$r->userid] = true;
                }
            }
        }

        foreach ($entries as $userid => $userentries) {
            foreach ($userentries as $entry) {
                if (!$entry->typeb) {
                    $out[$userid]->workshoptypea += $entry->hours;
                    continue;
                }
                $cmid = $reflectioncm[$entry->editionid] ?? 0;
                if ($cmid > 0 && empty($reflected[$cmid][$userid])) {
                    $out[$userid]->typebpendingreflection += $entry->hours;
                    continue;
                }
                $out[$userid]->workshoptypeb += $entry->hours;
            }
        }

        // 2. External Type B: validated + reflection.
        if ($has('local_ga_typeb_certs')) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'tb');
            foreach ($DB->get_records_select('local_ga_typeb_certs', "userid $insql", $params, '', 'id, userid, hours, status, reflectiontext') as $r) {
                if (portfolio_typeb::is_countable($r)) {
                    $out[(int)$r->userid]->externaltypeb += (float)$r->hours;
                } else if ((string)$r->status !== 'rejected') {
                    $out[(int)$r->userid]->externalpending += (float)$r->hours;
                }
            }
        }

        // 3. Institutional recognition.
        if ($has('local_ga_institutional_hours')) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ih');
            $sql = "SELECT userid, COALESCE(SUM(typeahours), 0) AS a, COALESCE(SUM(typebhours), 0) AS b
                      FROM {local_ga_institutional_hours}
                     WHERE userid $insql
                  GROUP BY userid";
            foreach ($DB->get_records_sql($sql, $params) as $r) {
                $out[(int)$r->userid]->institutionaltypea += (float)$r->a;
                $out[(int)$r->userid]->institutionaltypeb += (float)$r->b;
            }
        }

        // 4. Active transfers A -> B.
        if ($has('local_ga_typeb_transfers')) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'tr');
            $params['active'] = 'active';
            $sql = "SELECT userid, COALESCE(SUM(hours), 0) AS hours
                      FROM {local_ga_typeb_transfers}
                     WHERE userid $insql AND status = :active
                  GROUP BY userid";
            foreach ($DB->get_records_sql($sql, $params) as $r) {
                $out[(int)$r->userid]->transferhours += (float)$r->hours;
            }
        }

        foreach ($out as $summary) {
            self::finalise($summary);
        }
        return $out;
    }

    private static function empty_summary(): \stdClass {
        $summary = (object)[
            'workshoptypea' => 0.0,
            'workshoptypeb' => 0.0,
            'typebpendingreflection' => 0.0,
            'externaltypeb' => 0.0,
            'externalpending' => 0.0,
            'institutionaltypea' => 0.0,
            'institutionaltypeb' => 0.0,
            'transferhours' => 0.0,
        ];
        return self::finalise($summary);
    }

    private static function finalise(\stdClass $s): \stdClass {
        $grossa = $s->workshoptypea + $s->institutionaltypea;
        $transfer = min($s->transferhours, $grossa);
        $s->typeahours = round(max(0.0, $grossa - $transfer), 2);
        $s->typebhours = round(max(0.0, $s->workshoptypeb + $s->externaltypeb + $s->institutionaltypeb + $transfer), 2);
        $s->totalhours = round($s->typeahours + $s->typebhours, 2);
        $s->remaining = round(max(0.0, self::TARGET_HOURS - $s->totalhours), 2);
        $s->target = self::TARGET_HOURS;
        return $s;
    }
}
