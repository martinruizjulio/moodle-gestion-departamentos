<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

class institutional_hours {
    public const TABLE = 'local_ga_institutional_hours';
    public const SOURCE = 'Reconocimiento institucional';
    private const B_REQUIRED_HOURS = 22.0;

    public static function ensure_table(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::TABLE);
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fullname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('courselevel', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('groupname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('typeahours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('typebhours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('taskgrade', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
            $table->add_field('typebreflection', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('typebreflectionmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('source', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, self::SOURCE);
            $table->add_field('importid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('originalrow', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('rawdata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);
            $table->add_index('email', XMLDB_INDEX_NOTUNIQUE, ['email']);
            $dbman->create_table($table);
            return;
        }

        $fields = [
            new \xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id'),
            new \xmldb_field('email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'userid'),
            new \xmldb_field('fullname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'email'),
            new \xmldb_field('courselevel', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'fullname'),
            new \xmldb_field('groupname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'courselevel'),
            new \xmldb_field('typeahours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'groupname'),
            new \xmldb_field('typebhours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'typeahours'),
            new \xmldb_field('taskgrade', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'typebhours'),
            new \xmldb_field('typebreflection', XMLDB_TYPE_TEXT, null, null, null, null, null, 'taskgrade'),
            new \xmldb_field('typebreflectionmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'typebreflection'),
            new \xmldb_field('source', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, self::SOURCE, 'typebreflectionmodified'),
            new \xmldb_field('importid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'source'),
            new \xmldb_field('originalrow', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'importid'),
            new \xmldb_field('rawdata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'originalrow'),
            new \xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'rawdata'),
            new \xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated'),
            new \xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        self::add_index_if_possible($dbman, self::TABLE, 'userid', ['userid'], true);
        self::add_index_if_possible($dbman, self::TABLE, 'email', ['email'], false);
    }

    private static function add_index_if_possible($dbman, string $tablename, string $indexname, array $fields, bool $unique = false): void {
        global $DB;
        $table = new \xmldb_table($tablename);
        if (!$dbman->table_exists($table)) {
            return;
        }
        $columns = $DB->get_columns($tablename);
        foreach ($fields as $field) {
            if (!isset($columns[$field])) {
                return;
            }
        }
        $index = new \xmldb_index($indexname, $unique ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, $fields);
        if ($dbman->index_exists($table, $index)) {
            return;
        }
        if ($unique) {
            // Older versions could store several rows per user. Adding a UNIQUE
            // index over duplicates would abort the Moodle upgrade, so skip it
            // and leave the duplicates for a manual review (no data is deleted).
            $cols = implode(', ', $fields);
            $dup = $DB->get_records_sql("SELECT $cols, COUNT(1) AS n FROM {" . $tablename . "} GROUP BY $cols HAVING COUNT(1) > 1", [], 0, 1);
            if ($dup) {
                debugging('Gestión HEE: no se crea el índice único ' . $indexname . ' en ' . $tablename
                    . ' porque existen filas duplicadas. Revísalas manualmente.', DEBUG_DEVELOPER);
                return;
            }
        }
        try {
            $dbman->add_index($table, $index);
        } catch (\Throwable $e) {
            debugging('Gestión HEE: no se pudo crear el índice ' . $indexname . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public static function list_for_user(int $userid): array {
        global $DB;
        self::ensure_table();
        if ($userid <= 0) {
            return [];
        }
        return $DB->get_records(self::TABLE, ['userid' => $userid], 'timemodified DESC, id DESC');
    }

    public static function save_typeb_reflection(int $recordid, int $userid, string $reflectiontext): bool {
        global $DB;
        self::ensure_table();
        $reflectiontext = trim($reflectiontext);
        if ($recordid <= 0 || $userid <= 0 || $reflectiontext === '') {
            return false;
        }
        $record = $DB->get_record(self::TABLE, ['id' => $recordid, 'userid' => $userid], '*', IGNORE_MISSING);
        if (!$record || (float)($record->typebhours ?? 0) <= 0) {
            return false;
        }
        $now = time();
        $DB->update_record(self::TABLE, (object)[
            'id' => (int)$record->id,
            'typebreflection' => $reflectiontext,
            'typebreflectionmodified' => $now,
            'timemodified' => $now,
        ]);
        self::invalidate_block_cache_for_user($userid);
        grade_manager::sync_user_safely($userid);
        return true;
    }

    public static function total_typea_hours(int $userid): float {
        global $DB;
        self::ensure_table();
        if ($userid <= 0) {
            return 0.0;
        }
        return (float)$DB->get_field_sql('SELECT COALESCE(SUM(typeahours), 0) FROM {' . self::TABLE . '} WHERE userid = :userid', ['userid' => $userid]);
    }

    public static function total_typeb_hours(int $userid): float {
        global $DB;
        self::ensure_table();
        if ($userid <= 0) {
            return 0.0;
        }
        return (float)$DB->get_field_sql('SELECT COALESCE(SUM(typebhours), 0) FROM {' . self::TABLE . '} WHERE userid = :userid', ['userid' => $userid]);
    }

    public static function userids_with_records(): array {
        global $DB;
        self::ensure_table();
        $rows = $DB->get_records_sql('SELECT DISTINCT userid FROM {' . self::TABLE . '} WHERE userid > 0');
        return array_map('intval', array_keys($rows));
    }

    public static function get_temp_directory(): string {
        global $CFG;
        make_temp_directory('local_gestion_actividades/institutional_import');
        return $CFG->tempdir . '/local_gestion_actividades/institutional_import';
    }

    public static function save_uploaded_file(array $file): string {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No se ha recibido ningún archivo Excel válido.');
        }
        $filename = clean_filename($file['name'] ?? 'reconocimiento.xlsx');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new \RuntimeException('El archivo debe estar en formato .xlsx.');
        }
        $token = random_string(20);
        $target = self::get_temp_path($token);
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('No se ha podido guardar temporalmente el archivo.');
        }
        return $token;
    }

    public static function get_temp_path(string $token): string {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $token);
        if ($token === '') {
            throw new \RuntimeException('Token de importación no válido.');
        }
        return self::get_temp_directory() . '/' . $token . '.xlsx';
    }

    public static function preview_from_token(string $token): array {
        $path = self::get_temp_path($token);
        if (!is_readable($path)) {
            throw new \RuntimeException('No se encuentra el archivo temporal de importación. Vuelve a subir el Excel.');
        }
        return self::preview_file($path);
    }

    public static function preview_file(string $path): array {
        $rows = self::parse_xlsx($path);
        $preview = [];
        $summary = (object)['total' => 0, 'found' => 0, 'notfound' => 0, 'duplicate' => 0, 'invalid' => 0, 'withhours' => 0, 'withgrade' => 0, 'typeahours' => 0.0, 'typebhours' => 0.0];
        foreach ($rows as $row) {
            $summary->total++;
            $email = self::clean_email($row['email'] ?? '');
            $row['email'] = $email;
            $row['userid'] = 0;
            $row['status'] = 'invalid';
            $row['statuslabel'] = 'Email vacío o no válido';
            if ($email !== '') {
                $users = self::find_users_by_email($email);
                if (count($users) === 1) {
                    $user = reset($users);
                    $row['userid'] = (int)$user->id;
                    $row['moodlefullname'] = fullname($user);
                    $row['status'] = 'found';
                    $row['statuslabel'] = 'Encontrado';
                } else if (count($users) > 1) {
                    $row['status'] = 'duplicate';
                    $row['statuslabel'] = 'Email duplicado en Moodle';
                } else {
                    $row['status'] = 'notfound';
                    $row['statuslabel'] = 'Alumno no encontrado en Moodle';
                }
            }
            $summary->{$row['status']}++;
            if ((float)$row['typeahours'] > 0 || (float)$row['typebhours'] > 0) {
                $summary->withhours++;
            }
            if (array_key_exists('taskgrade', $row) && $row['taskgrade'] !== null && $row['taskgrade'] !== '') {
                $summary->withgrade++;
            }
            if ($row['status'] === 'found') {
                $summary->typeahours += (float)$row['typeahours'];
                $summary->typebhours += (float)$row['typebhours'];
            }
            $preview[] = $row;
        }
        return ['rows' => $preview, 'summary' => $summary];
    }

    public static function import_from_token(string $token, int $usermodified): \stdClass {
        global $DB;
        self::ensure_table();
        self::invalidate_block_schema_cache();
        $preview = self::preview_from_token($token);
        $importid = date('YmdHis') . '_' . random_string(8);
        $now = time();
        $result = (object)['created' => 0, 'updated' => 0, 'skipped' => 0, 'notfound' => 0, 'duplicate' => 0, 'invalid' => 0, 'userids' => []];
        foreach ($preview['rows'] as $row) {
            if ($row['status'] !== 'found') {
                $result->{$row['status']}++;
                $result->skipped++;
                continue;
            }
            $hasgrade = array_key_exists('taskgrade', $row) && $row['taskgrade'] !== null && $row['taskgrade'] !== '';
            if ((float)$row['typeahours'] <= 0 && (float)$row['typebhours'] <= 0 && !$hasgrade) {
                $result->skipped++;
                continue;
            }
            $userid = (int)$row['userid'];
            $existing = $DB->get_record(self::TABLE, ['userid' => $userid], '*', IGNORE_MISSING);
            $record = (object)[
                'userid' => $userid,
                'email' => $row['email'],
                'fullname' => $row['fullname'],
                'courselevel' => $row['courselevel'],
                'groupname' => $row['groupname'],
                'typeahours' => round(max(0.0, (float)$row['typeahours']), 2),
                'typebhours' => round(max(0.0, (float)$row['typebhours']), 2),
                'taskgrade' => (array_key_exists('taskgrade', $row) && $row['taskgrade'] !== null && $row['taskgrade'] !== '') ? round(max(0.0, min(10.0, (float)$row['taskgrade'])), 2) : null,
                'source' => self::SOURCE,
                'importid' => $importid,
                'originalrow' => (int)$row['rownum'],
                'rawdata' => json_encode($row, JSON_UNESCAPED_UNICODE),
                'timemodified' => $now,
                'usermodified' => $usermodified,
            ];
            if ($existing) {
                $record->id = (int)$existing->id;
                $DB->update_record(self::TABLE, $record);
                $result->updated++;
            } else {
                $record->timecreated = $now;
                $DB->insert_record(self::TABLE, $record);
                $result->created++;
            }
            $result->userids[] = $userid;
            self::invalidate_block_cache_for_user($userid);
        }
        $result->userids = array_values(array_unique($result->userids));
        if ($result->userids) {
            grade_manager::sync_all_managed_courses_safely();
        }
        return $result;
    }

    private static function find_users_by_email(string $email): array {
        global $DB;
        if ($email === '') {
            return [];
        }
        $sql = 'deleted = 0 AND ' . $DB->sql_equal('email', ':email', false, false);
        return $DB->get_records_select('user', $sql, ['email' => $email], '', 'id, firstname, lastname, email');
    }

    private static function parse_xlsx(string $path): array {
        if (!is_readable($path)) {
            throw new \RuntimeException('No se puede leer el archivo Excel subido.');
        }

        $errors = [];

        // Primera vía: lector incluido en Moodle cuando PhpSpreadsheet está disponible.
        // Es más tolerante con columnas ocultas, relaciones internas y valores cacheados de Excel.
        if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            try {
                $records = self::parse_xlsx_with_phpspreadsheet($path);
                if (!empty($records)) {
                    return $records;
                }
                $errors[] = 'PhpSpreadsheet no devolvió filas con email.';
            } catch (\Throwable $e) {
                $errors[] = 'PhpSpreadsheet: ' . $e->getMessage();
                if (function_exists('debugging')) {
                    debugging('Error leyendo reconocimiento institucional con PhpSpreadsheet: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        } else {
            $errors[] = 'PhpSpreadsheet no está disponible en esta instalación.';
        }

        // Segunda vía: lector XML ligero propio, sin llamadas externas.
        try {
            $records = self::parse_xlsx_manually($path);
            if (!empty($records)) {
                return $records;
            }
            $errors[] = 'Lector XML propio no devolvió filas con email.';
        } catch (\Throwable $e) {
            $errors[] = 'Lector XML propio: ' . $e->getMessage();
            if (function_exists('debugging')) {
                debugging('Error leyendo reconocimiento institucional con lector XML propio: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        throw new \RuntimeException('No se ha podido procesar el Excel. Detalle: ' . implode(' | ', $errors));
    }

    private static function parse_xlsx_with_phpspreadsheet(string $path): array {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load($path);
        try {
            $sheet = $spreadsheet->getSheetByName('TODOS');
            if (!$sheet) {
                $sheet = $spreadsheet->getSheet(0);
            }
            if (!$sheet) {
                throw new \RuntimeException('No se ha encontrado una hoja válida en el Excel.');
            }

            $highestrow = (int)$sheet->getHighestRow();
            $highestcol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
            $rawrows = [];
            for ($row = 1; $row <= $highestrow; $row++) {
                $cells = [];
                for ($col = 1; $col <= $highestcol; $col++) {
                    $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                    $cell = $sheet->getCell($coordinate);
                    $value = $cell->getValue();
                    if (is_string($value) && strlen($value) > 0 && $value[0] === '=') {
                        try {
                            $calculated = $cell->getCalculatedValue();
                            if ($calculated !== null && $calculated !== '') {
                                $value = $calculated;
                            }
                        } catch (\Throwable $e) {
                            if (method_exists($cell, 'getOldCalculatedValue')) {
                                $oldvalue = $cell->getOldCalculatedValue();
                                if ($oldvalue !== null && $oldvalue !== '') {
                                    $value = $oldvalue;
                                }
                            }
                        }
                    }
                    if ($value instanceof \DateTimeInterface) {
                        $value = userdate($value->getTimestamp(), '%Y-%m-%d');
                    }
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $cells[$col] = trim((string)$value);
                }
                if (!empty($cells)) {
                    $rawrows[$row] = $cells;
                }
            }
            return self::records_from_rawrows($rawrows);
        } finally {
            if (method_exists($spreadsheet, 'disconnectWorksheets')) {
                $spreadsheet->disconnectWorksheets();
            }
        }
    }

    private static function parse_xlsx_manually(string $path): array {
        $extractdir = self::get_temp_directory() . '/xlsx_' . random_string(16);
        make_writable_directory($extractdir);

        $ok = false;
        $packer = get_file_packer('application/zip');
        if ($packer) {
            $ok = (bool)$packer->extract_to_pathname($path, $extractdir);
        }

        // Fallback defensivo: algunas instalaciones fallan con el packer de Moodle, pero tienen ZipArchive activo.
        if (!$ok && class_exists('\\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $ok = $zip->extractTo($extractdir);
                $zip->close();
            }
        }

        if (!$ok) {
            self::delete_directory_safely($extractdir);
            throw new \RuntimeException('No se ha podido abrir el archivo Excel como .xlsx válido.');
        }

        try {
            $shared = self::read_shared_strings_from_dir($extractdir);
            $sheetpath = self::find_sheet_path_from_dir($extractdir, 'TODOS') ?: self::find_first_sheet_path_from_dir($extractdir);
            if (!$sheetpath) {
                throw new \RuntimeException('No se ha encontrado una hoja válida en el Excel.');
            }
            $xmlpath = $extractdir . '/' . $sheetpath;
            if (!is_readable($xmlpath)) {
                throw new \RuntimeException('No se ha podido leer la hoja del Excel.');
            }
            $xml = file_get_contents($xmlpath);
            if ($xml === false || $xml === '') {
                throw new \RuntimeException('No se ha podido leer la hoja del Excel.');
            }
            $root = @simplexml_load_string($xml);
            if (!$root) {
                throw new \RuntimeException('No se ha podido interpretar la hoja del Excel.');
            }
            $root->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheetrows = $root->xpath('//a:sheetData/a:row') ?: [];
            $rawrows = [];
            foreach ($sheetrows as $rowxml) {
                $rownum = (int)$rowxml['r'];
                $cells = [];
                foreach ($rowxml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                    $ref = (string)$cell['r'];
                    $col = self::column_number($ref);
                    $value = self::cell_value($cell, $shared);
                    if ($value !== '') {
                        $cells[$col] = $value;
                    }
                }
                if (!empty($cells)) {
                    $rawrows[$rownum] = $cells;
                }
            }
            return self::records_from_rawrows($rawrows);
        } finally {
            self::delete_directory_safely($extractdir);
        }
    }

    private static function records_from_rawrows(array $rawrows): array {
        $headerrow = self::detect_header_row($rawrows);
        if ($headerrow <= 0) {
            throw new \RuntimeException('No se ha podido detectar la fila de encabezados del Excel.');
        }
        $headers = [];
        foreach ($rawrows[$headerrow] as $col => $value) {
            $headers[$col] = self::normalise_header($value);
        }
        $emailcol = self::find_header_col($headers, ['email2', 'email', 'correoelectronico', 'correo']);
        if ($emailcol <= 0) {
            throw new \RuntimeException('No se ha encontrado la columna de email.');
        }
        $fullnamecol = self::find_header_col($headers, ['columna1', 'nombreyapellidos', 'alumno']);
        $surnamecol = self::find_header_col($headers, ['apellidos']);
        $namecol = self::find_header_col($headers, ['nombre']);
        $coursecol = self::find_header_col($headers, ['curso', 'curso2']);
        $groupcol = self::find_header_col($headers, ['grupo', 'grupo2', 'clavegrupo']);
        $typeacol = self::find_header_col($headers, ['totala', 'horastipoa', 'tipoa', 'horasa']);
        $typebcol = self::find_header_col($headers, ['totalb', 'horastipob', 'tipob', 'horasb']);
        $pendingbcol = self::find_header_col($headers, ['ptetipob22h', 'ptetipob', 'pendientetipob', 'pendienteb']);
        $taskgradecol = self::find_header_col($headers, ['promedio', 'nota', 'notatallertipoa', 'notatarea', 'notatipoa', 'calificacion', 'calificaciontarea']);

        $records = [];
        foreach ($rawrows as $rownum => $cells) {
            if ($rownum <= $headerrow) {
                continue;
            }
            $email = self::clean_email($cells[$emailcol] ?? '');
            if ($email === '') {
                continue;
            }
            $fullname = trim((string)($fullnamecol > 0 ? ($cells[$fullnamecol] ?? '') : ''));
            if ($fullname === '') {
                $fullname = trim((string)($namecol > 0 ? ($cells[$namecol] ?? '') : '') . ' ' . (string)($surnamecol > 0 ? ($cells[$surnamecol] ?? '') : ''));
            }
            $typea = $typeacol > 0 ? self::parse_float($cells[$typeacol] ?? 0) : 0.0;
            if ($typebcol > 0) {
                $typeb = self::parse_float($cells[$typebcol] ?? 0);
            } else if ($pendingbcol > 0) {
                $pendingb = self::parse_float($cells[$pendingbcol] ?? self::B_REQUIRED_HOURS);
                $typeb = max(0.0, self::B_REQUIRED_HOURS - $pendingb);
            } else {
                $typeb = 0.0;
            }
            $taskgrade = null;
            if ($taskgradecol > 0 && isset($cells[$taskgradecol])) {
                $candidategrade = self::parse_float($cells[$taskgradecol]);
                if ($candidategrade > 0) {
                    $taskgrade = round(max(0.0, min(10.0, $candidategrade)), 2);
                }
            }
            $records[] = [
                'rownum' => $rownum,
                'email' => $email,
                'fullname' => $fullname,
                'courselevel' => trim((string)($coursecol > 0 ? ($cells[$coursecol] ?? '') : '')),
                'groupname' => trim((string)($groupcol > 0 ? ($cells[$groupcol] ?? '') : '')),
                'typeahours' => round(max(0.0, $typea), 2),
                'typebhours' => round(max(0.0, $typeb), 2),
                'taskgrade' => $taskgrade,
            ];
        }
        return $records;
    }

    private static function read_shared_strings_from_dir(string $extractdir): array {
        $shared = [];
        $path = $extractdir . '/xl/sharedStrings.xml';
        if (!is_readable($path)) {
            return $shared;
        }
        $xml = file_get_contents($path);
        if ($xml === false || $xml === '') {
            return $shared;
        }

        // Lector tolerante: algunos Excel usan rich text (<r><t>...</t></r>) y otros texto simple.
        // Evitamos depender de XPath/namespaces para que funcione en más servidores PHP.
        if (preg_match_all('/<si[^>]*>(.*?)<\/si>/s', $xml, $matches)) {
            foreach ($matches[1] as $si) {
                $texts = [];
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tmatches)) {
                    foreach ($tmatches[1] as $txt) {
                        $texts[] = html_entity_decode(strip_tags($txt), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $shared[] = trim(implode('', $texts));
            }
        }
        return $shared;
    }

    private static function find_sheet_path_from_dir(string $extractdir, string $preferred): ?string {
        $wbpath = $extractdir . '/xl/workbook.xml';
        $relspath = $extractdir . '/xl/_rels/workbook.xml.rels';
        if (!is_readable($wbpath) || !is_readable($relspath)) {
            return null;
        }
        $wbxml = file_get_contents($wbpath);
        $relsxml = file_get_contents($relspath);
        if ($wbxml === false || $relsxml === false) {
            return null;
        }

        $relmap = [];
        if (preg_match_all('/<Relationship\b[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"[^>]*>/i', $relsxml, $rmatches, PREG_SET_ORDER)) {
            foreach ($rmatches as $m) {
                $relmap[$m[1]] = html_entity_decode($m[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        if (!$relmap) {
            return null;
        }

        if (preg_match_all('/<sheet\b[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"[^>]*>/i', $wbxml, $smatches, PREG_SET_ORDER)) {
            foreach ($smatches as $m) {
                $name = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                $rid = $m[2];
                if (\core_text::strtolower($name) === \core_text::strtolower($preferred) && !empty($relmap[$rid])) {
                    return self::normalise_xlsx_path($relmap[$rid]);
                }
            }
        }
        return null;
    }

    private static function find_first_sheet_path_from_dir(string $extractdir): ?string {
        $base = $extractdir . '/xl/worksheets';
        if (!is_dir($base)) {
            return null;
        }
        for ($i = 1; $i <= 50; $i++) {
            $path = 'xl/worksheets/sheet' . $i . '.xml';
            if (is_readable($extractdir . '/' . $path)) {
                return $path;
            }
        }
        return null;
    }

    private static function delete_directory_safely(string $dir): void {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        try {
            if (function_exists('remove_dir')) {
                remove_dir($dir);
                return;
            }
            $items = scandir($dir);
            if ($items === false) {
                return;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    self::delete_directory_safely($path);
                } else if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($dir);
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se ha podido eliminar el directorio temporal de importación institucional: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    private static function normalise_xlsx_path(string $target): string {
        $target = ltrim($target, '/');
        if (strpos($target, 'xl/') === 0) {
            return $target;
        }
        return 'xl/' . $target;
    }

    private static function detect_header_row(array $rawrows): int {
        $bestrow = 0;
        $bestscore = 0;
        foreach ($rawrows as $rownum => $cells) {
            $norm = array_map([self::class, 'normalise_header'], $cells);
            $score = 0;
            foreach ($norm as $header) {
                if (in_array($header, ['email2', 'email', 'correoelectronico', 'correo'], true)) {
                    $score += 5;
                }
                if (in_array($header, ['columna1', 'nombreyapellidos', 'apellidos', 'nombre'], true)) {
                    $score += 2;
                }
                if (in_array($header, ['totala', 'ptea45h', 'ptetipob22h', 'promedio'], true)) {
                    $score += 2;
                }
                if (preg_match('/^(a|t|b)\d+$/', $header)) {
                    $score += 1;
                }
            }
            if ($score > $bestscore) {
                $bestscore = $score;
                $bestrow = (int)$rownum;
            }
            if ($score >= 8) {
                return (int)$rownum;
            }
        }
        return $bestscore >= 5 ? $bestrow : 0;
    }

    private static function find_header_col(array $headers, array $candidates): int {
        foreach ($candidates as $candidate) {
            foreach ($headers as $col => $header) {
                if ($header === $candidate) {
                    return (int)$col;
                }
            }
        }
        foreach ($candidates as $candidate) {
            foreach ($headers as $col => $header) {
                if ($candidate !== '' && strpos($header, $candidate) !== false) {
                    return (int)$col;
                }
            }
        }
        return 0;
    }

    private static function cell_value(\SimpleXMLElement $cell, array $shared): string {
        $type = (string)$cell['t'];
        $children = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        if ($type === 'inlineStr') {
            $texts = [];
            foreach ($children->is->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $texts[] = (string)$t;
            }
            return trim(implode('', $texts));
        }

        // En celdas con fórmula interesa el valor cacheado <v>, no la fórmula <f>.
        $value = isset($children->v) ? (string)$children->v : '';
        if ($type === 's' && $value !== '' && isset($shared[(int)$value])) {
            return trim((string)$shared[(int)$value]);
        }
        return trim($value);
    }

    private static function column_number(string $ref): int {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $num = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return $num;
    }

    private static function normalise_header($value): string {
        $value = trim((string)$value);
        $value = str_replace(['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ'], ['a','e','i','o','u','A','E','I','O','U','n','N'], $value);
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9]/', '', $value);
    }

    private static function parse_float($value): float {
        $value = trim((string)$value);
        if ($value === '' || str_starts_with($value, '#')) {
            return 0.0;
        }
        $value = str_replace(',', '.', $value);
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private static function clean_email(string $email): string {
        $email = strtolower(trim($email));
        return validate_email($email) ? $email : '';
    }

    private static function invalidate_block_schema_cache(): void {
        global $CFG;
        try {
            if (!function_exists('block_gestion_hee_invalidate_schema_cache')) {
                $blocklib = $CFG->dirroot . '/blocks/gestion_hee/lib.php';
                if (is_readable($blocklib)) {
                    require_once($blocklib);
                }
            }
            if (function_exists('block_gestion_hee_invalidate_schema_cache')) {
                block_gestion_hee_invalidate_schema_cache();
            }
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se ha podido invalidar la caché de esquema del bloque Gestión HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    private static function invalidate_block_cache_for_user(int $userid): void {
        global $CFG;
        $userid = max(0, $userid);
        if ($userid <= 0) {
            return;
        }
        try {
            if (!function_exists('block_gestion_hee_invalidate_user_cache')) {
                $blocklib = $CFG->dirroot . '/blocks/gestion_hee/lib.php';
                if (is_readable($blocklib)) {
                    require_once($blocklib);
                }
            }
            if (function_exists('block_gestion_hee_invalidate_user_cache')) {
                block_gestion_hee_invalidate_user_cache($userid);
            }
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se ha podido invalidar la caché del bloque Gestión HEE tras reconocimiento institucional: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
