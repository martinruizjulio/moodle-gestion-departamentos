from pathlib import Path
import re
import subprocess


def read(path):
    return Path(path).read_text(encoding='utf-8')


def write(path, text):
    Path(path).write_text(text, encoding='utf-8')


def exact(path, old, new, count=1):
    text = read(path)
    actual = text.count(old)
    if actual != count:
        raise RuntimeError(f'{path}: expected {count} occurrences, found {actual}: {old[:120]}')
    write(path, text.replace(old, new, count))


def replace_function(path, signature, replacement):
    text = read(path)
    start = text.find(signature)
    if start < 0:
        raise RuntimeError(f'{path}: signature not found: {signature}')
    brace = text.find('{', start)
    if brace < 0:
        raise RuntimeError(f'{path}: opening brace not found')
    depth = 0
    quote = None
    escaped = False
    end = None
    i = brace
    while i < len(text):
        ch = text[i]
        if escaped:
            escaped = False
            i += 1
            continue
        if ch == '\\':
            escaped = True
            i += 1
            continue
        if quote:
            if ch == quote:
                quote = None
            i += 1
            continue
        if ch in ("'", '"'):
            quote = ch
            i += 1
            continue
        if ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                end = i + 1
                break
        i += 1
    if end is None:
        raise RuntimeError(f'{path}: unmatched braces')
    write(path, text[:start] + replacement + text[end:])


def run(args):
    subprocess.run(args, check=True)


# ---- user-timezone helper -------------------------------------------------
helper = 'gestion_actividades/classes/local/date_helper.php'
write(helper, r'''<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

final class date_helper {
    public static function parse_user_datetime(string $value): int {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            return 0;
        }
        $timezone = \core_date::get_user_timezone_object();
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dt !== false) {
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0)) {
                    return $dt->getTimestamp();
                }
            }
        }
        return 0;
    }

    public static function input_datetime(int $timestamp): string {
        return $timestamp > 0 ? userdate($timestamp, '%Y-%m-%dT%H:%M') : '';
    }
}
''')

# ---- explicit workshop edition -> series relation ------------------------
ws = 'gestion_actividades/classes/local/workshop_series.php'
replace_function(ws, '    public static function ensure_schema(): void', r'''    public static function ensure_schema(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))
                || !$DB->get_manager()->table_exists(new \xmldb_table(self::ITEMTABLE))) {
            throw new \coding_exception('El esquema de Ediciones de talleres no está instalado. Ejecuta la actualización de Moodle.');
        }
    }''')

exact(ws, r'''        if ($id > 0) {
            $old = self::get($id);
            if ((int)$old->courseid !== $record->courseid && self::items($id)) {
                throw new \RuntimeException('No se puede cambiar de curso una edición que ya contiene talleres.');
            }
            $record->id = $id;
''', r'''        if ($id > 0) {
            $old = self::get($id);
            $existingitems = self::items($id);
            if ((int)$old->courseid !== $record->courseid && $existingitems) {
                throw new \RuntimeException('No se puede cambiar de curso una edición que ya contiene talleres.');
            }
            foreach ($existingitems as $existingitem) {
                self::assert_no_overlapping_series($id, (int)$existingitem->workshopid, $record->datefrom, $record->dateto);
            }
            $record->id = $id;
''')

replace_function(ws, '    public static function series_for_edition(int $editionid): ?\\stdClass', r'''    public static function series_for_edition(int $editionid): ?\stdClass {
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
    }''')

exact(ws,
      '    public static function attach_workshop(int $seriesid, int $workshopid, int $sortorder = 0, int $notescmid = 0, int $sessionenddate = 0): void {',
      '    public static function attach_workshop(int $seriesid, int $workshopid, int $sortorder = 0, int $notescmid = 0, int $sessionenddate = 0, int $editionid = 0): void {')

exact(ws, r'''        if ($sessionenddate > 0 && ($sessionenddate < (int)$series->datefrom || $sessionenddate > (int)$series->dateto)) {
            throw new \RuntimeException('La hora de fin del taller queda fuera de las fechas de la edición.');
        }
        $existing = $DB->get_record(self::ITEMTABLE, ['seriesid' => $seriesid, 'workshopid' => $workshopid], '*', IGNORE_MISSING);
''', r'''        if ($sessionenddate > 0 && ($sessionenddate < (int)$series->datefrom || $sessionenddate > (int)$series->dateto)) {
            throw new \RuntimeException('La hora de fin del taller queda fuera de las fechas de la edición.');
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
''')

exact(ws, '    public static function next_sortorder(int $seriesid): int {', r'''    private static function assert_no_overlapping_series(int $seriesid, int $workshopid, int $datefrom, int $dateto): void {
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

    public static function next_sortorder(int $seriesid): int {''')

editionedit = 'gestion_actividades/edition_edit.php'
bulk = 'gestion_actividades/classes/local/bulk_workshops.php'
exact(editionedit,
      'workshop_series::attach_workshop($seriesid, $workshopid, $sortorder, 0, $sessionenddate);',
      'workshop_series::attach_workshop($seriesid, $workshopid, $sortorder, 0, $sessionenddate, $savededitionid);')
