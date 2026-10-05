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

    public static function attach_workshop(int $seriesid, int $workshopid, int $sortorder = 0, int $notescmid = 0): void {
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
        $existing = $DB->get_record(self::ITEMTABLE, ['seriesid' => $seriesid, 'workshopid' => $workshopid], '*', IGNORE_MISSING);
        $now = time();
        if ($existing) {
            $existing->sortorder = $sortorder;
            if ($notescmid > 0) {
                $existing->notescmid = $notescmid;
            }
            $existing->timemodified = $now;
            $DB->update_record(self::ITEMTABLE, $existing);
        } else {
            $DB->insert_record(self::ITEMTABLE, (object)[
                'seriesid' => $seriesid,
                'workshopid' => $workshopid,
                'sortorder' => $sortorder,
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
        global $DB;
        self::ensure_schema();
        $item = $DB->get_record(self::ITEMTABLE, ['workshopid' => $workshopid], '*', IGNORE_MULTIPLE);
        return $item ? self::get((int)$item->seriesid) : null;
    }

    public static function refresh_for_workshop(int $workshopid): void {
        $series = self::series_for_workshop($workshopid);
        if ($series) {
            self::ensure_course_structure((int)$series->id);
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
                self::move_workshop_modules((int)$item->workshopid, $sectionid, (int)$item->notescmid);
            }
        } else {
            // Compatibility mode for Moodle versions/sites without mod_subsection.
            $DB->update_record('course_sections', (object)[
                'id' => (int)$section->id,
                'summary' => self::render_calendar_html($seriesid),
                'summaryformat' => FORMAT_HTML,
                'timemodified' => time(),
            ]);
        }
        rebuild_course_cache((int)$course->id, true);
    }

    public static function render_calendar_html(int $seriesid): string {
        global $DB;
        $series = self::get($seriesid);
        $items = self::items($seriesid);
        $cards = [];
        foreach ($items as $item) {
            $edition = $DB->get_record('local_ga_workshop_editions', ['workshopid' => $item->workshopid], '*', IGNORE_MULTIPLE);
            if (!$edition) {
                continue;
            }
            $teachers = manager::get_edition_teachers((int)$edition->id);
            $teachernames = [];
            foreach ($teachers as $teacher) {
                $teachernames[] = fullname($teacher);
            }
            $date = !empty($edition->sessiondate) ? userdate((int)$edition->sessiondate, '%A %d/%m/%Y') : 'Fecha pendiente';
            $time = !empty($edition->sessiondate) ? userdate((int)$edition->sessiondate, '%H:%M') : '';
            $deadline = !empty($edition->enrolenddate) ? userdate((int)$edition->enrolenddate, '%d/%m/%Y %H:%M') : '-';
            $cards[] = '<article style="border:1px solid #d7ddd2;border-radius:12px;background:#fff;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.05)">' .
                '<div style="font-size:.78rem;font-weight:700;letter-spacing:.04em;color:#65735e;text-transform:uppercase">Taller ' . sprintf('%02d', (int)$item->sortorder) . '</div>' .
                '<h4 style="margin:.25rem 0 .65rem;font-size:1.05rem;color:#29451f">' . s($item->name) . '</h4>' .
                '<div><strong>' . s($date) . '</strong>' . ($time !== '' ? ' · ' . s($time) : '') . '</div>' .
                '<div style="margin-top:.35rem;color:#4e5a49">' . format_float((float)$item->hours, 2, true) . ' h' . (!empty($teachernames) ? ' · ' . s(implode(', ', $teachernames)) : '') . '</div>' .
                '<div style="margin-top:.35rem;color:#687064;font-size:.9rem">Inscripción hasta: ' . s($deadline) . ' · Plazas: ' . (int)$edition->places . '</div>' .
                '</article>';
        }
        $range = userdate((int)$series->datefrom, '%d/%m/%Y') . ' – ' . userdate((int)$series->dateto, '%d/%m/%Y');
        return '<div class="ga-workshop-calendar" style="max-width:1180px;margin:0 auto">' .
            '<div style="margin-bottom:16px"><h3 style="margin-bottom:4px">' . s($series->title) . '</h3><div style="color:#64705e">' . s($range) . '</div></div>' .
            '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">' . implode('', $cards) . '</div></div>';
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

    private static function move_workshop_modules(int $workshopid, int $targetsectionid, int $notescmid = 0): void {
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
