<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

class workshop_bulk_import {
    private const TEMPDIR = 'local_gestion_actividades/workshop_bulk_import';

    public static function save_uploaded_file(array $file): string {
        global $CFG;
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No se ha recibido ningún archivo Excel válido.');
        }
        $filename = clean_filename($file['name'] ?? 'talleres.xlsx');
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException('La plantilla debe estar en formato .xlsx.');
        }
        make_temp_directory(self::TEMPDIR);
        $token = random_string(24);
        $path = $CFG->tempdir . '/' . self::TEMPDIR . '/' . $token . '.xlsx';
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new \RuntimeException('No se ha podido guardar temporalmente el Excel.');
        }
        return $token;
    }

    public static function path_from_token(string $token): string {
        global $CFG;
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $token);
        if ($token === '') {
            throw new \RuntimeException('Token de importación no válido.');
        }
        return $CFG->tempdir . '/' . self::TEMPDIR . '/' . $token . '.xlsx';
    }

    public static function preview(string $token, int $courseid): array {
        global $DB;
        $rows = self::read_xlsx(self::path_from_token($token));
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $row['errors'] = [];
            $row['warnings'] = [];
            $row['courseid'] = $courseid;
            $code = trim((string)($row['code'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            $type = strtolower(trim((string)($row['type'] ?? 'typea')));
            if (!in_array($type, ['typea', 'typeb'], true)) {
                $type = 'typea';
            }
            $row['type'] = $type;
            if ($code === '') $row['errors'][] = 'Falta el código del taller.';
            if ($name === '') $row['errors'][] = 'Falta el nombre del taller.';
            if ($code !== '' && isset($seen[$code])) $row['errors'][] = 'Código repetido dentro del Excel.';
            $seen[$code] = true;

            $row['hours'] = self::decimal($row['hours'] ?? '');
            if ($row['hours'] <= 0) $row['errors'][] = 'Las horas deben ser superiores a 0.';
            $row['places'] = max(0, (int)self::decimal($row['places'] ?? 0));
            if ($row['places'] <= 0) $row['errors'][] = 'Las plazas deben ser superiores a 0.';

            $row['sessiondate'] = self::parse_datetime($row['date'] ?? '', $row['start'] ?? '');
            $row['enrolenddate'] = self::parse_datetime_value($row['enrolend'] ?? '');
            $row['quizclose'] = self::parse_datetime_value($row['quizclose'] ?? '');
            if ($row['sessiondate'] <= 0) $row['errors'][] = 'Fecha/hora del taller no válida.';
            if ($row['enrolenddate'] <= 0) $row['errors'][] = 'Fecha límite de inscripción no válida.';
            if ($row['sessiondate'] > 0 && $row['enrolenddate'] >= $row['sessiondate']) {
                $row['errors'][] = 'La inscripción debe cerrar antes de comenzar el taller.';
            }

            $row['createquiz'] = self::yes($row['createquiz'] ?? 'si');
            $row['createnotes'] = self::yes($row['createnotes'] ?? 'si');
            if ($type === 'typeb' && $row['createquiz']) {
                $row['warnings'][] = 'El Taller Tipo B no necesita cuestionario calificable; se ignorará esta opción.';
                $row['createquiz'] = false;
            }
            if ($row['createquiz'] && $row['quizclose'] <= 0) {
                $row['warnings'][] = 'No se indicó cierre de cuestionario; se conservará la fecha del cuestionario modelo.';
            }

            $teacheremail = core_text::strtolower(trim((string)($row['teacheremail'] ?? '')));
            $row['teacherids'] = [];
            if ($teacheremail !== '') {
                foreach (manager::get_course_teachers($courseid) as $teacher) {
                    if (core_text::strtolower(trim((string)$teacher->email)) === $teacheremail) {
                        $row['teacherids'][] = (int)$teacher->id;
                    }
                }
                if (!$row['teacherids']) $row['warnings'][] = 'No se ha encontrado en el curso el profesor ' . $teacheremail . '.';
            }

            if ($code !== '' && $DB->record_exists('local_ga_workshops', ['courseid' => $courseid, 'code' => $code])) {
                $row['errors'][] = 'Ya existe un taller con este código en el curso.';
            }
            $editioncode = trim((string)($row['editioncode'] ?? ''));
            if ($editioncode === '') $editioncode = preg_replace('/[^a-zA-Z0-9_-]/', '', $code) . '_E1';
            $row['editioncode'] = $editioncode;
            if ($editioncode === '') $row['errors'][] = 'No se ha podido generar el código de edición.';
            $row['ok'] = empty($row['errors']);
            $result[] = $row;
        }
        return $result;
    }

    public static function import(string $token, int $courseid, int $quiztemplatecmid = 0, int $notestemplatecmid = 0): \stdClass {
        global $DB;
        $rows = self::preview($token, $courseid);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $summary = (object)['created' => 0, 'skipped' => 0, 'quizcreated' => 0, 'notescreated' => 0, 'messages' => []];
        foreach ($rows as $row) {
            if (empty($row['ok'])) {
                $summary->skipped++;
                $summary->messages[] = ($row['code'] ?: 'Fila ' . $row['rownum']) . ': omitido por errores de validación.';
                continue;
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                $workshopid = manager::save_workshop((object)[
                    'id' => 0,
                    'courseid' => $courseid,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'description' => $row['description'] ?? '',
                    'hours' => $row['hours'],
                    'sectionnum' => 0,
                    'workshoptype' => $row['type'],
                ]);

                $editionid = manager::save_workshop_edition((object)[
                    'id' => 0,
                    'workshopid' => $workshopid,
                    'workshopname' => $row['name'],
                    'workshopdescription' => $row['description'] ?? '',
                    'workshophours' => $row['hours'],
                    'activityid' => 0,
                    'name' => $row['name'],
                    'editioncode' => $row['editioncode'],
                    'sessiondate' => $row['sessiondate'],
                    'enrolenddate' => $row['enrolenddate'],
                    'places' => $row['places'],
                    'groupid' => 0,
                    'attendancecmid' => 0,
                    'certificatecmid' => 0,
                    'requiredcmid' => 0,
                    'requiredmodname' => $row['type'] === 'typea' ? 'assign' : '',
                    'activitycreationtype' => $row['type'] === 'typea' ? 'assign' : '',
                    'status' => 'open',
                    'teachers' => $row['teacherids'],
                ]);

                $edition = manager::get_workshop_edition($editionid);
                $groupid = (int)($edition->groupid ?? 0);

                if (!empty($row['createquiz'])) {
                    if ($quiztemplatecmid <= 0) {
                        throw new \RuntimeException('La fila solicita cuestionario pero no se ha seleccionado un cuestionario modelo.');
                    }
                    $quizcmid = self::duplicate_template($course, $quiztemplatecmid, $groupid, 'Cuestionario ' . $row['code'], $row['quizclose']);
                    if ($quizcmid > 0) {
                        $columns = $DB->get_columns('local_ga_workshop_editions');
                        $update = (object)['id' => $editionid, 'requiredcmid' => $quizcmid, 'requiredmodname' => 'quiz', 'activitycreationtype' => 'quiz', 'timemodified' => time()];
                        if (isset($columns['requiredquizcmid'])) $update->requiredquizcmid = $quizcmid;
                        if (isset($columns['requiredassigncmid'])) $update->requiredassigncmid = 0;
                        $DB->update_record('local_ga_workshop_editions', $update);
                        $summary->quizcreated++;
                    }
                }

                if (!empty($row['createnotes']) && $notestemplatecmid > 0) {
                    $notescmid = self::duplicate_template($course, $notestemplatecmid, $groupid, 'Apuntes ' . $row['code'], 0);
                    if ($notescmid > 0) $summary->notescreated++;
                }

                manager::ensure_workshop_course_visuals_safely($workshopid);
                $transaction->allow_commit();
                $summary->created++;
                $summary->messages[] = $row['code'] . ': creado correctamente.';
            } catch (\Throwable $e) {
                $transaction->rollback($e);
                $summary->skipped++;
                $summary->messages[] = $row['code'] . ': ' . $e->getMessage();
            }
        }
        @unlink(self::path_from_token($token));
        return $summary;
    }

    public static function quiz_templates(int $courseid): array {
        return self::module_templates($courseid, 'quiz');
    }

    public static function notes_templates(int $courseid): array {
        global $DB;
        $out = ['0' => 'No duplicar recurso de apuntes'];
        foreach (['folder', 'resource'] as $modname) {
            foreach (self::module_templates($courseid, $modname, false) as $id => $name) {
                $out[$id] = $name;
            }
        }
        return $out;
    }

    private static function module_templates(int $courseid, string $modname, bool $withnone = true): array {
        global $DB;
        $out = $withnone ? ['0' => 'Selecciona un ' . ($modname === 'quiz' ? 'cuestionario modelo' : 'recurso modelo')] : [];
        $sql = "SELECT cm.id, x.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modname}} x ON x.id = cm.instance
                 WHERE cm.course = :courseid AND m.name = :modname AND cm.deletioninprogress = 0
              ORDER BY x.name";
        foreach ($DB->get_records_sql($sql, ['courseid' => $courseid, 'modname' => $modname]) as $r) {
            $out[(int)$r->id] = format_string($r->name) . ' (CMID ' . (int)$r->id . ')';
        }
        return $out;
    }

    private static function duplicate_template(\stdClass $course, int $templatecmid, int $groupid, string $name, int $timeclose = 0): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $modinfo = get_fast_modinfo($course);
        $template = $modinfo->get_cm($templatecmid);
        if (!$template || (int)$template->course !== (int)$course->id) {
            throw new \RuntimeException('La actividad modelo no pertenece al curso seleccionado.');
        }
        if (!function_exists('duplicate_module')) {
            throw new \RuntimeException('Esta instalación de Moodle no expone la función estándar duplicate_module().');
        }
        $newcm = duplicate_module($course, $template);
        if (!$newcm) {
            throw new \RuntimeException('Moodle no ha podido duplicar la actividad modelo.');
        }
        $newcmid = (int)(is_object($newcm) ? ($newcm->id ?? 0) : $newcm);
        if ($newcmid <= 0) {
            rebuild_course_cache($course->id, true);
            $candidate = $DB->get_record_sql("SELECT cm.id FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module WHERE cm.course=:courseid AND m.name=:modname ORDER BY cm.id DESC", ['courseid' => $course->id, 'modname' => $template->modname], IGNORE_MULTIPLE);
            $newcmid = $candidate ? (int)$candidate->id : 0;
        }
        if ($newcmid <= 0) throw new \RuntimeException('No se ha podido localizar la copia de la actividad modelo.');
        $cm = get_coursemodule_from_id('', $newcmid, $course->id, false, MUST_EXIST);
        if (!empty($cm->instance) && $DB->get_manager()->table_exists(new \xmldb_table($cm->modname))) {
            $columns = $DB->get_columns($cm->modname);
            $record = (object)['id' => (int)$cm->instance];
            if (isset($columns['name'])) $record->name = $name;
            if ($cm->modname === 'quiz' && $timeclose > 0 && isset($columns['timeclose'])) $record->timeclose = $timeclose;
            $DB->update_record($cm->modname, $record);
        }
        if ($groupid > 0) {
            $availability = json_encode(['op' => '&', 'c' => [['type' => 'group', 'id' => $groupid]], 'showc' => [false]], JSON_UNESCAPED_SLASHES);
            $DB->set_field('course_modules', 'availability', $availability, ['id' => $newcmid]);
        }
        rebuild_course_cache($course->id, true);
        return $newcmid;
    }

    public static function send_template(): void {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            throw new \RuntimeException('PhpSpreadsheet no está disponible; no se puede generar la plantilla XLSX automáticamente.');
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('TALLERES');
        $headers = ['Código', 'Nombre', 'Tipo', 'Descripción', 'Fecha', 'Inicio', 'Fin', 'Horas', 'Plazas', 'Cierre inscripción', 'Email profesor', 'Crear apuntes', 'Crear cuestionario', 'Cierre cuestionario', 'Código edición'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray(['TALLER-01', 'Nombre del taller', 'A', '', '19/09/2026', '12:30', '14:30', 2, 25, '12/09/2026 23:59', '', 'Sí', 'Sí', '23/09/2026 23:59', 'TALLER01_E1'], null, 'A2');
        foreach (range('A', 'O') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->freezePane('A2');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $temp = tempnam(make_temp_directory(self::TEMPDIR), 'tpl_');
        $writer->save($temp);
        send_temp_file($temp, 'Plantilla_importacion_masiva_talleres.xlsx');
        exit;
    }

    private static function read_xlsx(string $path): array {
        if (!is_readable($path)) throw new \RuntimeException('No se puede leer el Excel subido.');
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            throw new \RuntimeException('Esta importación requiere PhpSpreadsheet, disponible en las instalaciones Moodle compatibles con este proyecto.');
        }
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName('TALLERES') ?: $book->getSheet(0);
            $data = $sheet->toArray('', true, true, false);
        } finally {
            if (method_exists($book, 'disconnectWorksheets')) $book->disconnectWorksheets();
        }
        if (!$data) return [];
        $headers = array_map([self::class, 'header'], array_shift($data));
        $map = [];
        foreach ($headers as $i => $h) $map[$h] = $i;
        $aliases = [
            'code' => ['codigo','codigotaller','taller'], 'name' => ['nombre','actividad','nombretaller'], 'type' => ['tipo','tipotaller'],
            'description' => ['descripcion'], 'date' => ['fecha'], 'start' => ['inicio','horainicio'], 'end' => ['fin','horafin'],
            'hours' => ['horas'], 'places' => ['plazas'], 'enrolend' => ['cierreinscripcion','fininscripcion','fechalimiteinscripcion'],
            'teacheremail' => ['emailprofesor','profesor','correoprofesor'], 'createnotes' => ['crearapuntes','apuntes'],
            'createquiz' => ['crearcuestionario','cuestionario'], 'quizclose' => ['cier cuestionario','cierrecuestionario','fincuestionario'],
            'editioncode' => ['codigoedicion','edicion'],
        ];
        $cols = [];
        foreach ($aliases as $key => $candidates) {
            $cols[$key] = null;
            foreach ($candidates as $candidate) if (array_key_exists($candidate, $map)) { $cols[$key] = $map[$candidate]; break; }
        }
        $out = [];
        foreach ($data as $idx => $cells) {
            $row = ['rownum' => $idx + 2];
            $nonempty = false;
            foreach ($cols as $key => $col) {
                $value = $col === null ? '' : trim((string)($cells[$col] ?? ''));
                $row[$key] = $value;
                if ($value !== '') $nonempty = true;
            }
            if (!$nonempty) continue;
            if ($row['type'] !== '') $row['type'] = in_array(core_text::strtolower($row['type']), ['b','typeb','tipo b'], true) ? 'typeb' : 'typea';
            $out[] = $row;
        }
        return $out;
    }

    private static function header(string $value): string {
        $value = core_text::strtolower(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    private static function decimal($value): float {
        $value = str_replace([' ', ','], ['', '.'], trim((string)$value));
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private static function yes($value): bool {
        return in_array(self::header((string)$value), ['si','yes','1','true','x'], true);
    }

    private static function parse_datetime(string $date, string $time): int {
        return self::parse_datetime_value(trim($date . ' ' . $time));
    }

    private static function parse_datetime_value(string $value): int {
        $value = trim($value);
        if ($value === '') return 0;
        foreach (['d/m/Y H:i','d/m/Y H:i:s','Y-m-d H:i','Y-m-d H:i:s','d/m/Y'] as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $value);
            if ($dt && $dt->format($format) === $value) return $dt->getTimestamp();
        }
        $ts = strtotime(str_replace('T', ' ', $value));
        return $ts ?: 0;
    }
}
