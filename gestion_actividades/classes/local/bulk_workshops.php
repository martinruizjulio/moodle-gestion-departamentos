<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

class bulk_workshops {
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
        $target = $CFG->tempdir . '/' . self::TEMPDIR . '/' . $token . '.xlsx';
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('No se ha podido guardar temporalmente el Excel.');
        }
        return $token;
    }

    public static function path_from_token(string $token): string {
        global $CFG;
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $token);
        if ($token === '') throw new \RuntimeException('Token de importación no válido.');
        return $CFG->tempdir . '/' . self::TEMPDIR . '/' . $token . '.xlsx';
    }

    public static function preview(string $token, int $courseid): array {
        global $DB;
        $rows = self::read_xlsx(self::path_from_token($token));
        $teachers = manager::get_course_teachers($courseid);
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $row['errors'] = [];
            $row['warnings'] = [];
            $row['type'] = in_array(\core_text::strtolower(trim((string)$row['type'])), ['b','typeb','tipo b'], true) ? 'typeb' : 'typea';
            $row['code'] = trim((string)$row['code']);
            $row['name'] = trim((string)$row['name']);
            $row['hours'] = self::decimal($row['hours']);
            $row['places'] = max(0, (int)self::decimal($row['places']));
            $row['sessiondate'] = self::parse_datetime(trim($row['date'] . ' ' . $row['start']));
            $row['sessionenddate'] = self::parse_datetime(trim($row['date'] . ' ' . $row['end']));
            $row['enrolenddate'] = self::parse_datetime($row['enrolend']);
            $row['quizclose'] = self::parse_datetime($row['quizclose']);
            $row['createquiz'] = self::yes($row['createquiz']);
            $row['createnotes'] = self::yes($row['createnotes']);
            $row['teacherids'] = [];

            if ($row['code'] === '') $row['errors'][] = 'Falta el código del taller.';
            if ($row['name'] === '') $row['errors'][] = 'Falta el nombre del taller.';
            if ($row['hours'] <= 0) $row['errors'][] = 'Las horas deben ser superiores a 0.';
            if ($row['places'] <= 0) $row['errors'][] = 'Las plazas deben ser superiores a 0.';
            if ($row['sessiondate'] <= 0) $row['errors'][] = 'Fecha/hora de inicio del taller no válida.';
            if ($row['sessionenddate'] <= 0) $row['errors'][] = 'Hora de fin del taller no válida.';
            if ($row['sessiondate'] > 0 && $row['sessionenddate'] > 0 && $row['sessionenddate'] <= $row['sessiondate']) $row['errors'][] = 'La hora de fin debe ser posterior a la hora de inicio.';
            if ($row['enrolenddate'] <= 0) $row['errors'][] = 'Fecha límite de inscripción no válida.';
            if ($row['sessiondate'] > 0 && $row['enrolenddate'] >= $row['sessiondate']) $row['errors'][] = 'La inscripción debe cerrar antes del taller.';
            if ($row['code'] !== '' && isset($seen[$row['code']])) $row['errors'][] = 'Código repetido dentro del Excel.';
            $seen[$row['code']] = true;
            if ($row['code'] !== '' && $DB->record_exists('local_ga_workshops', ['courseid' => $courseid, 'code' => $row['code']])) $row['errors'][] = 'Ya existe ese código en el curso.';

            if ($row['type'] === 'typeb') {
                if ($row['createquiz']) {
                    $row['createquiz'] = false;
                    $row['warnings'][] = 'Tipo B: el cuestionario se ignora; se creará automáticamente una tarea Moodle de reflexión.';
                }
                $row['warnings'][] = 'Tipo B: se creará automáticamente una tarea de reflexión con texto en línea y archivo adjunto opcional.';
            }
            if ($row['createquiz'] && $row['quizclose'] <= 0) $row['warnings'][] = 'Sin cierre de cuestionario: conservará la fecha del modelo.';

            $email = \core_text::strtolower(trim((string)$row['teacheremail']));
            if ($email !== '') {
                foreach ($teachers as $teacher) {
                    if (\core_text::strtolower(trim((string)$teacher->email)) === $email) $row['teacherids'][] = (int)$teacher->id;
                }
                if (!$row['teacherids']) $row['warnings'][] = 'Profesor no encontrado en el curso: ' . $email . '.';
            }

            $editioncode = trim((string)$row['editioncode']);
            if ($editioncode === '') $editioncode = preg_replace('/[^a-zA-Z0-9_-]/', '', $row['code']) . '_E1';
            $row['editioncode'] = $editioncode;
            if ($editioncode === '') $row['errors'][] = 'No se pudo generar el código de edición.';
            $row['ok'] = !$row['errors'];
            $out[] = $row;
        }
        return $out;
    }

    public static function import(string $token, int $courseid, int $seriesid, int $quiztemplatecmid = 0, int $notestemplatecmid = 0): \stdClass {
        global $DB, $CFG;
        $series = workshop_series::get($seriesid);
        if ((int)$series->courseid !== $courseid) {
            throw new \RuntimeException('La edición de talleres no pertenece al curso seleccionado.');
        }
        $rows = self::preview($token, $courseid);
        $validrows = array_values(array_filter($rows, function($row) { return !empty($row['ok']); }));
        $minstart = 0;
        $maxend = 0;
        foreach ($validrows as $row) {
            $minstart = $minstart > 0 ? min($minstart, (int)$row['sessiondate']) : (int)$row['sessiondate'];
            $maxend = max($maxend, (int)$row['sessionenddate']);
        }
        // Seminars outside the Edición's range widen the Edición (same rule as
        // the manual form) instead of aborting the import.
        $seriesextension = $validrows ? workshop_series::extend_to_cover($seriesid, $minstart, $maxend) : null;
        if ($seriesextension) {
            $series = workshop_series::get($seriesid);
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $summary = (object)[
            'created' => 0,
            'skipped' => count($rows) - count($validrows),
            'quizcreated' => 0,
            'reflectioncreated' => 0,
            'notescreated' => 0,
            'seriesid' => $seriesid,
            'messages' => $seriesextension ? [workshop_series::extension_message($seriesextension)] : [],
        ];
        $transaction = $DB->start_delegated_transaction();
        // New editions get their seriesid in attach_workshop(); until then the
        // legacy section renderer must not see them as legacy seminars.
        manager::suspend_legacy_section_sync(true);
        $suspended = true;
        try {
            $order = workshop_series::next_sortorder($seriesid);
            foreach ($validrows as $row) {
                $workshopid = manager::save_workshop((object)[
                    'id' => 0, 'courseid' => $courseid, 'code' => $row['code'], 'name' => $row['name'],
                    'description' => $row['description'], 'hours' => $row['hours'], 'sectionnum' => 0, 'workshoptype' => $row['type'],
                ]);
                $defaultactivitytype = $row['type'] === 'typeb' ? 'assign' : 'quiz';
                $editionid = manager::save_workshop_edition((object)[
                    'id' => 0, 'workshopid' => $workshopid, 'workshopname' => $row['name'],
                    'workshopdescription' => $row['description'], 'workshophours' => $row['hours'], 'activityid' => 0,
                    'name' => $row['name'], 'editioncode' => $row['editioncode'], 'sessiondate' => $row['sessiondate'],
                    'enrolenddate' => $row['enrolenddate'], 'places' => $row['places'], 'groupid' => 0,
                    'attendancecmid' => 0, 'certificatecmid' => 0, 'requiredcmid' => 0,
                    'requiredmodname' => $defaultactivitytype,
                    'activitycreationtype' => $defaultactivitytype,
                    'status' => 'open', 'teachers' => $row['teacherids'],
                ]);
                // Link the edition to its Edición now (attach_workshop() below
                // confirms it) so generated activities are created inside the
                // Edición and never in a legacy TALLERES TIPO A/B section.
                if (array_key_exists('seriesid', $DB->get_columns('local_ga_workshop_editions'))) {
                    $DB->set_field('local_ga_workshop_editions', 'seriesid', $seriesid, ['id' => $editionid]);
                }
                $edition = manager::get_workshop_edition($editionid);

                // Keep bulk creation aligned with the canonical rule: every new
                // Type A edition is quiz-first even when the Excel row chooses not
                // to duplicate a quiz template yet.
                if ($row['type'] === 'typea') {
                    $columns = $DB->get_columns('local_ga_workshop_editions');
                    $update = (object)[
                        'id' => $editionid,
                        'requiredcmid' => 0,
                        'requiredmodname' => 'quiz',
                        'activitycreationtype' => 'quiz',
                        'timemodified' => time(),
                    ];
                    if (isset($columns['requiredquizcmid'])) $update->requiredquizcmid = 0;
                    if (isset($columns['requiredassigncmid'])) $update->requiredassigncmid = 0;
                    $DB->update_record('local_ga_workshop_editions', $update);
                    $edition = manager::get_workshop_edition($editionid);
                }

                // The edition group must exist before any Moodle activity
                // is created so Attendance, quiz/reflection and resources are
                // restricted correctly from the start.
                $groupid = manager::get_or_create_edition_group($editionid);
                $edition = manager::get_workshop_edition($editionid);
                $notescmid = 0;

                if ($row['type'] === 'typeb') {
                    $attendance = manager::create_attendance_activity_for_edition(
                        $editionid,
                        'Asistencia T' . sprintf('%02d', $order)
                    );
                    if (empty($attendance->success)) {
                        throw new \RuntimeException($row['code'] . ': ' . ($attendance->message ?? 'no se pudo crear la lista de asistencia.'));
                    }
                    $reflection = typeb_reflection_activity::ensure_for_edition($editionid);
                    if (empty($reflection->success)) {
                        throw new \RuntimeException($row['code'] . ': ' . ($reflection->message ?? 'no se pudo crear la tarea de reflexión Tipo B.'));
                    }
                    $summary->reflectioncreated++;
                } else if ($quiztemplatecmid > 0) {
                    $attendance = manager::create_attendance_activity_for_edition(
                        $editionid,
                        'Asistencia T' . sprintf('%02d', $order)
                    );
                    if (empty($attendance->success)) {
                        throw new \RuntimeException($row['code'] . ': ' . ($attendance->message ?? 'no se pudo crear la lista de asistencia.'));
                    }
                    // Optional advanced path: explicitly duplicate one selected
                    // model instead of creating the normal empty quiz.
                    require_once($CFG->dirroot . '/course/lib.php');
                    $quizcmid = self::duplicate_template(
                        $course,
                        $quiztemplatecmid,
                        $groupid,
                        'Cuestionario T' . sprintf('%02d', (int)$order),
                        $row['quizclose']
                    );
                    $columns = $DB->get_columns('local_ga_workshop_editions');
                    $update = (object)[
                        'id' => $editionid,
                        'requiredcmid' => $quizcmid,
                        'requiredmodname' => 'quiz',
                        'activitycreationtype' => 'quiz',
                        'timemodified' => time(),
                    ];
                    if (isset($columns['requiredquizcmid'])) $update->requiredquizcmid = $quizcmid;
                    if (isset($columns['requiredassigncmid'])) $update->requiredassigncmid = 0;
                    $DB->update_record('local_ga_workshop_editions', $update);
                    // Same group linkage as the default quiz: group restriction,
                    // separate groups with the edition grouping, visible inside
                    // its TALLER subsection.
                    manager::restrict_required_activity_to_edition_group($editionid, $quizcmid);
                    $cmcolumns = $DB->get_columns('course_modules');
                    foreach (['visible', 'visibleold', 'visibleoncoursepage'] as $field) {
                        if (isset($cmcolumns[$field])) {
                            $DB->set_field('course_modules', $field, 1, ['id' => $quizcmid]);
                        }
                    }
                    if (isset($cmcolumns['idnumber'])) {
                        $DB->set_field('course_modules', 'idnumber', 'HEE_EDITION_' . $editionid . '_REQUIRED_QUIZ', ['id' => $quizcmid]);
                    }
                    $summary->quizcreated++;
                } else {
                    $defaults = manager::ensure_typea_default_activities($editionid, $order);
                    if (empty($defaults->success) || empty($defaults->quizcmid)) {
                        throw new \RuntimeException($row['code'] . ': ' . ($defaults->message ?? 'no se pudo preparar asistencia y cuestionario.'));
                    }
                    $quizcmid = (int)$defaults->quizcmid;
                    $summary->quizcreated++;
                }
                if (!empty($row['createnotes']) && $notestemplatecmid > 0) {
                    $notescmid = self::duplicate_template($course, $notestemplatecmid, $groupid, 'Apuntes ' . $row['code'], 0);
                    $summary->notescreated++;
                }
                workshop_series::attach_workshop($seriesid, $workshopid, $order, $notescmid, $row['sessionenddate'], $editionid);
                // Session duration uses the Excel end time stored by attach_workshop().
                attendance_sync::ensure_session($editionid);

                if ($row['type'] === 'typea') {
                    $savededition = manager::get_workshop_edition($editionid);
                    $finalquizcmid = !empty($savededition->requiredquizcmid)
                        ? (int)$savededition->requiredquizcmid
                        : (int)($savededition->requiredcmid ?? 0);

                    // Validate against the database, not get_fast_modinfo /
                    // get_coursemodule_from_id. At this point we are still in
                    // the delegated transaction and the quiz may just have been
                    // moved into a delegated subsection, so modinfo can be stale.
                    $finalquizcm = $finalquizcmid > 0
                        ? $DB->get_record_sql(
                            "SELECT cm.id
                               FROM {course_modules} cm
                               JOIN {modules} m ON m.id = cm.module
                               JOIN {quiz} q ON q.id = cm.instance
                              WHERE cm.id = :cmid
                                AND cm.course = :courseid
                                AND m.name = 'quiz'",
                            ['cmid' => $finalquizcmid, 'courseid' => $courseid],
                            IGNORE_MISSING
                        )
                        : false;
                    if (!$finalquizcm) {
                        throw new \RuntimeException(
                            $row['code'] . ': el cuestionario Tipo A no existe o no quedó vinculado en la base de datos Moodle.'
                        );
                    }
                }

                $summary->created++;
                $summary->messages[] = $row['code'] . ': creado como Taller ' . sprintf('%02d', $order) . ' de ' . $series->title . '.';
                $order++;
            }
            manager::suspend_legacy_section_sync(false);
            $suspended = false;
            workshop_series::ensure_course_structure($seriesid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($suspended) {
                manager::suspend_legacy_section_sync(false);
            }
            $transaction->rollback($e);
        }
        @unlink(self::path_from_token($token));
        return $summary;
    }

    public static function quiz_templates(int $courseid): array {
        return self::module_templates($courseid, 'quiz', true);
    }

    public static function notes_templates(int $courseid): array {
        $out = [0 => 'No duplicar recurso de apuntes'];
        foreach (['folder', 'resource'] as $modname) {
            foreach (self::module_templates($courseid, $modname, false) as $id => $label) $out[$id] = $label;
        }
        return $out;
    }

    private static function module_templates(int $courseid, string $modname, bool $placeholder): array {
        global $DB;
        $out = $placeholder ? [0 => 'No duplicar modelo · crear cuestionario vacío automáticamente'] : [];
        $sql = "SELECT cm.id, cm.idnumber, x.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modname}} x ON x.id = cm.instance
                 WHERE cm.course = :courseid
                   AND m.name = :modname
                   AND cm.deletioninprogress = 0
              ORDER BY x.name";
        foreach ($DB->get_records_sql($sql, ['courseid' => $courseid, 'modname' => $modname]) as $row) {
            if ($modname === 'quiz'
                    && (strpos((string)($row->idnumber ?? ''), 'HEE_') === 0
                        || preg_match('/^Cuestionario T-\\d+$/u', trim((string)$row->name)))) {
                continue;
            }
            $out[(int)$row->id] = format_string($row->name) . ' (CMID ' . (int)$row->id . ')';
        }
        return $out;
    }

    private static function duplicate_template(\stdClass $course, int $templatecmid, int $groupid, string $name, int $timeclose): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $template = get_fast_modinfo($course)->get_cm($templatecmid);
        if (!$template || (int)$template->course !== (int)$course->id) throw new \RuntimeException('La actividad modelo no pertenece al curso.');
        if (!function_exists('duplicate_module')) throw new \RuntimeException('Moodle no permite duplicar módulos mediante la API estándar en esta instalación.');
        $copy = duplicate_module($course, $template);
        $newcmid = is_object($copy) ? (int)($copy->id ?? 0) : (int)$copy;
        if ($newcmid <= 0) throw new \RuntimeException('No se ha podido duplicar la actividad modelo.');
        $cm = get_coursemodule_from_id('', $newcmid, $course->id, false, MUST_EXIST);
        $table = $cm->modname;
        if ($DB->get_manager()->table_exists(new \xmldb_table($table))) {
            $columns = $DB->get_columns($table);
            $record = (object)['id' => (int)$cm->instance];
            if (isset($columns['name'])) $record->name = $name;
            if ($table === 'quiz' && $timeclose > 0 && isset($columns['timeclose'])) $record->timeclose = $timeclose;
            $DB->update_record($table, $record);
        }
        $cmcolumns = $DB->get_columns('course_modules');
        if (isset($cmcolumns['visible'])) {
            $DB->set_field('course_modules', 'visible', 1, ['id' => $newcmid]);
        }
        if (isset($cmcolumns['visibleoncoursepage'])) {
            $DB->set_field('course_modules', 'visibleoncoursepage', 1, ['id' => $newcmid]);
        }
        if (isset($cmcolumns['idnumber'])) {
            $DB->set_field('course_modules', 'idnumber', 'HEE_DUPLICATED_' . strtoupper($table) . '_' . $newcmid, ['id' => $newcmid]);
        }
        if ($groupid > 0) {
            $availability = json_encode(['op' => '&', 'c' => [['type' => 'group', 'id' => $groupid]], 'showc' => [true]], JSON_UNESCAPED_SLASHES);
            $DB->set_field('course_modules', 'availability', $availability, ['id' => $newcmid]);
        }
        rebuild_course_cache($course->id, true);
        return $newcmid;
    }

    public static function send_template(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) throw new \RuntimeException('PhpSpreadsheet no está disponible para generar la plantilla XLSX.');
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('TALLERES');
        // Sheet TALLERES: headers only. It used to carry one Tipo A and one
        // Tipo B example row; an Edición cannot mix A and B, so a template
        // filled in on top of the examples failed or imported the examples.
        $headers = ['Código','Nombre','Tipo','Descripción','Fecha','Inicio','Fin','Horas','Plazas','Cierre inscripción',
            'Email profesor','Crear apuntes','Cierre cuestionario','Código edición'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        // Dates and times as text, so Excel does not reformat them on typing.
        foreach (['E', 'F', 'G', 'J', 'M'] as $col) {
            $sheet->getStyle($col . '2:' . $col . '300')->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        }
        // Drop-down lists for Tipo (A/B) and Crear apuntes (Sí/No).
        foreach (['C' => '"A,B"', 'L' => '"Sí,No"'] as $col => $list) {
            $validation = $sheet->getCell($col . '2')->getDataValidation();
            $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1($list);
            $sheet->setDataValidation($col . '2:' . $col . '300', $validation);
        }
        foreach (range('A','N') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->freezePane('A2');

        // Sheet INSTRUCCIONES (never imported: only the TALLERES sheet is read).
        $help = $book->createSheet();
        $help->setTitle('INSTRUCCIONES');
        $help->fromArray([
            ['Cómo rellenar la hoja TALLERES'],
            ['Una fila por taller; el orden de las filas es el orden TALLER 01, TALLER 02...'],
            ['Un mismo Excel solo puede contener talleres de Tipo A o solo de Tipo B (importa cada tipo por separado).'],
            ['Formatos: fecha dd/mm/aaaa · horas de inicio y fin hh:mm · cierres dd/mm/aaaa hh:mm · horas con decimales permitidos (1,5).'],
            ['Cierre cuestionario: solo Tipo A; déjalo vacío si aún no lo sabes (se puede fijar después en el cuestionario).'],
            ['Email profesor: correo del profesor del curso que impartirá el taller (opcional).'],
            ['Código edición: opcional; si se deja vacío se genera como CÓDIGO_E1.'],
            [''],
            ['Ejemplo Tipo A (no copiar en la misma hoja que uno de Tipo B):'],
            $headers,
            ['TALLER-01','Nombre del taller Tipo A','A','','19/09/2026','12:30','14:30',2,25,'12/09/2026 23:59','','Sí','23/09/2026 23:59','TALLER01_E1'],
            [''],
            ['Ejemplo Tipo B:'],
            $headers,
            ['TALLER-B01','Nombre del taller Tipo B','B','','20/09/2026','10:00','12:00',2,25,'13/09/2026 23:59','','Sí','','TALLERB01_E1'],
        ], null, 'A1');
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $help->getStyle('A10:N10')->getFont()->setBold(true);
        $help->getStyle('A14:N14')->getFont()->setBold(true);
        foreach (range('A','N') as $col) $help->getColumnDimension($col)->setAutoSize(true);
        $book->setActiveSheetIndex(0);
        $path = tempnam(make_temp_directory(self::TEMPDIR), 'tpl_');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        \send_temp_file($path, 'Plantilla_importacion_masiva_talleres.xlsx');
        exit;
    }

    private static function read_xlsx(string $path): array {
        if (!is_readable($path)) throw new \RuntimeException('No se puede leer el Excel subido.');
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) throw new \RuntimeException('Esta instalación no dispone de PhpSpreadsheet para leer .xlsx.');
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName('TALLERES') ?: $book->getSheet(0);
            $data = $sheet->toArray('', true, true, false);
        } finally {
            if (method_exists($book, 'disconnectWorksheets')) $book->disconnectWorksheets();
        }
        if (!$data) return [];
        $headers = array_map([self::class, 'normalise'], array_shift($data));
        $index = [];
        foreach ($headers as $i => $name) $index[$name] = $i;
        $aliases = [
            'code' => ['codigo','codigotaller','taller'], 'name' => ['nombre','actividad','nombretaller'], 'type' => ['tipo','tipotaller'],
            'description' => ['descripcion'], 'date' => ['fecha'], 'start' => ['inicio','horainicio'], 'end' => ['fin','horafin'],
            'hours' => ['horas'], 'places' => ['plazas'], 'enrolend' => ['cierreinscripcion','fininscripcion','fechalimiteinscripcion'],
            'teacheremail' => ['emailprofesor','profesor','correoprofesor'], 'createnotes' => ['crearapuntes','apuntes'],
            'createquiz' => ['crearcuestionario','cuestionario'], 'quizclose' => ['cierrecuestionario','fincuestionario'], 'editioncode' => ['codigoedicion','edicion'],
        ];
        $cols = [];
        foreach ($aliases as $key => $names) {
            $cols[$key] = null;
            foreach ($names as $name) if (array_key_exists($name, $index)) { $cols[$key] = $index[$name]; break; }
        }
        $rows = [];
        foreach ($data as $i => $cells) {
            $row = ['rownum' => $i + 2];
            $hasdata = false;
            foreach ($cols as $key => $col) {
                $value = $col === null ? '' : trim((string)($cells[$col] ?? ''));
                $row[$key] = $value;
                if ($value !== '') $hasdata = true;
            }
            if ($hasdata) $rows[] = $row;
        }
        return $rows;
    }

    private static function normalise(string $value): string {
        $value = \core_text::strtolower(trim($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) $value = $ascii;
        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    private static function decimal($value): float {
        $value = str_replace([' ', ','], ['', '.'], trim((string)$value));
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private static function yes($value): bool {
        return in_array(self::normalise((string)$value), ['si','yes','1','true','x'], true);
    }

    private static function parse_datetime($value): int {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        return date_helper::parse_user_datetime($value);
    }
}