exact(bulk,
      "workshop_series::attach_workshop($seriesid, $workshopid, $order, $notescmid, $row['sessionenddate']);",
      "workshop_series::attach_workshop($seriesid, $workshopid, $order, $notescmid, $row['sessionenddate'], $editionid);")

# ---- install.xml + authoritative upgrade ---------------------------------
install = 'gestion_actividades/db/install.xml'
xml = read(install)
start = xml.index('<TABLE NAME="local_ga_workshop_editions"')
end = xml.index('</TABLE>', start)
segment = xml[start:end]
if 'NAME="seriesid"' not in segment:
    segment = segment.replace(
        '<FIELD NAME="workshopid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0"/>',
        '<FIELD NAME="workshopid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0"/>\n        <FIELD NAME="seriesid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0"/>',
        1)
    segment = segment.replace(
        '<INDEX NAME="workshopid" UNIQUE="false" FIELDS="workshopid"/>',
        '<INDEX NAME="workshopid" UNIQUE="false" FIELDS="workshopid"/>\n        <INDEX NAME="seriesid" UNIQUE="false" FIELDS="seriesid"/>',
        1)
    xml = xml[:start] + segment + xml[end:]
    write(install, xml)

upgrade = 'gestion_actividades/db/upgrade.php'
text = read(upgrade)
text = text.replace(r'''        // These helpers use Moodle XMLDB and are idempotent. Running them here
        // makes the upgrade path authoritative instead of relying on page visits.
        \local_gestion_actividades\local\portfolio_typeb::ensure_table();
        \local_gestion_actividades\local\institutional_hours::ensure_table();
        \local_gestion_actividades\local\workshop_series::ensure_schema();

''', '', 1)
marker = '    return true;\n}'
if marker not in text:
    raise RuntimeError('upgrade.php return marker not found')
migration = r'''    if ($oldversion < 2026100513) {
        $series = new xmldb_table('local_ga_workshop_series');
        if (!$dbman->table_exists($series)) {
            $series->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $series->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $series->add_field('datefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('dateto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('calendarcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('calendarsectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $series->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $series->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $series->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($series);
        }

        $items = new xmldb_table('local_ga_series_items');
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
            $endfield = new xmldb_field('sessionenddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sortorder');
            if (!$dbman->field_exists($items, $endfield)) {
                $dbman->add_field($items, $endfield);
            }
        }

        $editions = new xmldb_table('local_ga_workshop_editions');
        $seriesfield = new xmldb_field('seriesid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'workshopid');
        if ($dbman->table_exists($editions) && !$dbman->field_exists($editions, $seriesfield)) {
            $dbman->add_field($editions, $seriesfield);
        }
        if ($dbman->table_exists($editions)) {
            $seriesindex = new xmldb_index('seriesid', XMLDB_INDEX_NOTUNIQUE, ['seriesid']);
            if (!$dbman->index_exists($editions, $seriesindex)) {
                $dbman->add_index($editions, $seriesindex);
            }
            $rows = $DB->get_records_select('local_ga_workshop_editions', 'seriesid = 0 OR seriesid IS NULL');
            foreach ($rows as $edition) {
                $sql = "SELECT s.id
                          FROM {local_ga_workshop_series} s
                          JOIN {local_ga_series_items} i ON i.seriesid = s.id
                         WHERE i.workshopid = :workshopid
                           AND s.datefrom <= :sessionfrom
                           AND s.dateto >= :sessionto
                      ORDER BY s.datefrom DESC, s.id DESC";
                $matches = $DB->get_records_sql($sql, [
                    'workshopid' => (int)$edition->workshopid,
                    'sessionfrom' => (int)$edition->sessiondate,
                    'sessionto' => (int)$edition->sessiondate,
                ], 0, 1);
                if ($matches) {
                    $match = reset($matches);
                    $DB->set_field('local_ga_workshop_editions', 'seriesid', (int)$match->id, ['id' => (int)$edition->id]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026100513, 'local', 'gestion_actividades');
    }

'''
write(upgrade, text.replace(marker, migration + marker, 1))

# ---- remove DDL from runtime helper methods -------------------------------
grade = 'gestion_actividades/classes/local/grade_manager.php'
replace_function(grade, '    public static function ensure_settings_table(): void', r'''    public static function ensure_settings_table(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::SETTINGS_TABLE))) {
            throw new \coding_exception('El esquema de configuración HEE no está instalado. Ejecuta la actualización de Moodle.');
        }
    }''')

institutional = 'gestion_actividades/classes/local/institutional_hours.php'
replace_function(institutional, '    public static function ensure_table(): void', r'''    public static function ensure_table(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            throw new \coding_exception('El esquema de reconocimiento institucional HEE no está instalado. Ejecuta la actualización de Moodle.');
        }
    }''')

