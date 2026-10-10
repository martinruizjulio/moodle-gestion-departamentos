<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Historical Tipo B PAT importer.
 *
 * These records may arrive years before the student exists in this Moodle.
 * Identity is therefore stored independently and linked to userid whenever a
 * reliable institutional identifier/email becomes available.
 */
class pat_typeb {
    public const TABLE = 'local_ga_typeb_pat';
    public const IMPORTS = 'local_ga_typeb_pat_imports';
    private const TEMPDIR = 'local_gestion_actividades/pat_typeb';

    public static function save_uploaded_file(array $file): string {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No se ha recibido un archivo XLSX válido.');
        }
        $name = (string)($file['name'] ?? '');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException('El archivo debe ser .xlsx.');
        }
        $dir = make_temp_directory(self::TEMPDIR);
        $token = bin2hex(random_bytes(16));
        $path = $dir . DIRECTORY_SEPARATOR . $token . '.xlsx';
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new \RuntimeException('No se ha podido guardar temporalmente el Excel.');
        }
        return $token;
    }

    public static function path_from_token(string $token): string {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \RuntimeException('Token de importación no válido.');
        }
        $path = make_temp_directory(self::TEMPDIR) . DIRECTORY_SEPARATOR . $token . '.xlsx';
        if (!is_readable($path)) {
            throw new \RuntimeException('La importación ha caducado. Vuelve a subir el Excel.');
        }
        return $path;
    }

    /** @return array{headers:array,quizcols:array,attendancecol:int,rows:array} */
    public static function preview(string $token): array {
        return self::read_xlsx(self::path_from_token($token));
    }

    /**
     * Import a PAT export.
     *
     * @param array $hoursbycol column => hours to recognise
     * @param array $maxbycol column => maximum quiz grade in the file (default 10).
     *        Pass rule: attendance 100 and grade >= half of the maximum (5/10, 2.5/5…).
     */
    public static function import(string $token, string $academicyear, array $hoursbycol, string $filename = '', array $maxbycol = []): \stdClass {
        global $DB;
        $academicyear = academic_year::normalise($academicyear);
        if ($academicyear === '') {
            throw new \RuntimeException('Curso académico no válido.');
        }
        $parsed = self::read_xlsx(self::path_from_token($token));
        if (!$parsed['quizcols']) {
            throw new \RuntimeException('No se han detectado columnas «Cuestionario:… (Real)».');
        }
        foreach ($parsed['quizcols'] as $col => $name) {
            if (!isset($hoursbycol[$col]) || (float)$hoursbycol[$col] <= 0) {
                throw new \RuntimeException('Indica las horas de «' . $name . '».');
            }
            $max = isset($maxbycol[$col]) ? (float)$maxbycol[$col] : 10.0;
            if ($max <= 0) {
                throw new \RuntimeException('Indica la nota máxima del cuestionario de «' . $name . '».');
            }
            $maxbycol[$col] = $max;
        }

        $now = time();
        $created = 0; $updated = 0; $linked = 0; $pending = 0; $passed = 0;
        $linkeduserids = [];
        $transaction = $DB->start_delegated_transaction();
        global $USER;
        $batchid = (int)$DB->insert_record(self::IMPORTS, (object)[
            'filename' => clean_param($filename, PARAM_FILE), 'academicyear' => $academicyear,
            'workshops' => implode("\n", $parsed['quizcols']), 'created' => 0, 'updated' => 0, 'passed' => 0,
            'usermodified' => (int)($USER->id ?? 0), 'timecreated' => $now,
        ]);
        foreach ($parsed['rows'] as $row) {
            $identity = self::identity_from_row($row, $parsed['headers']);
            if ($identity->studentkey === '') {
                continue;
            }
            $userid = self::resolve_user($identity);
            if ($userid > 0) {
                $linked++;
                $linkeduserids[$userid] = $userid;
            } else {
                $pending++;
            }

            $attendance = self::numeric_value($row[$parsed['attendancecol']] ?? null);
            foreach ($parsed['quizcols'] as $col => $workshopname) {
                $grade = self::numeric_value($row[$col] ?? null);
                $hours = (float)$hoursbycol[$col];
                $grademax = (float)$maxbycol[$col];
                $ispassed = $attendance !== null && $attendance >= 100.0 && $grade !== null && $grade >= $grademax / 2.0;
                if ($ispassed) { $passed++; }
                $workshopkey = sha1(self::normalise_key($workshopname));
                $existing = $DB->get_record(self::TABLE, [
                    'studentkey' => $identity->studentkey,
                    'workshopkey' => $workshopkey,
                    'academicyear' => $academicyear,
                ], '*', IGNORE_MISSING);
                $record = (object)[
                    'userid' => $userid,
                    'studentkey' => $identity->studentkey,
                    'studentid' => $identity->studentid,
                    'dni' => $identity->dni,
                    'firstname' => $identity->firstname,
                    'lastname' => $identity->lastname,
                    'email' => $identity->email,
                    'workshopkey' => $workshopkey,
                    'workshopname' => $workshopname,
                    'academicyear' => $academicyear,
                    'attendance' => $attendance,
                    'grade' => $grade,
                    'hours' => $hours,
                    'passed' => $ispassed ? 1 : 0,
                    'sourcefile' => clean_param($filename, PARAM_FILE),
                    'timemodified' => $now,
                    'grademax' => $grademax,
                    'importid' => $batchid,
                ];
                if ($existing) {
                    $record->id = (int)$existing->id;
                    $record->timecreated = (int)$existing->timecreated;
                    $DB->update_record(self::TABLE, $record);
                    $updated++;
                } else {
                    $record->timecreated = $now;
                    $DB->insert_record(self::TABLE, $record);
                    $created++;
                }
            }
        }
        $DB->update_record(self::IMPORTS, (object)['id' => $batchid, 'created' => $created, 'updated' => $updated, 'passed' => $passed]);
        $transaction->allow_commit();
        @unlink(self::path_from_token($token));
        self::invalidate_hours_cache(array_values($linkeduserids));
        return (object)[
            'created' => $created, 'updated' => $updated, 'linked' => $linked,
            'pending' => $pending, 'passed' => $passed, 'importid' => $batchid,
        ];
    }

    private static function invalidate_hours_cache(array $userids): void {
        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return;
        }
        if (function_exists('block_gestion_hee_invalidate_users_cache')) {
            block_gestion_hee_invalidate_users_cache($userids);
        } else if (class_exists('\\block_gestion_hee\\local\\student_hours_cache')) {
            \block_gestion_hee\local\student_hours_cache::invalidate_users($userids);
        }
    }

    /** Import batches, newest first, with the number of rows each still owns. */
    public static function list_imports(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::IMPORTS))) {
            return [];
        }
        $imports = $DB->get_records(self::IMPORTS, null, 'timecreated DESC, id DESC');
        if ($imports) {
            $counts = $DB->get_records_sql("SELECT importid, COUNT(1) AS n, SUM(passed) AS passed,
                                                   COUNT(DISTINCT studentkey) AS students
                                              FROM {" . self::TABLE . "} GROUP BY importid");
            foreach ($imports as $import) {
                $c = $counts[$import->id] ?? null;
                $import->currentrows = $c ? (int)$c->n : 0;
                $import->currentpassed = $c ? (int)$c->passed : 0;
                $import->students = $c ? (int)$c->students : 0;
            }
        }
        return $imports;
    }

    /**
     * Annul an import: deletes the PAT rows whose last import is this one and
     * the batch itself. Rows later re-imported by another batch are kept.
     *
     * @return int rows deleted
     */
    public static function annul_import(int $importid): int {
        global $DB;
        $import = $DB->get_record(self::IMPORTS, ['id' => $importid], '*', MUST_EXIST);
        $userids = $DB->get_fieldset_select(self::TABLE, 'DISTINCT userid', 'importid = ? AND userid > 0', [$import->id]);
        $n = $DB->count_records(self::TABLE, ['importid' => $import->id]);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::TABLE, ['importid' => $import->id]);
        $DB->delete_records(self::IMPORTS, ['id' => $import->id]);
        $transaction->allow_commit();
        self::invalidate_hours_cache($userids);
        return $n;
    }

    /** Highest numeric grade found in each quiz column (helps choose the scale). */
    public static function max_grades(array $preview): array {
        $out = [];
        foreach ($preview['quizcols'] as $col => $name) {
            $max = null;
            foreach ($preview['rows'] as $row) {
                $v = self::numeric_value($row[$col] ?? null);
                if ($v !== null && ($max === null || $v > $max)) {
                    $max = $v;
                }
            }
            $out[$col] = $max;
        }
        return $out;
    }

    /** «4,00 / 5» */
    public static function grade_text(\stdClass $row): string {
        if ($row->grade === null || $row->grade === '') {
            return '-';
        }
        $max = isset($row->grademax) && (float)$row->grademax > 0 ? (float)$row->grademax : 10.0;
        return format_float((float)$row->grade, 2, true) . ' / ' . format_float($max, $max == (int)$max ? 0 : 2, true);
    }

    public static function reconcile_users(array $userids = []): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            return 0;
        }
        $params = [];
        $where = 'userid = 0';
        $users = [];
        if ($userids) {
            [$insql, $params] = $DB->get_in_or_equal(array_values(array_unique(array_map('intval', $userids))), SQL_PARAMS_NAMED, 'pu');
            $users = $DB->get_records_select('user', "id $insql AND deleted = 0", $params, '', 'id,idnumber,email,firstname,lastname');
        } else {
            return 0;
        }
        $linked = 0;
        foreach ($users as $user) {
            $idnumber = trim((string)$user->idnumber);
            $email = trim((string)$user->email);
            $conditions = []; $p = [];
            if ($idnumber !== '') {
                $conditions[] = '(' . $DB->sql_equal('TRIM(studentid)', ':sid', false, false)
                    . ' OR ' . $DB->sql_equal('TRIM(dni)', ':dni', false, false) . ')';
                $p['sid'] = $idnumber;
                $p['dni'] = $idnumber;
            }
            if ($email !== '') {
                $conditions[] = $DB->sql_equal('email', ':email', false, false);
                $p['email'] = $email;
            }
            if (!$conditions) { continue; }
            $records = $DB->get_records_select(self::TABLE, 'userid = 0 AND (' . implode(' OR ', $conditions) . ')', $p, '', 'id');
            foreach ($records as $record) {
                $DB->set_field(self::TABLE, 'userid', (int)$user->id, ['id' => (int)$record->id]);
                $linked++;
            }
        }
        return $linked;
    }

    public static function for_user(int $userid): array {
        global $DB;
        self::reconcile_users([$userid]);
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            return [];
        }
        return $DB->get_records(self::TABLE, ['userid' => $userid], 'academicyear DESC, workshopname ASC');
    }

    private static function resolve_user(\stdClass $identity): int {
        global $DB;
        // Institutional ID / DNI first, then e-mail. Case- and space-insensitive
        // (Moodle accounts may store «Dd4@UCV.es» or « 1234A»).
        foreach ([$identity->studentid, $identity->dni] as $idnumber) {
            $idnumber = trim($idnumber);
            if ($idnumber !== '') {
                $userid = self::find_user_by('idnumber', $idnumber);
                if ($userid) { return $userid; }
            }
        }
        if ($identity->email !== '') {
            $userid = self::find_user_by('email', $identity->email);
            if ($userid) { return $userid; }
        }
        return 0;
    }

    /** Single non-deleted user whose field matches the value ignoring case/spaces; 0 if none or ambiguous. */
    private static function find_user_by(string $field, string $value): int {
        global $DB;
        $select = 'deleted = 0 AND ' . $DB->sql_equal('TRIM(' . $field . ')', ':v', false, false);
        $ids = array_keys($DB->get_records_select('user', $select, ['v' => trim($value)], 'id', 'id', 0, 2));
        return count($ids) === 1 ? (int)$ids[0] : 0;
    }

    private static function identity_from_row(array $row, array $headers): \stdClass {
        $map = [];
        foreach ($headers as $i => $h) { $map[self::normalise_key($h)] = $i; }
        $get = static function(array $names) use ($row, $map): string {
            foreach ($names as $name) {
                $key = pat_typeb::normalise_key($name);
                if (isset($map[$key])) { return trim((string)($row[$map[$key]] ?? '')); }
            }
            return '';
        };
        $studentid = $get(['Número de ID','Numero de ID']);
        $dni = $get(['Departamento']);
        $email = \core_text::strtolower($get(['Dirección de correo','Direccion de correo','Correo']));
        $seed = $dni !== '' ? 'dni:' . self::normalise_key($dni)
            : ($studentid !== '' ? 'id:' . self::normalise_key($studentid) : ($email !== '' ? 'mail:' . $email : ''));
        return (object)[
            'studentkey' => $seed !== '' ? sha1($seed) : '',
            'studentid' => $studentid,
            'dni' => $dni,
            'email' => $email,
            'firstname' => $get(['Nombre']),
            'lastname' => $get(['Apellido(s)','Apellidos']),
        ];
    }

    private static function numeric_value($value): ?float {
        if ($value === null) { return null; }
        $text = trim((string)$value);
        if ($text === '' || $text === '-') { return null; }
        $text = str_replace(',', '.', $text);
        return is_numeric($text) ? (float)$text : null;
    }

    private static function normalise_key(string $value): string {
        $value = \core_text::strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value);
        return $value ?? '';
    }

    private static function clean_quiz_name(string $header): string {
        $name = preg_replace('/^Cuestionario\s*:\s*/iu', '', trim($header));
        $name = preg_replace('/\s*\(Real\)\s*$/iu', '', (string)$name);
        return trim((string)$name);
    }

    private static function read_xlsx(string $path): array {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            throw new \RuntimeException('PhpSpreadsheet no está disponible en esta instalación.');
        }
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) { $reader->setReadDataOnly(true); }
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheet(0);
            $data = $sheet->toArray('', true, true, false);
        } finally {
            if (method_exists($book, 'disconnectWorksheets')) { $book->disconnectWorksheets(); }
        }
        if (!$data) { throw new \RuntimeException('El Excel está vacío.'); }
        $headers = array_map(static fn($v) => trim((string)$v), array_shift($data));
        $quizcols = []; $attendancecol = -1;
        foreach ($headers as $i => $header) {
            if (preg_match('/^Cuestionario\s*:/iu', $header)) {
                $quizcols[$i] = self::clean_quiz_name($header);
            } else if ($attendancecol < 0 && preg_match('/^Asistencia\s*:/iu', $header)) {
                $attendancecol = $i;
            }
        }
        if (!$quizcols) { throw new \RuntimeException('No se han encontrado columnas de cuestionario.'); }
        if ($attendancecol < 0) { throw new \RuntimeException('No se ha encontrado la columna de asistencia.'); }
        $rows = array_values(array_filter($data, static function($row): bool {
            foreach ($row as $v) { if (trim((string)$v) !== '') { return true; } }
            return false;
        }));
        return ['headers' => $headers, 'quizcols' => $quizcols, 'attendancecol' => $attendancecol, 'rows' => $rows];
    }
}
