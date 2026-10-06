<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Default "Materiales T0X" folder of each workshop edition.
 *
 * Agreed rule (2026-10-06): the files of a workshop are only visible to the
 * students enrolled in that workshop (its edition group), from the moment the
 * workshop starts until its quiz closes. For Type B (no quiz) the end is the
 * reflection deadline. Without a closing date there is no end limit.
 *
 * The folder is a standard Moodle mod_folder inside the TALLER subsection, so
 * teachers just drop files into it. Its availability is recalculated whenever
 * the course structure is synchronised and when the quiz/reflection dates
 * change (observer), so it follows date changes automatically.
 */
class materials_folder {

    /** idnumber marker of the generated folder. */
    public static function idnumber(int $editionid): string {
        return 'HEE_EDITION_' . $editionid . '_MATERIALS';
    }

    public static function available(): bool {
        global $DB;
        return $DB->record_exists('modules', ['name' => 'folder'])
            && $DB->get_manager()->table_exists(new \xmldb_table('folder'));
    }

    /**
     * Course module id of the edition folder, or 0.
     */
    public static function cmid(int $editionid, int $courseid): int {
        global $DB;
        return (int)$DB->get_field_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'folder'
              WHERE cm.course = :courseid AND cm.idnumber = :idnumber AND cm.deletioninprogress = 0",
            ['courseid' => $courseid, 'idnumber' => self::idnumber($editionid)],
            IGNORE_MULTIPLE
        );
    }

    /**
     * Create the folder if missing (modern editions only) and refresh its
     * availability. Returns the folder cmid or 0.
     */
    public static function ensure(int $editionid, int $sortorder = 0): int {
        global $DB, $CFG;
        if (!self::available()) {
            return 0;
        }
        $edition = manager::get_workshop_edition($editionid);
        if (empty($edition->seriesid)) {
            return 0;
        }
        $workshop = manager::get_workshop((int)$edition->workshopid);
        $courseid = (int)$workshop->courseid;

        $cmid = self::cmid($editionid, $courseid);
        if ($cmid <= 0) {
            $sectionnum = manager::series_parent_section_number($edition, $courseid);
            if ($sectionnum < 0) {
                return 0;
            }
            require_once($CFG->dirroot . '/course/lib.php');
            require_once($CFG->dirroot . '/course/modlib.php');
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $name = $sortorder > 0 ? 'Materiales T' . sprintf('%02d', $sortorder) : 'Materiales ' . $workshop->code;
            $moduleinfo = (object)[
                'course' => $courseid,
                'section' => $sectionnum,
                'module' => (int)$DB->get_field('modules', 'id', ['name' => 'folder'], MUST_EXIST),
                'modulename' => 'folder',
                'name' => $name,
                'intro' => '<p>Materiales del taller. Visibles para el alumnado apuntado desde el inicio del taller '
                    . 'hasta el cierre del cuestionario.</p>',
                'introformat' => FORMAT_HTML,
                'showdescription' => 0,
                'visible' => 1,
                'visibleoncoursepage' => 1,
                'groupmode' => 0,
                'groupingid' => 0,
                'completion' => 0,
                'completionview' => 0,
                'completionexpected' => 0,
                'completiongradeitemnumber' => '',
                'cmidnumber' => self::idnumber($editionid),
                'files' => 0,
                'revision' => 1,
                'display' => 0,
                'showexpanded' => 1,
                'showdownloadfolder' => 1,
                'forcedownload' => 1,
            ];
            $outertransaction = $DB->is_transaction_started();
            try {
                $created = add_moduleinfo($moduleinfo, $course);
                $cmid = (int)($created->coursemodule ?? 0);
            } catch (\Throwable $e) {
                if ($outertransaction) {
                    throw $e;
                }
                if ($DB->is_transaction_started()) {
                    $DB->force_transaction_rollback();
                }
                debugging('No se pudo crear la carpeta de materiales del taller: ' . $e->getMessage(), DEBUG_DEVELOPER);
                return 0;
            }
        }
        if ($cmid > 0) {
            self::refresh_availability($editionid);
        }
        return $cmid;
    }

    /**
     * Start (workshop session) and end (quiz close / reflection deadline).
     *
     * @return int[] [start, end] (0 = no limit)
     */
    public static function window(\stdClass $edition): array {
        global $DB;
        $start = (int)($edition->sessiondate ?? 0);
        $end = 0;
        $workshop = manager::get_workshop((int)$edition->workshopid);
        if (manager::is_typeb_workshop($workshop)) {
            $cmid = (int)(!empty($edition->requiredassigncmid) ? $edition->requiredassigncmid : ($edition->requiredcmid ?? 0));
            if ($cmid > 0) {
                $assign = $DB->get_record_sql(
                    "SELECT a.duedate, a.cutoffdate
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                       JOIN {assign} a ON a.id = cm.instance
                      WHERE cm.id = :cmid",
                    ['cmid' => $cmid],
                    IGNORE_MISSING
                );
                if ($assign) {
                    $end = (int)$assign->cutoffdate > 0 ? (int)$assign->cutoffdate : (int)$assign->duedate;
                }
            }
        } else {
            $cmid = (int)(!empty($edition->requiredquizcmid) ? $edition->requiredquizcmid : ($edition->requiredcmid ?? 0));
            if ($cmid > 0) {
                $end = (int)$DB->get_field_sql(
                    "SELECT q.timeclose
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                       JOIN {quiz} q ON q.id = cm.instance
                      WHERE cm.id = :cmid",
                    ['cmid' => $cmid]
                );
            }
        }
        if ($end > 0 && $start > 0 && $end <= $start) {
            $end = 0;
        }
        return [$start, $end];
    }

    /**
     * Availability: edition group (hidden to everyone else) AND from the
     * workshop start AND until the quiz closes.
     */
    public static function refresh_availability(int $editionid): void {
        global $DB;
        $edition = manager::get_workshop_edition($editionid);
        $workshop = manager::get_workshop((int)$edition->workshopid);
        $cmid = self::cmid($editionid, (int)$workshop->courseid);
        if ($cmid <= 0) {
            return;
        }
        $groupid = (int)($edition->groupid ?? 0);
        if ($groupid <= 0) {
            $groupid = manager::get_or_create_edition_group($editionid);
        }
        [$start, $end] = self::window($edition);
        $conditions = [['type' => 'group', 'id' => $groupid]];
        $showc = [false];
        if ($start > 0) {
            $conditions[] = ['type' => 'date', 'd' => '>=', 't' => $start];
            $showc[] = true;
        }
        if ($end > 0) {
            $conditions[] = ['type' => 'date', 'd' => '<', 't' => $end];
            $showc[] = true;
        }
        $availability = json_encode(['op' => '&', 'c' => $conditions, 'showc' => $showc], JSON_UNESCAPED_SLASHES);
        $current = (string)$DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        if ($current !== $availability) {
            $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
            rebuild_course_cache((int)$workshop->courseid, true);
        }
    }

    /**
     * Quiz/assignment settings changed: refresh the folders of the editions
     * that use that activity as their required activity.
     */
    public static function refresh_for_required_cm(int $cmid): void {
        global $DB;
        if ($cmid <= 0) {
            return;
        }
        $columns = $DB->get_columns('local_ga_workshop_editions');
        $where = ['requiredcmid = :c1'];
        $params = ['c1' => $cmid];
        if (isset($columns['requiredquizcmid'])) {
            $where[] = 'requiredquizcmid = :c2';
            $params['c2'] = $cmid;
        }
        if (isset($columns['requiredassigncmid'])) {
            $where[] = 'requiredassigncmid = :c3';
            $params['c3'] = $cmid;
        }
        $ids = $DB->get_fieldset_select('local_ga_workshop_editions', 'id', implode(' OR ', $where), $params);
        foreach ($ids as $id) {
            self::refresh_availability((int)$id);
        }
    }
}