typeb = 'gestion_actividades/classes/local/portfolio_typeb.php'
replace_function(typeb, '    public static function ensure_table(): void', r'''    public static function ensure_table(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            throw new \coding_exception('El esquema de certificados Tipo B no está instalado. Ejecuta la actualización de Moodle.');
        }
    }''')

# ---- timezone-safe form parsing ------------------------------------------
text = read(editionedit)
if 'use local_gestion_actividades\\local\\date_helper;' not in text:
    text = text.replace('use local_gestion_actividades\\local\\typeb_reflection_activity;\n',
                        'use local_gestion_actividades\\local\\typeb_reflection_activity;\nuse local_gestion_actividades\\local\\date_helper;\n', 1)
text = text.replace("strtotime(str_replace('T', ' ', required_param('sessiondate_text', PARAM_TEXT))) ?: 0",
                    "date_helper::parse_user_datetime(required_param('sessiondate_text', PARAM_TEXT))")
text = text.replace("strtotime(str_replace('T', ' ', required_param('sessionenddate_text', PARAM_TEXT))) ?: 0",
                    "date_helper::parse_user_datetime(required_param('sessionenddate_text', PARAM_TEXT))")
text = text.replace("strtotime(str_replace('T', ' ', required_param('enrolenddate_text', PARAM_TEXT))) ?: 0",
                    "date_helper::parse_user_datetime(required_param('enrolenddate_text', PARAM_TEXT))")
write(editionedit, text)

seriespage = 'gestion_actividades/workshop_series.php'
text = read(seriespage)
if 'use local_gestion_actividades\\local\\date_helper;' not in text:
    text = text.replace('use local_gestion_actividades\\local\\workshop_series;\n',
                        'use local_gestion_actividades\\local\\workshop_series;\nuse local_gestion_actividades\\local\\date_helper;\n', 1)
text = text.replace("strtotime(str_replace('T', ' ', required_param('datefrom_text', PARAM_TEXT))) ?: 0",
                    "date_helper::parse_user_datetime(required_param('datefrom_text', PARAM_TEXT))")
text = text.replace("strtotime(str_replace('T', ' ', required_param('dateto_text', PARAM_TEXT))) ?: 0",
                    "date_helper::parse_user_datetime(required_param('dateto_text', PARAM_TEXT))")
write(seriespage, text)

internaltask = 'gestion_actividades/internal_task.php'
text = read(internaltask)
if 'use local_gestion_actividades\\local\\date_helper;' not in text:
    text = text.replace('use local_gestion_actividades\\local\\manager;\n',
                        'use local_gestion_actividades\\local\\manager;\nuse local_gestion_actividades\\local\\date_helper;\n', 1)
text = text.replace("$duedatetext !== '' ? (strtotime(str_replace('T', ' ', $duedatetext)) ?: 0) : 0",
                    "$duedatetext !== '' ? date_helper::parse_user_datetime($duedatetext) : 0")
text = text.replace("date('Y-m-d\\TH:i', (int)$edition->taskduedate)",
                    "date_helper::input_datetime((int)$edition->taskduedate)")
write(internaltask, text)

bulkpage = 'gestion_actividades/workshop_bulk_import.php'
text = read(bulkpage)
if 'use local_gestion_actividades\\local\\date_helper;' not in text:
    text = text.replace('use local_gestion_actividades\\local\\bulk_workshops;\n',
                        'use local_gestion_actividades\\local\\bulk_workshops;\nuse local_gestion_actividades\\local\\date_helper;\n', 1)
text = text.replace("strtotime(str_replace('T', ' ', $seriesfromtext)) ?: 0", "date_helper::parse_user_datetime($seriesfromtext)")
text = text.replace("strtotime(str_replace('T', ' ', $seriestotext)) ?: 0", "date_helper::parse_user_datetime($seriestotext)")
text = text.replace("date('Y-m-d\\TH:i', $defaultfrom)", "date_helper::input_datetime($defaultfrom)")
text = text.replace("date('Y-m-d\\TH:i', $defaultto)", "date_helper::input_datetime($defaultto)")
write(bulkpage, text)

replace_function(bulk, '    private static function parse_datetime(', r'''    private static function parse_datetime($value): int {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        return date_helper::parse_user_datetime($value);
    }''')

# ---- version --------------------------------------------------------------
version = 'gestion_actividades/version.php'
text = read(version)
text = re.sub(r'\$plugin->version\s*=\s*\d+;', '$plugin->version = 2026100513;', text)
text = re.sub(r"\$plugin->release\s*=\s*'[^']+';", "$plugin->release = '1.5.98-alpha';", text)
write(version, text)

# Commit all source changes; validation happens in the workflow before push.
run(['git', 'add', helper, ws, editionedit, bulk, install, upgrade, grade, institutional, typeb,
     seriespage, internaltask, bulkpage, version])
run(['git', 'commit', '-m', 'feat: formalise workshop series schema and timezone handling'])
