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
        $dbman = $DB->get_manager();

        $table = new \xmldb_table(self::TABLE);
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('datefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('dateto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('calendarcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('calendarsectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }

        $items = new \xmldb_table(self::ITEMTABLE);
        if (!$dbman->table_exists($items)) {
            $items->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $items->add_field('seriesid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('sessionenddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('subsectioncmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('subsectionsectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('notescmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $items->add_index('seriesworkshop', XMLDB_INDEX_UNIQUE, ['seriesid', 'workshopid']);
            $items->add_index('seriesorder', XMLDB_INDEX_NOTUNIQUE, ['seriesid', 'sortorder']);
            $items->add_index('workshopid', XMLDB_INDEX_NOTUNIQUE, ['workshopid']);
            $dbman->create_table($items);
        } else {
            $endfield = new \xmldb_field('sessionenddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sortorder');
            if (!$dbman->field_exists($items, $endfield)) {
                $dbman->add_field($items, $endfield);
            }
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
        if ($id > 0) {
            $old = self::get($id);
            if ((int)$old->courseid !== $record->courseid && self::items($id)) {
                throw new \RuntimeException('No se puede cambiar de curso una edición que ya contiene talleres.');
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

    public static function attach_workshop(int $seriesid, int $workshopid, int $sortorder = 0, int $notescmid = 0, int $sessionenddate = 0): void {
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
        if ($sessionenddate > 0 && ($sessionenddate < (int)$series->datefrom || $sessionenddate > (int)$series->dateto)) {
            throw new \RuntimeException('La hora de fin del taller queda fuera de las fechas de la edición.');
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
        $sql = "SELECT s.*
                  FROM {" . self::TABLE . "} s
                  JOIN {" . self::ITEMTABLE . "} i ON i.seriesid = s.id
                 WHERE i.workshopid = :workshopid
                   AND s.datefrom <= :sessionfrom
                   AND s.dateto >= :sessionto
              ORDER BY s.datefrom DESC, s.id DESC";
        // Moodle DML does not accept the same named placeholder twice.
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
            $section = course_create_section($course, 0, true);
            $series->sectionid = (int)$section->id;
            $DB->set_field(self::TABLE, 'sectionid', $series->sectionid, ['id' => $seriesid]);
        }

        $visible = ($series->status === 'finished' || ((int)$series->dateto > 0 && (int)$series->dateto < time())) ? 0 : 1;
        $DB->update_record('course_sections', (object)[
            'id' => (int)$section->id,
            'name' => $series->title,
            'visible' => $visible,
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

            foreach (self::items($seriesid) as $item) {
                $title = sprintf('Taller %02d · %s', (int)$item->sortorder, $item->name);
                [$cmid, $sectionid] = self::ensure_subsection($course, (int)$section->section, (int)$item->subsectioncmid, $title);
                if ($cmid !== (int)$item->subsectioncmid || $sectionid !== (int)$item->subsectionsectionid) {
                    $DB->update_record(self::ITEMTABLE, (object)[
                        'id' => (int)$item->id,
                        'subsectioncmid' => $cmid,
                        'subsectionsectionid' => $sectionid,
                        'timemodified' => time(),
                    ]);
                }
                self::move_workshop_modules((int)$item->workshopid, $sectionid, (int)$item->notescmid, $series);
            }
        } else {
            $DB->update_record('course_sections', (object)[
                'id' => (int)$section->id,
                'summary' => self::render_calendar_html($seriesid),
                'summaryformat' => FORMAT_HTML,
                'timemodified' => time(),
            ]);
        }
        rebuild_course_cache((int)$course->id, true);
    }

    /**
     * Resolve the edition of a workshop that belongs to this series date range.
     */
    private static function edition_for_series_item(\stdClass $series, \stdClass $item): ?\stdClass {
        global $DB;
        $params = [
            'workshopid' => (int)$item->workshopid,
            'datefrom' => (int)$series->datefrom,
            'dateto' => (int)$series->dateto,
        ];
        $sql = "SELECT *
                  FROM {local_ga_workshop_editions}
                 WHERE workshopid = :workshopid
                   AND sessiondate >= :datefrom
                   AND sessiondate <= :dateto
              ORDER BY sessiondate DESC, id DESC";
        $records = $DB->get_records_sql($sql, $params, 0, 1);
        return $records ? reset($records) : null;
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
            $rows[] = '<tr>' .
                '<td style="padding:10px;white-space:nowrap;font-weight:700;border-bottom:1px solid #edf0ea">' . sprintf('%02d', (int)$item->sortorder) . '</td>' .
                '<td style="padding:10px;border-bottom:1px solid #edf0ea"><strong>' . s($item->name) . '</strong></td>' .
                '<td style="padding:10px;white-space:nowrap;border-bottom:1px solid #edf0ea">' . s($date) . '</td>' .
                '<td style="padding:10px;white-space:nowrap;border-bottom:1px solid #edf0ea">' . s($time !== '' ? $time : '-') . '</td>' .
                '<td style="padding:10px;white-space:nowrap;text-align:center;border-bottom:1px solid #edf0ea">' . format_float((float)$item->hours, 2, true) . ' h</td>' .
                '<td style="padding:10px;border-bottom:1px solid #edf0ea">' . s($teachernames ? implode(', ', $teachernames) : '-') . '</td>' .
                '<td style="padding:10px;white-space:nowrap;text-align:center;border-bottom:1px solid #edf0ea">' . (int)$edition->places . '</td>' .
                '<td style="padding:10px;white-space:nowrap;border-bottom:1px solid #edf0ea">' . s($deadline) . '</td>' .
                '</tr>';
        }
        $range = userdate((int)$series->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y');
        $body = $rows ? implode('', $rows) : '<tr><td colspan="8" style="padding:16px;text-align:center;color:#687064">No hay talleres publicados en esta edición.</td></tr>';
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
            '</tr></thead><tbody>' . $body . '</tbody></table></div></div>';
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
            if ($series && ((int)$edition->sessiondate < (int)$series->datefrom || (int)$edition->sessiondate > (int)$series->dateto)) {
                continue;
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
            if (!$cm || (int)$cm->section === (int)$target->id) {
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
