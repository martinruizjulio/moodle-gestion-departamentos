<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

class portfolio_pdf {
    public static function default_cover_template(): string {
        return '<h1 style="text-align:center;color:#2b4b1e;font-size:26pt;">Portafolio de certificados</h1>' .
               '<h2 style="text-align:center;color:#222;font-size:18pt;">{alumno}</h2>' .
               '<p style="text-align:center;font-size:13pt;">Curso: <strong>{curso}</strong></p>' .
               '<p style="text-align:center;font-size:12pt;line-height:1.6;">Horas Tipo A: <strong>{horas_tipo_a}</strong><br>Horas Tipo B: <strong>{horas_tipo_b}</strong><br>Total reconocido: <strong>{horas_total}</strong></p>' .
               '<p style="text-align:center;color:#666;font-size:10pt;">Fecha de emisión: {fecha_emision}</p>';
    }

    public static function get_cover_template(): string {
        $value = get_config('local_gestion_actividades', 'portfoliocoverhtml');
        if ($value === false || trim((string)$value) === '') {
            return self::default_cover_template();
        }
        return (string)$value;
    }

    public static function save_cover_template(string $html): void {
        set_config('portfoliocoverhtml', $html, 'local_gestion_actividades');
    }

    public static function get_typea_hours(int $userid): float {
        $hours = 0.0;
        if (class_exists('local_gestion_actividades\\local\\manager') && method_exists(manager::class, 'get_student_total_hours')) {
            $hours = (float)manager::get_student_total_hours($userid);
        }
        $certsum = self::sum_typea_certificate_hours($userid);
        return max($hours, $certsum);
    }

    public static function sum_typea_certificate_hours(int $userid): float {
        $total = 0.0;
        foreach (self::get_typea_certificates($userid) as $cert) {
            if (isset($cert->hours) && $cert->hours !== null && $cert->hours !== '') {
                $total += (float)$cert->hours;
            }
        }
        return $total;
    }

    public static function get_typea_certificates(int $userid): array {
        if (class_exists('local_gestion_actividades\\local\\manager') && method_exists(manager::class, 'list_user_certificates')) {
            $certs = manager::list_user_certificates($userid);
            usort($certs, function($a, $b) {
                return ((int)($a->timeissued ?? 0)) <=> ((int)($b->timeissued ?? 0));
            });
            return $certs;
        }
        return [];
    }

    public static function sum_certificate_hours(array $certs): float {
        $sum = 0.0;
        foreach ($certs as $c) {
            $sum += (float)($c->hours ?? 0);
        }
        return $sum;
    }

    public static function get_typeb_certificates(int $userid): array {
        $certs = array_values(array_filter(portfolio_typeb::list_for_user($userid), function($cert) {
            return portfolio_typeb::is_countable($cert);
        }));
        usort($certs, function($a, $b) {
            return ((int)($a->activitydate ?? 0)) <=> ((int)($b->activitydate ?? 0));
        });
        return $certs;
    }

