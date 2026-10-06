<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps the Moodle course hierarchy aligned with the HEE seminar model.
 *
 * Visible order for current content:
 * 1) Seminarios Tipo A (calendar first, then seminar subsections)
 * 2) Seminarios Tipo B (calendar first, then seminar subsections)
 * 3) Autoevaluación final HEE (availability controlled at 54 recognised hours)
 * 4) Previous seminar editions, hidden from students.
 */
class course_layout {
    private const SELFASSESSMENT_IDNUMBER = 'gestion_hee_selfassessment';

    /**
     * Return the single workshop type used by a series.
     * Empty series return an empty string; legacy mixed series return "mixed".
     */
    public static function series_type(int $seriesid): string {
        global $DB;
        if ($seriesid <= 0) {
            return '';
        }
        $sql = "SELECT DISTINCT w.workshoptype
                  FROM {local_ga_series_items} i
                  JOIN {local_ga_workshops} w ON w.id = i.workshopid
                 WHERE i.seriesid = :seriesid";
        $types = array_values($DB->get_fieldset_sql($sql, ['seriesid' => $seriesid]));
        $types = array_values(array_unique(array_map(static function($type): string {
            return (string)$type === 'typeb' ? 'typeb' : 'typea';
        }, $types)));
        if (!$types) {
            return '';
        }
        return count($types) === 1 ? $types[0] : 'mixed';
    }

    /**
     * Prevent new A/B mixtures inside one Edición de seminarios.
     */
    public static function assert_series_accepts_type(int $seriesid, string $type): void {
        $type = $type === 'typeb' ? 'typeb' : 'typea';
        $current = self::series_type($seriesid);
        if ($current === 'mixed') {
            throw new \RuntimeException('Esta edición contiene seminarios Tipo A y Tipo B heredados. No se pueden añadir más hasta separar la edición.');
        }
        if ($current !== '' && $current !== $type) {
            throw new \RuntimeException(
                'Una misma Edición de seminarios no puede mezclar Tipo A y Tipo B. Crea o selecciona una edición del tipo correspondiente.'
            );
        }
    }

    /**
     * Apply visible names, visibility and HEE section ordering for one course.
     */
    public static function synchronise_course(int $courseid): void {
        global $CFG, $DB;
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }
        if (!$DB->get_manager()->table_exists(new \xmldb_table(workshop_series::TABLE))
                || !$DB->get_manager()->table_exists(new \xmldb_table(workshop_series::ITEMTABLE))) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $activea = [];
        $activeb = [];
        $activeother = [];
        $history = [];

        foreach ($DB->get_records(workshop_series::TABLE, ['courseid' => $courseid], 'datefrom DESC, id DESC') as $series) {
            if (empty($series->sectionid)) {
                continue;
            }
            $section = $DB->get_record('course_sections', [
                'id' => (int)$series->sectionid,
                'course' => $courseid,
            ], '*', IGNORE_MISSING);
            if (!$section) {
                continue;
            }

            $type = self::series_type((int)$series->id);
            $finished = (string)$series->status === 'finished';
            $label = self::series_label($series, $type, $finished);
            $changes = [];
            if ((string)$section->name !== $label) {
                $changes['name'] = $label;
            }
            $access = workshop_series::section_access_state((int)$series->id);
            if ((int)$section->visible !== (int)$access->visible) {
                $changes['visible'] = (int)$access->visible;
            }
            if ((string)($section->availability ?? '') !== (string)($access->availability ?? '')) {
                $changes['availability'] = $access->availability;
            }
            if ($changes) {
                course_update_section($courseid, $section, $changes);
            }

            self::rename_series_subsections((int)$series->id, $courseid);

            $entry = (object)[
                'sectionid' => (int)$section->id,
                'datefrom' => (int)$series->datefrom,
                'seriesid' => (int)$series->id,
            ];
            if ($finished) {
                $history[] = $entry;
            } else if ($type === 'typea') {
                $activea[] = $entry;
            } else if ($type === 'typeb') {
                $activeb[] = $entry;
            } else {
                $activeother[] = $entry;
            }
        }

        $sort = static function(array &$entries): void {
            usort($entries, static function($a, $b): int {
                if ($a->datefrom !== $b->datefrom) {
                    return $b->datefrom <=> $a->datefrom;
                }
                return $b->seriesid <=> $a->seriesid;
            });
        };
        $sort($activea);
        $sort($activeb);
        $sort($activeother);
        $sort($history);

        $orderedsectionids = array_map(static fn($e) => $e->sectionid, array_merge($activea, $activeb, $activeother));
        $autosectionid = self::selfassessment_section_id($courseid);
        if ($autosectionid > 0) {
            $orderedsectionids[] = $autosectionid;
        }
        foreach ($history as $entry) {
            $orderedsectionids[] = $entry->sectionid;
        }

