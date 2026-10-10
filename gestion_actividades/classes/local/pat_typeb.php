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

    public static function import(string $token, string $academicyear, array $hoursbycol, string $filename = ''): \stdClass {
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
        }

        $now = time();
        $created = 0; $updated = 0; $linked = 0; $pending = 0; $passed = 0;
        $transaction = $DB->start_delegated_transaction();
        foreach ($parsed['rows'] as $row) {
            $identity = self::identity_from_row($row, $parsed['headers']);
            if ($identity->studentkey === '') {
                continue;
            }
            $userid = self::resolve_user($identity);
            if ($userid > 0) { $linked++; } else { $pending++; }

            $attendance = self::numeric_value($row[$parsed['attendancecol']] ?? null);
            foreach ($parsed['quizcols'] as $col => $workshopname) {
                $grade = self::numeric_value($row[$col] ?? null);
                $hours = (float)$hoursbycol[$col];
                $ispassed = $attendance !== null && $attendance >= 100.0 && $grade !== null && $grade >= 5.0;
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
        $transaction->allow_commit();
        @unlink(self::path_from_token($token));
        return (object)[
            'created' => $created, 'updated' => $updated, 'linked' => $linked,
            'pending' => $pending, 'passed' => $passed,
        ];
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
            $idnumber = self::normalise_key((string)$user->idnumber);
            $email = \core_text::strtolower(trim((string)$user->email));
            $conditions = []; $p = [];
            if ($idnumber !== '') {
                $conditions[] = '(studentid = :sid OR dni = :dni)';
                $p['sid'] = (string)$user->idnumber;
                $p['dni'] = (string)$user->idnumber;
            }
            if ($email !== '') {
                $conditions[] = 'email = :email';
                $p['email'] = (string)$user->email;
            }
            if (!$conditions) { continue; }
            $records = $DB->get_records_select(self::TABLE, 'userid = 0 AND (' . implode(' OR ', $conditions) . ')', $p);
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
        foreach ([$identity->studentid, $identity->dni] as $idnumber) {
            if ($idnumber !== '') {
                $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0], 'id', IGNORE_MULTIPLE);
                if ($user) { return (int)$user->id; }
            }
        }
        if ($identity->email !== '') {
            $user = $DB->get_record('user', ['email' => $identity->email, 'deleted' => 0], 'id', IGNORE_MULTIPLE);
            if ($user) { return (int)$user->id; }
        }
        return 0;
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
