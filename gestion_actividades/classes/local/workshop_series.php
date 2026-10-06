<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Groups workshop editions into a visible Moodle course section.
 *
 * One series = one "Edición de Talleres". Each workshop can be represented by a
 * real Moodle subsection (mod_subsection) when available. A generated calendar
 * is stored in the first subsection summary and refreshed from canonical data.
 */
class workshop_series {
    public const TABLE = 'local_ga_workshop_series';
    public const ITEMTABLE = 'local_ga_series_items';

    public static function ensure_schema(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))
                || !$DB->get_manager()->table_exists(new \xmldb_table(self::ITEMTABLE))) {
            throw new \coding_exception('El esquema de Ediciones de talleres no está instalado. Ejecuta la actualización de Moodle.');
        }
    }

    public static function save(\stdClass $data): int {
        global $DB;
        self::ensure_schema();
        $id = !empty($data->id) ? (int)$data->id : 0;
        $now = time();
        $record = (object)[
            'courseid' => (int)$data->courseid,
            'title' => trim((string)$data->title),
            'datefrom' => max(0, (int)$data->datefrom),
            'dateto' => max(0, (int)$data->dateto),
            'status' => !empty($data->status) && in_array($data->status, ['active', 'finished'], true) ? $data->status : 'active',
            'timemodified' => $now,
        ];
        if ($record->title === '') {
            throw new \RuntimeException('La edición de talleres necesita un título.');
        }
        if ($record->datefrom <= 0 || $record->dateto <= 0 || $record->dateto < $record->datefrom) {
            throw new \RuntimeException('Las fechas de la edición de talleres no son válidas.');
        }
        $oldstatus = '';
        if ($id > 0) {
            $old = self::get($id);
            $oldstatus = (string)$old->status;
            $existingitems = self::items($id);
            if ((int)$old->courseid !== $record->courseid && $existingitems) {
                throw new \RuntimeException('No se puede cambiar de curso una edición que ya contiene talleres.');
            }
            foreach ($existingitems as $existingitem) {
                self::assert_no_overlapping_series($id, (int)$existingitem->workshopid, $record->datefrom, $record->dateto);
            }
            $record->id = $id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record->sectionid = 0;
            $record->calendarcmid = 0;
            $record->calendarsectionid = 0;
            $record->timecreated = $now;
            $id = (int)$DB->insert_record(self::TABLE, $record);
        }
        self::ensure_course_structure($id);
        // Changing the status from the edit form must have exactly the same
        // effects as the Finalizar / Reabrir buttons.
        if ($record->status !== $oldstatus && ($oldstatus !== '' || $record->status === 'finished')) {
            self::apply_status_side_effects($id, $record->status === 'finished');
        }
        return $id;
    }

    public static function get(int $id): \stdClass {
        global $DB;
        self::ensure_schema();
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    public static function list_for_course(int $courseid = 0): array {
        global $DB;
        self::ensure_schema();
        $params = $courseid > 0 ? ['courseid' => $courseid] : null;
        return $DB->get_records(self::TABLE, $params, 'datefrom DESC, id DESC');
    }

    public static function options(int $courseid = 0): array {
        $out = [0 => 'Sin edición de talleres'];
        foreach (self::list_for_course($courseid) as $series) {
            $out[(int)$series->id] = $series->title . ' · ' . userdate((int)$series->datefrom, '%d/%m/%Y') . '–' . userdate((int)$series->dateto, '%d/%m/%Y');
        }
        return $out;
    }

    public static function items(int $seriesid): array {
        global $DB;
        self::ensure_schema();
        $sql = "SELECT i.*, w.code, w.name, w.hours, w.workshoptype
                  FROM {" . self::ITEMTABLE . "} i
                  JOIN {local_ga_workshops} w ON w.id = i.workshopid
                 WHERE i.seriesid = :seriesid
              ORDER BY i.sortorder ASC, i.id ASC";
        return $DB->get_records_sql($sql, ['seriesid' => $seriesid]);
    }

    public static function item_for_workshop(int $workshopid, int $seriesid = 0): ?\stdClass {
        global $DB;
        self::ensure_schema();
        if ($seriesid > 0) {
            $item = $DB->get_record(self::ITEMTABLE, [
                'workshopid' => $workshopid,
                'seriesid' => $seriesid,
            ], '*', IGNORE_MISSING);
            return $item ?: null;
        }

        $sql = "SELECT i.*
                  FROM {" . self::ITEMTABLE . "} i
                  JOIN {" . self::TABLE . "} s ON s.id = i.seriesid
                 WHERE i.workshopid = :workshopid
              ORDER BY s.datefrom DESC, s.id DESC, i.id DESC";
        $records = $DB->get_records_sql($sql, ['workshopid' => $workshopid], 0, 1);
        return $records ? reset($records) : null;
    }

    public static function attach_workshop(int $seriesid, int $workshopid, int $sortorder = 0, int $notescmid = 0, int $sessionenddate = 0, int $editionid = 0): void {
        global $DB;
        self::ensure_schema();
        $series = self::get($seriesid);
        $workshop = manager::get_workshop($workshopid);
        if ((int)$series->courseid !== (int)$workshop->courseid) {
            throw new \RuntimeException('La edición y el taller deben pertenecer al mismo curso.');
        }
        if ($sortorder <= 0) {
            $sortorder = 1 + (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) FROM {' . self::ITEMTABLE . '} WHERE seriesid = :seriesid', ['seriesid' => $seriesid]);
        }
        // A seminar outside the Edición's range widens the Edición instead of
        // being rejected (see extend_to_cover()).
        $sessionstart = $sessionenddate;
        if ($editionid > 0) {
            $sessionstart = (int)($DB->get_field('local_ga_workshop_editions', 'sessiondate', ['id' => $editionid]) ?: $sessionenddate);
        }
        if ($sessionenddate > 0 || $sessionstart > 0) {
            self::extend_to_cover($seriesid, $sessionstart, $sessionenddate, $workshopid);
            $series = self::get($seriesid);
        }
        self::assert_no_overlapping_series($seriesid, $workshopid, (int)$series->datefrom, (int)$series->dateto);
        if ($editionid > 0) {
            $edition = manager::get_workshop_edition($editionid);
            if ((int)$edition->workshopid !== $workshopid) {
                throw new \RuntimeException('La edición concreta no pertenece al taller indicado.');
            }
            $columns = $DB->get_columns('local_ga_workshop_editions');
            if (isset($columns['seriesid'])) {
                $DB->set_field('local_ga_workshop_editions', 'seriesid', $seriesid, ['id' => $editionid]);
            }
        }
        $existing = $DB->get_record(self::ITEMTABLE, ['seriesid' => $seriesid, 'workshopid' => $workshopid], '*', IGNORE_MISSING);
        $now = time();
        if ($existing) {
            $existing->sortorder = $sortorder;
            if ($notescmid > 0) {
                $existing->notescmid = $notescmid;
            }
            if ($sessionenddate > 0) {
                $existing->sessionenddate = $sessionenddate;
            }
            $existing->timemodified = $now;
            $DB->update_record(self::ITEMTABLE, $existing);
        } else {
            $DB->insert_record(self::ITEMTABLE, (object)[
                'seriesid' => $seriesid,
                'workshopid' => $workshopid,
                'sortorder' => $sortorder,
                'sessionenddate' => max(0, $sessionenddate),
                'subsectioncmid' => 0,
                'subsectionsectionid' => 0,
                'notescmid' => max(0, $notescmid),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        self::ensure_course_structure($seriesid);
    }

    /**
     * Widen an Edición de talleres so that [$start, $end] fits inside it.
     *
     * Used when a seminar's date/time is edited (or added) outside the range of
     * its Edición: instead of rejecting the change, the Edición is extended so
     * nothing is hidden or mis-assigned by mistake. Status is not changed.
     * Returns null when no change was needed, otherwise old/new ranges.
     *
     * @throws \RuntimeException if the wider range would overlap another
     *         Edición that contains one of the same base seminars.
     */
    public static function extend_to_cover(int $seriesid, int $start, int $end, int $extraworkshopid = 0): ?\stdClass {
        global $DB;
        self::ensure_schema();
        $series = self::get($seriesid);
        $oldfrom = (int)$series->datefrom;
        $oldto = (int)$series->dateto;
        $newfrom = ($start > 0 && ($oldfrom <= 0 || $start < $oldfrom)) ? $start : $oldfrom;
        $newto = ($end > 0 && $end > $oldto) ? $end : $oldto;
        if ($newfrom === $oldfrom && $newto === $oldto) {
            return null;
        }
        $workshopids = [];
        foreach (self::items($seriesid) as $item) {
            $workshopids[(int)$item->workshopid] = (int)$item->workshopid;
        }
        if ($extraworkshopid > 0) {
            $workshopids[$extraworkshopid] = $extraworkshopid;
        }
        foreach ($workshopids as $workshopid) {
            self::assert_no_overlapping_series($seriesid, $workshopid, $newfrom, $newto);
        }
        $DB->update_record(self::TABLE, (object)[
            'id' => $seriesid,
            'datefrom' => $newfrom,
            'dateto' => $newto,
            'timemodified' => time(),
        ]);
        return (object)[
            'seriesid' => $seriesid,
            'title' => (string)$series->title,
            'oldfrom' => $oldfrom,
            'oldto' => $oldto,
            'datefrom' => $newfrom,
            'dateto' => $newto,
        ];
    }

    /**
     * Human message for an extension returned by extend_to_cover().
     */
    public static function extension_message(?\stdClass $extension): string {
        if (!$extension) {
            return '';
        }
        $format = get_string('strftimedatetimeshort', 'langconfig');
        return 'La Edición de talleres «' . $extension->title . '» se ha ampliado automáticamente para incluir el taller: '
            . userdate($extension->datefrom, $format) . ' – ' . userdate($extension->dateto, $format) . '.';
    }

    private static function assert_no_overlapping_series(int $seriesid, int $workshopid, int $datefrom, int $dateto): void {
        global $DB;
        $sql = "SELECT s.id, s.title
                  FROM {" . self::TABLE . "} s
                  JOIN {" . self::ITEMTABLE . "} i ON i.seriesid = s.id
                 WHERE i.workshopid = :workshopid
                   AND s.id <> :seriesid
                   AND s.datefrom <= :dateto
                   AND s.dateto >= :datefrom";
        $conflict = $DB->get_record_sql($sql, [
            'workshopid' => $workshopid,
            'seriesid' => $seriesid,
            'datefrom' => $datefrom,
            'dateto' => $dateto,
        ], IGNORE_MULTIPLE);
        if ($conflict) {
            throw new \RuntimeException('Este taller ya pertenece a otra Edición de talleres cuyo rango de fechas se solapa: ' . $conflict->title . '.');
        }
    }

    /**
     * Remove an Edición de talleres from active management. Only allowed
     * while its editions have no academic evidence (enrolments, hours,
     * certificates, reflections, submissions, transfers); otherwise the
     * manager must use "Finalizar y ocultar", which keeps everything. When
     * allowed, linked editions are archived/unlinked, the Moodle parent
     * section is hidden (never deleted) and the series container/items are
     * removed. No Moodle activity or student record is deleted.
     */
    public static function delete_series(int $seriesid): void {
        global $DB, $CFG;
        self::ensure_schema();
        $series = self::get($seriesid);
        require_once($CFG->dirroot . '/course/lib.php');

        $editionids = self::editions_have_seriesid()
            ? $DB->get_fieldset_select('local_ga_workshop_editions', 'id', 'seriesid = :seriesid', ['seriesid' => $seriesid])
            : [];
        $evidence = self::academic_evidence_summary(array_map('intval', $editionids));
        if ($evidence) {
            throw new \RuntimeException(
                'No se puede borrar esta Edición porque ya contiene datos académicos (' . implode(', ', $evidence) . '). '
                . 'Usa «Finalizar y ocultar»: conserva inscripciones, asistencia, reflexiones, certificados y horas, '
                . 'y la mueve al histórico.');
        }
        $columns = $DB->get_columns('local_ga_workshop_editions');
        foreach ($editionids as $editionid) {
            $update = (object)['id' => (int)$editionid, 'timemodified' => time()];
            if (isset($columns['seriesid'])) {
                $update->seriesid = 0;
            }
            if (isset($columns['archived'])) {
                $update->archived = 1;
            }
            if (isset($columns['status'])) {
                $update->status = 'archived';
            }
            $DB->update_record('local_ga_workshop_editions', $update);
        }
        manager::invalidate_teacher_block_cache([], array_map('intval', $editionids));

        if (!empty($series->sectionid)) {
            $section = $DB->get_record('course_sections', [
                'id' => (int)$series->sectionid,
                'course' => (int)$series->courseid,
            ], '*', IGNORE_MISSING);
            if ($section) {
                course_update_section((int)$series->courseid, $section, [
                    'name' => 'Eliminada · ' . (string)$series->title,
                    'summary' => '',
                    'summaryformat' => FORMAT_HTML,
                    'visible' => 0,
                    'availability' => null,
                ]);
            }
        }

        $DB->delete_records(self::ITEMTABLE, ['seriesid' => $seriesid]);
        $DB->delete_records(self::TABLE, ['id' => $seriesid]);

        // Remove stale legacy cards that older builds may have generated for
        // these seminars. Archived editions are excluded by the legacy renderer.
        manager::sync_workshop_section_summary((int)$series->courseid, 'typea');
        manager::sync_workshop_section_summary((int)$series->courseid, 'typeb');
        rebuild_course_cache((int)$series->courseid, true);
    }

    /**
     * Academic evidence attached to the given editions, as human-readable
     * labels with counts. An empty array means the editions are empty and the
     * series can be withdrawn without losing anything a student produced.
     *
     * @param int[] $editionids
     * @return string[]
     */
    public static function academic_evidence_summary(array $editionids): array {
        global $DB;
        $editionids = array_values(array_unique(array_filter(array_map('intval', $editionids))));
        if (!$editionids) {
            return [];
        }
        $checks = [
            'local_ga_edition_enrolments' => 'inscripciones',
            'local_ga_hour_history' => 'horas registradas',
            'local_ga_certificates' => 'certificados',
            'local_ga_typeb_reflections' => 'reflexiones',
            'local_ga_task_submissions' => 'entregas',
            'local_ga_typeb_transfers' => 'traspasos de horas',
        ];
        $dbman = $DB->get_manager();
        $out = [];
        [$insql, $params] = $DB->get_in_or_equal($editionids, SQL_PARAMS_NAMED, 'ev');
        foreach ($checks as $table => $label) {
            if (!$dbman->table_exists(new \xmldb_table($table))) {
                continue;
            }
            $count = $DB->count_records_select($table, "editionid $insql", $params);
            if ($count > 0) {
                $out[] = $count . ' ' . $label;
            }
        }
        return $out;
    }

    public static function next_sortorder(int $seriesid): int {
        global $DB;
        self::ensure_schema();
        return 1 + (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) FROM {' . self::ITEMTABLE . '} WHERE seriesid = :seriesid', ['seriesid' => $seriesid]);
    }

    public static function series_for_workshop(int $workshopid): ?\stdClass {
        $item = self::item_for_workshop($workshopid);
        return $item ? self::get((int)$item->seriesid) : null;
    }

    /**
     * Resolve the series that contains a concrete workshop edition by date.
     */
    public static function series_for_edition(int $editionid): ?\stdClass {
        global $DB;
        self::ensure_schema();
        $edition = manager::get_workshop_edition($editionid);
        if (property_exists($edition, 'seriesid') && (int)$edition->seriesid > 0) {
            $series = $DB->get_record(self::TABLE, ['id' => (int)$edition->seriesid], '*', IGNORE_MISSING);
            if ($series) {
                return $series;
            }
        }
        // Compatibility fallback for historical records created before seriesid.
        $sql = "SELECT s.*
                  FROM {" . self::TABLE . "} s
                  JOIN {" . self::ITEMTABLE . "} i ON i.seriesid = s.id
                 WHERE i.workshopid = :workshopid
                   AND s.datefrom <= :sessionfrom
                   AND s.dateto >= :sessionto
              ORDER BY s.datefrom DESC, s.id DESC";
        $records = $DB->get_records_sql($sql, [
            'workshopid' => (int)$edition->workshopid,
            'sessionfrom' => (int)$edition->sessiondate,
            'sessionto' => (int)$edition->sessiondate,
        ], 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * A workshop base may be reused by historical editions. Refresh every
     * series that references it instead of arbitrarily picking one.
     */
    public static function refresh_for_workshop(int $workshopid): void {
        global $DB;
        self::ensure_schema();
        $seriesids = $DB->get_records_sql(
            'SELECT DISTINCT seriesid AS id FROM {' . self::ITEMTABLE . '} WHERE workshopid = :workshopid',
            ['workshopid' => $workshopid]
        );
        foreach ($seriesids as $row) {
            self::ensure_course_structure((int)$row->id);
        }
    }

    public static function set_finished(int $seriesid, bool $finished): void {
        global $DB;
        self::ensure_schema();
        $DB->update_record(self::TABLE, (object)[
            'id' => $seriesid,
            'status' => $finished ? 'finished' : 'active',
            'timemodified' => time(),
        ]);
        self::ensure_course_structure($seriesid);
        self::apply_status_side_effects($seriesid, $finished);
    }

    /**
     * Effects of finishing/reopening a series: teacher block counters and,
     * when finishing, closing ordinary Type B reflection submissions.
     */
    private static function apply_status_side_effects(int $seriesid, bool $finished): void {
        global $DB;
        // Closing/reopening the Edición de talleres moves its workshops between
        // "vigentes" and "finalizados" in the Profesor HEE block immediately.
        $editionids = self::editions_have_seriesid()
            ? $DB->get_fieldset_select('local_ga_workshop_editions', 'id', 'seriesid = :seriesid', ['seriesid' => $seriesid])
            : [];
        manager::invalidate_teacher_block_cache([], $editionids);
        if ($finished) {
            foreach ($editionids as $editionid) {
                try {
                    typeb_certificate_policy::close_reflection_submissions((int)$editionid);
                } catch (\Throwable $e) {
                    debugging('No se pudo cerrar la entrega de reflexiones: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * Visibility/availability for the parent section of a seminar series.
     *
     * Finished series stay hidden from students. The only exception is a
     * finished Type B series with an active individual reflection extension:
     * Moodle cannot open an activity whose parent section is hidden, so the
     * parent section is made technically visible but restricted to the
     * edition group and to the extension deadline. This keeps the finished
     * series unavailable to the rest of the course while the student can use
     * Moodle's native assignment extension.
     */
    public static function section_access_state(int $seriesid): \stdClass {
        global $DB;
        $series = self::get($seriesid);
        if ((string)$series->status !== 'finished') {
            return (object)['visible' => 1, 'availability' => null];
        }
        if (!self::editions_have_seriesid()) {
            return (object)['visible' => 0, 'availability' => null];
        }

        $now = time();
        $activeenrolment = manager::active_enrolment_sql('ee');
        $sql = "SELECT e.groupid, MAX(auf.extensionduedate) AS untiltime
                  FROM {local_ga_workshop_editions} e
                  JOIN {course_modules} cm ON cm.id = e.requiredcmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :assignmod
                  JOIN {assign_user_flags} auf ON auf.assignment = cm.instance
                  JOIN {local_ga_edition_enrolments} ee
                    ON ee.editionid = e.id
                   AND ee.userid = auf.userid
                   AND " . $activeenrolment . "
                 WHERE e.seriesid = :seriesid
                   AND e.groupid > 0
                   AND auf.extensionduedate > :now
              GROUP BY e.groupid";
        $rows = $DB->get_records_sql($sql, [
            'assignmod' => 'assign',
            'seriesid' => $seriesid,
            'now' => $now,
        ]);
        if (!$rows) {
            return (object)['visible' => 0, 'availability' => null];
        }

        $branches = [];
        foreach ($rows as $row) {
            $groupid = (int)$row->groupid;
            $until = (int)$row->untiltime;
            if ($groupid <= 0 || $until <= $now) {
                continue;
            }
            $branches[] = [
                'op' => '&',
                'c' => [
                    ['type' => 'group', 'id' => $groupid],
                    ['type' => 'date', 'd' => '<', 't' => $until],
                ],
                'showc' => [false, false],
            ];
        }
        if (!$branches) {
            return (object)['visible' => 0, 'availability' => null];
        }

        $availability = json_encode([
            'op' => '|',
            'c' => $branches,
            'showc' => array_fill(0, count($branches), false),
        ], JSON_UNESCAPED_SLASHES);

        return (object)[
            'visible' => 1,
            'availability' => $availability ?: null,
        ];
    }

    /**
     * Recalculate only the parent-section access after granting/revoking a
     * late Type B reflection permission.
     */
    public static function refresh_section_access(int $seriesid): void {
        global $DB, $CFG;
        $series = self::get($seriesid);
        if (empty($series->sectionid)) {
            return;
        }
        $section = $DB->get_record('course_sections', [
            'id' => (int)$series->sectionid,
            'course' => (int)$series->courseid,
        ], '*', IGNORE_MISSING);
        if (!$section) {
            return;
        }
        $access = self::section_access_state($seriesid);
        $changes = [];
        if ((int)$section->visible !== (int)$access->visible) {
            $changes['visible'] = (int)$access->visible;
        }
        if ((string)($section->availability ?? '') !== (string)($access->availability ?? '')) {
            $changes['availability'] = $access->availability;
        }
        if ($changes) {
            require_once($CFG->dirroot . '/course/lib.php');
            course_update_section((int)$series->courseid, $section, $changes);
            rebuild_course_cache((int)$series->courseid, true);
        }
    }

    public static function ensure_course_structure(int $seriesid): void {
        global $DB, $CFG;
        self::ensure_schema();
        require_once($CFG->dirroot . '/course/lib.php');
        $series = self::get($seriesid);
        $course = $DB->get_record('course', ['id' => $series->courseid], '*', MUST_EXIST);

        $section = null;
        if (!empty($series->sectionid)) {
            $section = $DB->get_record('course_sections', ['id' => $series->sectionid, 'course' => $series->courseid], '*', IGNORE_MISSING);
        }
        if (!$section) {
            // Position 0 = append as last regular section. Do NOT pass
            // $skipcheck = true: since Moodle 4.4 that makes core insert a
            // section numbered 0, which collides with the course's section 0.
            $section = course_create_section($course, 0);
            $series->sectionid = (int)$section->id;
            $DB->set_field(self::TABLE, 'sectionid', $series->sectionid, ['id' => $seriesid]);
        }

        $access = self::section_access_state($seriesid);
        $DB->update_record('course_sections', (object)[
            'id' => (int)$section->id,
            'name' => $series->title,
            'visible' => $access->visible,
            'availability' => $access->availability,
            'timemodified' => time(),
        ]);

        if (self::subsections_supported()) {
            [$calendarcmid, $calendarsectionid] = self::ensure_subsection($course, (int)$section->section, (int)$series->calendarcmid, 'Calendario y resumen de talleres');
            if ($calendarcmid !== (int)$series->calendarcmid || $calendarsectionid !== (int)$series->calendarsectionid) {
                $DB->update_record(self::TABLE, (object)[
                    'id' => $seriesid,
                    'calendarcmid' => $calendarcmid,
                    'calendarsectionid' => $calendarsectionid,
                    'timemodified' => time(),
                ]);
            }
            self::update_calendar_section($calendarsectionid, $seriesid);
            // Calendar first, then TALLER 01, TALLER 02... (items() is sorted).
            $ordered = [$calendarcmid];

            foreach (self::items($seriesid) as $item) {
                $title = self::subsection_title((int)$item->sortorder, (string)$item->name);
                [$cmid, $sectionid] = self::ensure_subsection($course, (int)$section->section, (int)$item->subsectioncmid, $title);
                if ($cmid !== (int)$item->subsectioncmid || $sectionid !== (int)$item->subsectionsectionid) {
                    $DB->update_record(self::ITEMTABLE, (object)[
                        'id' => (int)$item->id,
                        'subsectioncmid' => $cmid,
                        'subsectionsectionid' => $sectionid,
                        'timemodified' => time(),
                    ]);
                }
                self::configure_workshop_subsection($sectionid, $series, $item);
                self::move_workshop_modules((int)$item->workshopid, $sectionid, (int)$item->notescmid, $series);
                $ordered[] = $cmid;
            }
            self::order_parent_section((int)$section->id, $ordered);
        } else {
            $DB->update_record('course_sections', (object)[
                'id' => (int)$section->id,
                'summary' => self::render_calendar_html($seriesid),
                'summaryformat' => FORMAT_HTML,
                'timemodified' => time(),
            ]);
        }
        // Older builds rendered the same workshops in a parallel
        // TALLERES TIPO A/B section. Rebuild that legacy surface so modern
        // series-owned seminars disappear from it.
        manager::sync_workshop_section_summary((int)$course->id, 'typea');
        manager::sync_workshop_section_summary((int)$course->id, 'typeb');
        rebuild_course_cache((int)$course->id, true);
    }

    /**
     * Resolve the edition of a workshop that belongs to this series date range.
     */
    private static function edition_for_series_item(\stdClass $series, \stdClass $item): ?\stdClass {
        global $DB;
        // Explicit link first (seriesid, since 2026100513); dates are only a
        // fallback for historical editions that could not be linked.
        if (self::editions_have_seriesid()) {
            $linked = $DB->get_records('local_ga_workshop_editions', [
                'workshopid' => (int)$item->workshopid,
                'seriesid' => (int)$series->id,
            ], 'sessiondate DESC, id DESC', '*', 0, 1);
            if ($linked) {
                return reset($linked);
            }
        }
        $params = [
            'workshopid' => (int)$item->workshopid,
            'datefrom' => (int)$series->datefrom,
            'dateto' => (int)$series->dateto,
        ];
        $sql = "SELECT *
                  FROM {local_ga_workshop_editions}
                 WHERE workshopid = :workshopid
                   AND sessiondate >= :datefrom
                   AND sessiondate <= :dateto" . (self::editions_have_seriesid() ? "
                   AND (seriesid = 0 OR seriesid IS NULL)" : "") . "
              ORDER BY sessiondate DESC, id DESC";
        $records = $DB->get_records_sql($sql, $params, 0, 1);
        return $records ? reset($records) : null;
    }

    private static function editions_have_seriesid(): bool {
        global $DB;
        static $has = null;
        if ($has === null) {
            $has = array_key_exists('seriesid', $DB->get_columns('local_ga_workshop_editions'));
        }
        return $has;
    }

    public static function render_calendar_html(int $seriesid): string {
        $series = self::get($seriesid);
        $items = self::items($seriesid);
        $rows = [];
        foreach ($items as $item) {
            $edition = self::edition_for_series_item($series, $item);
            if (!$edition) {
                continue;
            }
            $teachers = manager::get_edition_teachers((int)$edition->id);
            $teachernames = [];
            foreach ($teachers as $teacher) {
                $teachernames[] = fullname($teacher);
            }
            $date = !empty($edition->sessiondate) ? userdate((int)$edition->sessiondate, '%d/%m/%Y') : 'Pendiente';
            $starttime = !empty($edition->sessiondate) ? userdate((int)$edition->sessiondate, '%H:%M') : '';
            $endtime = !empty($item->sessionenddate) ? userdate((int)$item->sessionenddate, '%H:%M') : '';
            $time = $starttime;
            if ($starttime !== '' && $endtime !== '') {
                $time .= '–' . $endtime;
            }
            $deadline = !empty($edition->enrolenddate) ? userdate((int)$edition->enrolenddate, '%d/%m/%Y %H:%M') : '-';
            // This HTML is shared by every course user, so the link must not
            // mutate anything: it opens the seminar page, which shows the
            // user's own status and the sesskey-protected "Inscribirme".
            // The label is constant because this HTML is cached in the section
            // summary and must not go stale when the enrolment deadline passes.
            $viewurl = new \moodle_url('/local/gestion_actividades/workshop_view.php', [
                'id' => (int)$item->workshopid,
                'editionid' => (int)$edition->id,
            ]);
            $access = '<span class="local-ga-card-actions" data-editionid="' . (int)$edition->id . '">'
                . '<a class="btn btn-sm btn-primary local-ga-enrol-status" data-editionid="' . (int)$edition->id . '" '
                . 'href="' . $viewurl->out(true) . '">Inscribirme</a> '
                . '<a class="btn btn-sm btn-outline-secondary" href="' . $viewurl->out(true) . '">Ver taller</a>'
                . '</span>';
            $rows[] = '<tr>' .
                '<td style="padding:10px;white-space:nowrap;font-weight:700;border-bottom:1px solid #edf0ea">' . sprintf('%02d', (int)$item->sortorder) . '</td>' .
                '<td style="padding:10px;border-bottom:1px solid #edf0ea"><strong>' . s($item->name) . '</strong></td>' .
                '<td style="padding:10px;white-space:nowrap;border-bottom:1px solid #edf0ea">' . s($date) . '</td>' .
                '<td style="padding:10px;white-space:nowrap;border-bottom:1px solid #edf0ea">' . s($time !== '' ? $time : '-') . '</td>' .
                '<td style="padding:10px;white-space:nowrap;text-align:center;border-bottom:1px solid #edf0ea">' . format_float((float)$item->hours, 2, true) . ' h</td>' .
                '<td style="padding:10px;border-bottom:1px solid #edf0ea">' . s($teachernames ? implode(', ', $teachernames) : '-') . '</td>' .
                '<td style="padding:10px;white-space:nowrap;text-align:center;border-bottom:1px solid #edf0ea">' . (int)$edition->places . '</td>' .
                '<td style="padding:10px;white-space:nowrap;border-bottom:1px solid #edf0ea">' . s($deadline) . '</td>' .
                '<td style="padding:10px;white-space:nowrap;border-bottom:1px solid #edf0ea">' . $access . '</td>' .
                '</tr>';
        }
        $range = userdate((int)$series->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y');
        $body = $rows ? implode('', $rows) : '<tr><td colspan="9" style="padding:16px;text-align:center;color:#687064">No hay talleres publicados en esta edición.</td></tr>';
        return '<div class="ga-workshop-calendar" style="max-width:1180px;margin:0 auto">' .
            '<div style="margin-bottom:14px"><h3 style="margin:0 0 3px">' . s($series->title) . '</h3><div style="color:#64705e">' . s($range) . '</div></div>' .
            '<div style="overflow-x:auto;border:1px solid #d7ddd2;border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03)">' .
            '<table style="width:100%;border-collapse:collapse;min-width:900px">' .
            '<thead><tr style="background:#f4f7f2;color:#29451f">' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:left">#</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:left">Taller</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:left">Fecha</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:left">Horario</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:center">Horas</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:left">Profesorado</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:center">Plazas</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:left">Cierre inscripción</th>' .
            '<th style="padding:10px;border-bottom:1px solid #d7ddd2;text-align:left">Acceso</th>' .
            '</tr></thead><tbody>' . $body . '</tbody></table></div></div>';
    }

    /**
     * Canonical title of a workshop subsection inside its Edición. Shared with
     * course_layout so both code paths never rename it differently.
     */
    public static function subsection_title(int $sortorder, string $name): string {
        return sprintf('TALLER %02d: %s', $sortorder, trim($name));
    }

    /**
     * Put the given course modules (calendar + workshop subsections) at the
     * top of the parent section in the given order. Other modules a teacher
     * added to the parent section keep their relative order after them.
     * Only the order of the sequence changes; no module is added or removed.
     */
    private static function order_parent_section(int $sectionid, array $orderedcmids): void {
        global $DB;
        $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id, course, sequence', IGNORE_MISSING);
        if (!$section) {
            return;
        }
        $current = array_values(array_filter(array_map('intval', explode(',', (string)$section->sequence))));
        $wanted = array_values(array_filter(array_map('intval', $orderedcmids), static function(int $cmid) use ($current): bool {
            return in_array($cmid, $current, true);
        }));
        $wanted = array_values(array_unique($wanted));
        $rest = array_values(array_filter($current, static function(int $cmid) use ($wanted): bool {
            return !in_array($cmid, $wanted, true);
        }));
        $sequence = array_merge($wanted, $rest);
        if ($sequence !== $current) {
            $DB->set_field('course_sections', 'sequence', implode(',', $sequence), ['id' => $sectionid]);
            rebuild_course_cache((int)$section->course, true);
        }
    }

    private static function subsections_supported(): bool {
        global $DB, $CFG;
        return $DB->record_exists('modules', ['name' => 'subsection'])
            && $DB->get_manager()->table_exists(new \xmldb_table('subsection'))
            && is_readable($CFG->dirroot . '/mod/subsection/lib.php');
    }

    private static function ensure_subsection(\stdClass $course, int $parentsectionnum, int $existingcmid, string $name): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/subsection/lib.php');

        if ($existingcmid > 0) {
            $cm = get_coursemodule_from_id('subsection', $existingcmid, (int)$course->id, false, IGNORE_MISSING);
            if ($cm) {
                $instance = $DB->get_record('subsection', ['id' => $cm->instance], '*', IGNORE_MISSING);
                $delegated = $instance ? $DB->get_record('course_sections', ['component' => 'mod_subsection', 'itemid' => $instance->id], '*', IGNORE_MISSING) : null;
                if ($instance && $delegated) {
                    if ($delegated->name !== $name) {
                        $DB->update_record('course_sections', (object)['id' => $delegated->id, 'name' => $name, 'timemodified' => time()]);
                        $DB->set_field('subsection', 'name', $name, ['id' => $instance->id]);
                    }
                    return [(int)$cm->id, (int)$delegated->id];
                }
            }
        }

        $module = $DB->get_record('modules', ['name' => 'subsection'], '*', MUST_EXIST);
        $data = (object)[
            'course' => (int)$course->id,
            'module' => (int)$module->id,
            'modulename' => 'subsection',
            'add' => 'subsection',
            'section' => $parentsectionnum,
            'name' => $name,
            'visible' => 1,
            'groupmode' => 0,
            'groupingid' => 0,
            'completion' => 0,
            'availability' => null,
            'showdescription' => 0,
        ];
        $cm = add_moduleinfo($data, $course);
        $cmid = (int)($cm->coursemodule ?? $cm->id ?? 0);
        if ($cmid <= 0) {
            throw new \RuntimeException('Moodle no ha podido crear la subsección.');
        }
        $cmrecord = get_coursemodule_from_id('subsection', $cmid, (int)$course->id, false, MUST_EXIST);
        $delegated = $DB->get_record('course_sections', ['component' => 'mod_subsection', 'itemid' => (int)$cmrecord->instance], '*', MUST_EXIST);
        return [$cmid, (int)$delegated->id];
    }

    /**
     * Configure the Moodle subsection itself as the visible workshop card.
     * There is deliberately no duplicate HTML workshop card in its summary:
     * the real Moodle activities live directly inside the delegated section.
     */
    private static function configure_workshop_subsection(int $sectionid, \stdClass $series, \stdClass $item): void {
        global $DB;
        if ($sectionid <= 0) {
            return;
        }
        $edition = self::edition_for_series_item($series, $item);
        $availability = null;
        if ($edition && !empty($edition->groupid)) {
            $availability = json_encode([
                'op' => '&',
                'c' => [
                    ['type' => 'group', 'id' => (int)$edition->groupid],
                ],
                'showc' => [true],
            ], JSON_UNESCAPED_SLASHES);
        }
        $DB->update_record('course_sections', (object)[
            'id' => $sectionid,
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'availability' => $availability ?: null,
            'timemodified' => time(),
        ]);
    }

    private static function update_calendar_section(int $sectionid, int $seriesid): void {
        global $DB;
        if ($sectionid <= 0) {
            return;
        }
        $DB->update_record('course_sections', (object)[
            'id' => $sectionid,
            'summary' => self::render_calendar_html($seriesid),
            'summaryformat' => FORMAT_HTML,
            'timemodified' => time(),
        ]);
    }

    /**
     * Move only the Moodle modules that belong to the edition represented by
     * this series. Old/new editions reusing the same workshop base stay put.
     */
    private static function move_workshop_modules(int $workshopid, int $targetsectionid, int $notescmid = 0, ?\stdClass $series = null): void {
        global $DB, $CFG;
        if ($targetsectionid <= 0) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        $target = $DB->get_record('course_sections', ['id' => $targetsectionid], '*', IGNORE_MISSING);
        if (!$target) {
            return;
        }

        $editions = manager::list_workshop_editions($workshopid);
        $cmids = [];
        foreach ($editions as $edition) {
            if ($series) {
                $editionseries = (int)($edition->seriesid ?? 0);
                if ($editionseries > 0 && $editionseries !== (int)$series->id) {
                    continue;
                }
                if ($editionseries === 0 && ((int)$edition->sessiondate < (int)$series->datefrom
                        || (int)$edition->sessiondate > (int)$series->dateto)) {
                    continue;
                }
            }
            foreach (['attendancecmid', 'requiredcmid', 'requiredassigncmid', 'requiredquizcmid', 'certificatecmid'] as $field) {
                if (!empty($edition->$field)) {
                    $cmids[(int)$edition->$field] = (int)$edition->$field;
                }
            }
        }
        if ($notescmid > 0) {
            $cmids[$notescmid] = $notescmid;
        }
        foreach ($cmids as $cmid) {
            $cm = get_coursemodule_from_id('', $cmid, (int)$target->course, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            if ((int)$cm->section === (int)$target->id) {
                // Older versions removed required activities from the section
                // sequence ("hard archive"), leaving them unreachable. If that
                // happened here, put the module back into its section.
                $sequence = array_filter(array_map('trim', explode(',', (string)$DB->get_field('course_sections', 'sequence', ['id' => (int)$target->id]))));
                if (!in_array((string)$cmid, $sequence, true)) {
                    $sequence[] = (string)$cmid;
                    $DB->set_field('course_sections', 'sequence', implode(',', $sequence), ['id' => (int)$target->id]);
                    rebuild_course_cache((int)$target->course, true);
                }
                continue;
            }
            if (function_exists('moveto_module')) {
                moveto_module($cm, $target, null);
            } else {
                course_add_cm_to_section((int)$target->course, $cmid, (int)$target->section);
            }
        }
    }
}