    public static function replace_cover_placeholders(string $html, \stdClass $user, string $course, float $typeahours, float $typebhours): string {
        $replacements = [
            '{alumno}' => fullname($user),
            '{curso}' => $course,
            '{horas_tipo_a}' => self::format_hours($typeahours),
            '{horas_tipo_b}' => self::format_hours($typebhours),
            '{horas_total}' => self::format_hours($typeahours + $typebhours),
            '{fecha_emision}' => userdate(time(), get_string('strftimedatefullshort', 'langconfig')),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    public static function format_hours(float $hours): string {
        return rtrim(rtrim(number_format($hours, 2, ',', '.'), '0'), ',') . ' h';
    }

    public static function detect_course_label(array $typeacerts, array $typebcerts): string {
        foreach ($typeacerts as $c) {
            if (!empty($c->coursename)) {
                return format_string($c->coursename);
            }
        }
        return get_string('site');
    }

    public static function apply_ucv_background(\pdf $pdf): void {
        $bg = dirname(__DIR__, 2) . '/pix/certificate_ucv_bg.jpg';
        if (file_exists($bg)) {
            $pdf->Image($bg, 0, 0, 210, 297, '', '', '', false, 300, '', false, false, 0);
        }
    }

    public static function add_ucv_page(\pdf $pdf): void {
        $pdf->AddPage();
        self::apply_ucv_background($pdf);
        $pdf->SetXY(25, 35);
    }

    public static function typeb_status_label(string $status): string {
        if ($status === 'validated') {
            return 'Validado y completado';
        }
        if ($status === portfolio_typeb::STATUS_VALIDATED_PENDING_REFLECTION) {
            return 'Validado · falta reflexión';
        }
        if ($status === 'pending') {
            return 'Pendiente';
        }
        if ($status === 'rejected') {
            return 'Rechazado';
        }
        return $status;
    }

    private static function write_certificate_card(\pdf $pdf, string $title, array $rows): void {
        $html = '<div style="border:1px solid #d8d8d8;border-radius:6px;padding:9px 10px;margin-bottom:8px;">';
        $html .= '<h3 style="font-size:12.5pt;color:#2b4b1e;margin:0 0 5px 0;">' . s($title) . '</h3>';
        foreach ($rows as $label => $value) {
            $html .= '<p style="font-size:10.5pt;margin:2px 0;"><strong>' . s($label) . ':</strong> ' . s((string)$value) . '</p>';
        }
        $html .= '</div>';
        $pdf->writeHTML($html, true, false, true, false, '');
    }

    public static function render_pdf_string(int $userid): string {
        global $DB, $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        portfolio_typeb::ensure_table();

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $typeacerts = self::get_typea_certificates($userid);
        $typebworkshopcerts = manager::list_user_typeb_workshop_certificates($userid);
        $typebcerts = self::get_typeb_certificates($userid);
        $typeahours = self::get_typea_hours($userid);
        $institutional = class_exists('local_gestion_actividades\\local\\institutional_hours') ? institutional_hours::list_for_user($userid) : [];
        $typebhours = portfolio_typeb::total_validated_hours($userid) + self::sum_certificate_hours($typebworkshopcerts) + (class_exists('local_gestion_actividades\\local\\institutional_hours') ? institutional_hours::total_typeb_hours($userid) : 0.0);
        $course = self::detect_course_label($typeacerts, $typebcerts);

        $pdf = new \pdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Gestion_actividades');
        $pdf->SetAuthor('Gestion_actividades');
        $pdf->SetTitle('Portafolio de certificados - ' . fullname($user));
        $pdf->SetMargins(25, 35, 25);
        $pdf->SetAutoPageBreak(true, 24);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        self::add_ucv_page($pdf);
        $cover = self::replace_cover_placeholders(self::get_cover_template(), $user, $course, $typeahours, $typebhours);
        $pdf->writeHTML('<div style="margin-top:42mm;">' . $cover . '</div>', true, false, true, false, '');

        self::add_ucv_page($pdf);
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->writeHTML('<h1 style="color:#2b4b1e;">Índice</h1>', true, false, true, false, '');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->writeHTML('<ol style="font-size:12pt;line-height:1.7;"><li>Portada</li><li>Resumen de horas</li><li>Certificados de Talleres Tipo A</li><li>Talleres Tipo B y formaciones externas validadas</li><li>Reconocimiento institucional</li></ol>', true, false, true, false, '');

        self::add_ucv_page($pdf);
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->writeHTML('<h1 style="color:#2b4b1e;">Resumen de horas</h1>', true, false, true, false, '');
        $pdf->SetFont('helvetica', '', 11);
        self::write_certificate_card($pdf, 'Horas reconocidas', [
            'Talleres Tipo A' => self::format_hours($typeahours),
            'Talleres Tipo B' => self::format_hours($typebhours),
            'Total reconocido' => self::format_hours($typeahours + $typebhours),
        ]);

        self::add_ucv_page($pdf);
        $pdf->writeHTML('<h1 style="color:#2b4b1e;">Certificados de Talleres Tipo A</h1>', true, false, true, false, '');
        if ($typeacerts) {
            foreach ($typeacerts as $c) {
                self::write_certificate_card($pdf, trim(($c->workshopcode ?? '') . ' - ' . ($c->workshopname ?? '')), [
                    'Curso' => $c->coursename ?? '-',
                    'Horas' => !empty($c->hours) ? self::format_hours((float)$c->hours) : '-',
                    'Fecha de emisión' => !empty($c->timeissued) ? userdate((int)$c->timeissued, get_string('strftimedatefullshort', 'langconfig')) : '-',
                    'Estado' => 'Generado',
                ]);
            }
        } else {
            $pdf->writeHTML('<p>No constan certificados Tipo A generados.</p>', true, false, true, false, '');
        }

        self::add_ucv_page($pdf);
        $pdf->writeHTML('<h1 style="color:#2b4b1e;">Talleres Tipo B</h1>', true, false, true, false, '');
        if ($typebworkshopcerts) {
            foreach ($typebworkshopcerts as $c) {
                self::write_certificate_card($pdf, trim(($c->workshopcode ?? '') . ' - ' . ($c->workshopname ?? '')), [
                    'Curso' => $c->coursename ?? '-',
                    'Horas' => !empty($c->hours) ? self::format_hours((float)$c->hours) : '-',
                    'Asistencia' => 'Confirmada',
                    'Reflexión' => 'Entregada',
                    'Resultado' => 'Apto',
                    'Fecha de emisión' => !empty($c->timeissued) ? userdate((int)$c->timeissued, get_string('strftimedatefullshort', 'langconfig')) : '-',
                ]);
            }
        } else {
            $pdf->writeHTML('<p>No constan certificados de Talleres Tipo B internos.</p>', true, false, true, false, '');
        }

        $pdf->writeHTML('<h2 style="color:#2b4b1e;">Formaciones externas reconocidas como Tipo B</h2>', true, false, true, false, '');
        if ($typebcerts) {
            foreach ($typebcerts as $c) {
                self::write_certificate_card($pdf, (string)($c->activityname ?? 'Formación externa Tipo B'), [
                    'Fecha de la actividad' => !empty($c->activitydate) ? userdate((int)$c->activitydate, get_string('strftimedatefullshort', 'langconfig')) : '-',
                    'Horas reconocidas' => self::format_hours((float)($c->hours ?? 0)),
                    'Estado' => 'Validado y completado como Tipo B',
                    'Justificación del alumno' => !empty($c->activitydescription) ? (string)$c->activitydescription : '-',
                    'Reflexión' => !empty($c->reflectiontext) ? (string)$c->reflectiontext : '-',
                    'Comentario de validación' => !empty($c->reviewcomment) ? (string)$c->reviewcomment : '-',
                    'Archivo acreditativo' => !empty($c->filename) ? (string)$c->filename : '-',
                ]);
            }
        } else {
            $pdf->writeHTML('<p>No constan formaciones externas validadas y completadas como Tipo B.</p>', true, false, true, false, '');
        }

        self::add_ucv_page($pdf);
        $pdf->writeHTML('<h1 style="color:#2b4b1e;">Reconocimiento institucional</h1>', true, false, true, false, '');
        if (!empty($institutional)) {
            foreach ($institutional as $r) {
                if ((float)$r->typeahours > 0) {
                    self::write_certificate_card($pdf, 'Reconocimiento institucional - Horas Tipo A', [
                        'Origen' => $r->source ?? 'Reconocimiento institucional',
                        'Curso' => $r->courselevel ?? '-',
                        'Grupo' => $r->groupname ?? '-',
                        'Horas' => self::format_hours((float)$r->typeahours),
                        'Asistencia' => 'Confirmada',
                        'Tarea' => 'Entregada',
                        'Nota Taller A' => ($r->taskgrade !== null && $r->taskgrade !== '') ? format_float((float)$r->taskgrade, 2, true) : '-',
                    ]);
                }
                if ((float)$r->typebhours > 0) {
                    self::write_certificate_card($pdf, 'Reconocimiento institucional - Horas Tipo B', [
                        'Origen' => $r->source ?? 'Reconocimiento institucional',
                        'Curso' => $r->courselevel ?? '-',
                        'Grupo' => $r->groupname ?? '-',
                        'Horas' => self::format_hours((float)$r->typebhours),
                        'Asistencia' => 'Confirmada',
                        'Tarea' => 'No aplica',
                        'Calificación' => 'No aplica',
                        'Texto alumno' => !empty($r->typebreflection) ? (string)$r->typebreflection : 'Pendiente',
                    ]);
                }
            }
        } else {
            $pdf->writeHTML('<p>No constan horas de reconocimiento institucional.</p>', true, false, true, false, '');
        }

        $pdf->writeHTML('<p style="color:#666;font-size:9pt;">Documento generado automáticamente por Gestion_actividades.</p>', true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    public static function filename_for_user(\stdClass $user): string {
        return clean_filename('portafolio_certificados_' . fullname($user) . '.pdf');
    }
}