        // Keep unrelated course content untouched and group only HEE-owned
        // sections at the end, in the agreed A -> B -> autoevaluation -> history order.
        foreach (array_values(array_unique(array_filter($orderedsectionids))) as $sectionid) {
            $current = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid], 'id,section', IGNORE_MISSING);
            if (!$current || (int)$current->section === 0) {
                continue;
            }
            $maxsection = self::last_regular_section_number($courseid);
            if ((int)$current->section !== $maxsection && function_exists('move_section_to')) {
                move_section_to($course, (int)$current->section, $maxsection);
            }
        }
        rebuild_course_cache($courseid, true);
    }

    /**
     * Highest section number of a regular (non-delegated) section.
     *
     * Since Moodle 4.5, mod_subsection stores delegated sections with the
     * highest numbers and core keeps them last. Moving or creating a regular
     * section must therefore target the last *regular* number, never
     * MAX(section) of the whole course.
     */
    public static function last_regular_section_number(int $courseid): int {
        global $DB;
        $where = 'course = :courseid';
        if (array_key_exists('component', $DB->get_columns('course_sections'))) {
            $where .= ' AND component IS NULL';
        }
        return (int)$DB->get_field_sql('SELECT COALESCE(MAX(section), 0) FROM {course_sections} WHERE ' . $where,
            ['courseid' => $courseid]);
    }

    private static function series_label(\stdClass $series, string $type, bool $history): string {
        // The parent section is the Edición itself. Type A/B is represented by
        // its contents and management metadata, not by adding a second label to
        // the visible section name.
        $label = trim((string)$series->title);
        return $history ? 'Histórico · ' . $label : $label;
    }

    private static function rename_series_subsections(int $seriesid, int $courseid): void {
        global $DB;
        $series = $DB->get_record(workshop_series::TABLE, ['id' => $seriesid], '*', IGNORE_MISSING);
        if (!$series) {
            return;
        }
        if (!empty($series->calendarsectionid)) {
            self::rename_section($courseid, (int)$series->calendarsectionid, 'Calendario y resumen de seminarios');
        }
        $sql = "SELECT i.subsectionsectionid, i.sortorder, w.name
                  FROM {local_ga_series_items} i
                  JOIN {local_ga_workshops} w ON w.id = i.workshopid
                 WHERE i.seriesid = :seriesid
              ORDER BY i.sortorder ASC, i.id ASC";
        foreach ($DB->get_records_sql($sql, ['seriesid' => $seriesid]) as $item) {
            if ((int)$item->subsectionsectionid <= 0) {
                continue;
            }
            self::rename_section(
                $courseid,
                (int)$item->subsectionsectionid,
                workshop_series::subsection_title((int)$item->sortorder, (string)$item->name)
            );
        }
    }

    private static function rename_section(int $courseid, int $sectionid, string $name): void {
        global $DB;
        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid], '*', IGNORE_MISSING);
        if ($section && (string)$section->name !== $name) {
            course_update_section($courseid, $section, ['name' => $name]);
            // Keep the mod_subsection instance name aligned with its section.
            if ((string)($section->component ?? '') === 'mod_subsection' && (int)($section->itemid ?? 0) > 0) {
                $DB->set_field('subsection', 'name', $name, ['id' => (int)$section->itemid]);
            }
        }
    }

    /**
     * Resolve the configured self-assessment section. This also supports a quiz
     * created manually in Moodle and later selected in HEE.
     */
    private static function selfassessment_section_id(int $courseid): int {
        global $DB;
        if ($DB->get_manager()->table_exists(new \xmldb_table(grade_manager::SETTINGS_TABLE))) {
            $cmid = (int)$DB->get_field(grade_manager::SETTINGS_TABLE, 'selfassessmentcmid', ['courseid' => $courseid]);
            if ($cmid > 0) {
                $sectionid = (int)$DB->get_field('course_modules', 'section', [
                    'id' => $cmid,
                    'course' => $courseid,
                    'deletioninprogress' => 0,
                ]);
                if ($sectionid > 0) {
                    return $sectionid;
                }
            }
        }

        $sql = "SELECT cs.id
                  FROM {course_modules} cm
                  JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE cm.course = :courseid
                   AND cm.idnumber = :idnumber
                   AND cm.deletioninprogress = 0
              ORDER BY cm.id DESC";
        return (int)$DB->get_field_sql($sql, [
            'courseid' => $courseid,
            'idnumber' => self::SELFASSESSMENT_IDNUMBER,
        ], IGNORE_MULTIPLE);
    }
}
