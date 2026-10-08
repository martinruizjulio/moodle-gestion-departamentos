<?php
defined('MOODLE_INTERNAL') || die();

class block_gestion_hee extends block_base {

    public function init(): void {
        $this->title = get_string('title', 'block_gestion_hee');
    }

    public function applicable_formats(): array {
        return [
            'course-view' => true,
            'my' => true,
            'site-index' => false,
            'mod' => false,
        ];
    }

    public function instance_allow_multiple(): bool {
        return false;
    }

    public function has_config(): bool {
        return false;
    }

    public function get_content() {
        global $USER, $DB;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';

        if (empty($USER->id) || isguestuser()) {
            return $this->content;
        }

        try {
            $teachersummary = \block_gestion_hee\local\teacher_workshops_cache::get_summary((int)$USER->id);

            // A newly assigned Profesor HEE may still have a cached zero summary for a few
            // minutes. Check the canonical assignment table before falling back to the
            // student view so the teacher tools appear immediately after assignment.
            if (empty($teachersummary['total'])
                    && $DB->get_manager()->table_exists(new xmldb_table('local_ga_edition_teachers'))
                    && $DB->record_exists('local_ga_edition_teachers', ['userid' => (int)$USER->id])) {
                \block_gestion_hee\local\teacher_workshops_cache::invalidate_user((int)$USER->id);
                $teachersummary = \block_gestion_hee\local\teacher_workshops_cache::get_summary((int)$USER->id);
            }

            $ismanager = $this->is_hee_manager((int)$USER->id);
            $isprofhee = !empty($teachersummary['total']);
            if ($ismanager || $isprofhee || $this->is_course_teacher((int)$USER->id)) {
                // Staff never see the student hour summary (0 h / 54 h).
                $this->content->text = $this->render_staff_view($ismanager, $isprofhee ? $teachersummary : null);
            } else {
                $summary = \block_gestion_hee\local\student_hours_cache::get_summary((int)$USER->id);
                $this->content->text = $this->render_summary($summary);
            }
        } catch (Throwable $e) {
            if (function_exists('debugging')) {
                debugging('No se ha podido renderizar block_gestion_hee: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $this->content->text = html_writer::div(
                get_string('temporarilyunavailable', 'block_gestion_hee'),
                'text-muted small'
            );
        }

        return $this->content;
    }

    private function render_summary(array $summary): string {
        $typeahours = (float)($summary['typeahours'] ?? 0);
        $typebhours = (float)($summary['typebhours'] ?? 0);
        $total = (float)($summary['total'] ?? ($typeahours + $typebhours));
        $remaining = (float)($summary['remaining'] ?? max(0, 54 - $total));

        $html = html_writer::tag('style', '.block-gestion-hee-student-summary .local-ga-badge-remaining{background:#d96c06;color:#fff;}');
        $html .= html_writer::start_div('block-gestion-hee-student-summary');

        if ($total <= 0) {
            $html .= html_writer::tag('p', get_string('nohoursyet', 'block_gestion_hee'), ['class' => 'text-muted']);
        }

        $html .= $this->render_metric(get_string('typeahours', 'block_gestion_hee'), $typeahours, 'bg-success');
        $html .= $this->render_metric(get_string('typebhours', 'block_gestion_hee'), $typebhours, 'bg-success');
        $html .= html_writer::tag('hr', '');
        $html .= $this->render_metric(get_string('totalhours', 'block_gestion_hee'), $total, 'bg-success');

        $remainingclass = $remaining <= 0 ? 'bg-success' : 'local-ga-badge-remaining';
        $html .= $this->render_metric(get_string('remaininghours', 'block_gestion_hee'), $remaining, $remainingclass);

        if (!empty($summary['error'])) {
            $html .= html_writer::div(get_string('temporarilyunavailable', 'block_gestion_hee'), 'text-muted small mt-1');
        } else if (!empty($summary['stale'])) {
            $html .= html_writer::div(get_string('cachedstale', 'block_gestion_hee'), 'text-muted small mt-1');
        }

        // Keep the student help immediately below the hour summary so it is always
        // easy to find without adding another page to the course navigation.
        $html .= $this->render_student_help();

        $html .= html_writer::start_div('mt-2');
        $transfereligible = $typeahours > 32.0 && $typebhours < 22.0;
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/transfer_typeb.php', $this->course_params()),
            get_string('transfertypeb', 'block_gestion_hee'),
            [
                'class' => 'btn btn-sm ' . ($transfereligible ? 'btn-warning' : 'btn-outline-secondary') . ' d-block w-100 mb-1',
                'title' => $transfereligible
                    ? 'Puedes consultar y realizar los traspasos disponibles.'
                    : 'Consulta aquí las condiciones y los talleres que pueden traspasarse.',
            ]
        );
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/typeb_upload.php', $this->course_params()),
            'Solicitar validación Tipo B',
            [
                'class' => 'btn btn-sm btn-outline-secondary d-block w-100 mb-1',
                'title' => 'Sube un certificado de formación externa para solicitar su reconocimiento como Taller Tipo B.',
            ]
        );
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/portfolio.php', $this->course_params()),
            get_string('myportfolio', 'block_gestion_hee'),
            ['class' => 'btn btn-sm btn-outline-secondary d-block w-100']
        );
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();

        return $html;
    }

    private function render_student_help(): string {
        $body = html_writer::tag('p', get_string('studenthelpintro', 'block_gestion_hee'), ['class' => 'lead fs-6']);
        foreach (['join', 'a', 'b', 'bexternal', 'certs', 'transfer', 'selfassessment'] as $key) {
            $body .= $this->render_help_section('studenthelp' . $key . '_title', 'studenthelp' . $key . '_text');
        }
        $body .= html_writer::div(get_string('studenthelpfooter', 'block_gestion_hee'), 'alert alert-info mb-0');
        return $this->render_help_modal('block-gestion-hee-student-help', get_string('studenthelpbutton', 'block_gestion_hee'),
            'btn btn-warning fw-bold w-100 py-2', get_string('studenthelptitle', 'block_gestion_hee'), $body);
    }

    private function render_teacher_help(): string {
        $body = html_writer::tag('p', get_string('teacherhelpintro', 'block_gestion_hee'), ['class' => 'lead fs-6']);
        foreach (['common', 'a', 'b', 'end', 'roles'] as $key) {
            $body .= $this->render_help_section('teacherhelp' . $key . '_title', 'teacherhelp' . $key . '_text');
        }
        $body .= html_writer::div(get_string('teacherhelpfooter', 'block_gestion_hee'), 'alert alert-info mb-0');
        return $this->render_help_modal('block-gestion-hee-teacher-help', get_string('teacherhelpbutton', 'block_gestion_hee'),
            'btn btn-info fw-bold w-100 py-2 text-white', get_string('teacherhelptitle', 'block_gestion_hee'), $body);
    }

    /** Button + Bootstrap modal (moved to <body> so the block drawer does not clip it). */
    private function render_help_modal(string $modalid, string $buttonlabel, string $buttonclass, string $title, string $body): string {
        $html = html_writer::start_div('mt-2 mb-2');
        $html .= html_writer::tag('button', $buttonlabel, [
            'type' => 'button',
            'class' => $buttonclass,
            'data-bs-toggle' => 'modal',
            'data-bs-target' => '#' . $modalid,
            // Bootstrap 4 attributes for themes/sites still on Moodle 4.x.
            'data-toggle' => 'modal',
            'data-target' => '#' . $modalid,
            'aria-controls' => $modalid,
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::start_div('modal fade', [
            'id' => $modalid,
            'tabindex' => '-1',
            'aria-labelledby' => $modalid . '-title',
            'aria-hidden' => 'true',
        ]);
        $html .= html_writer::start_div('modal-dialog modal-lg modal-dialog-scrollable');
        $html .= html_writer::start_div('modal-content');
        $html .= html_writer::start_div('modal-header');
        $html .= html_writer::tag('h5', $title, ['class' => 'modal-title', 'id' => $modalid . '-title']);
        $html .= html_writer::tag('button', '', [
            'type' => 'button',
            'class' => 'btn-close',
            'data-bs-dismiss' => 'modal',
            'data-dismiss' => 'modal',
            'aria-label' => get_string('close', 'core'),
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::div($body, 'modal-body');
        $html .= html_writer::start_div('modal-footer');
        $html .= html_writer::tag('button', get_string('close', 'core'), [
            'type' => 'button',
            'class' => 'btn btn-secondary',
            'data-bs-dismiss' => 'modal',
            'data-dismiss' => 'modal',
        ]);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $this->page->requires->js_init_code(
            "(function(){var m=document.getElementById(" . json_encode($modalid) . ");" .
            "if(m&&m.parentNode!==document.body){document.body.appendChild(m);}})();"
        );
        return $html;
    }

    /** Site admin or Gestor HEE (Usuarios autorizados). */
    private function is_hee_manager(int $userid): bool {
        if (is_siteadmin($userid)) {
            return true;
        }
        try {
            if (class_exists('\\local_gestion_actividades\\local\\manager')) {
                return \local_gestion_actividades\local\manager::can_manage_globally($userid);
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Teacher-type role (editing teacher, non-editing teacher or manager
     * archetype): in this course on a course page, in any course on the
     * Dashboard. Students keep the student view.
     */
    private function is_course_teacher(int $userid): bool {
        global $DB;
        $roleids = $DB->get_fieldset_select('role', 'id', "archetype IN ('editingteacher', 'teacher', 'manager')");
        if (!$roleids) {
            return false;
        }
        [$in, $params] = $DB->get_in_or_equal(array_map('intval', $roleids), SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $course = $this->page->course ?? null;
        if ($course && (int)$course->id !== (int)SITEID) {
            $context = \context_course::instance((int)$course->id);
            if (has_capability('moodle/course:update', $context, $userid, false)) {
                return true;
            }
            $params['ctx'] = $context->id;
            return $DB->record_exists_select('role_assignments', "userid = :userid AND contextid = :ctx AND roleid $in", $params);
        }
        $params['level'] = CONTEXT_COURSE;
        return $DB->record_exists_sql("SELECT 1 FROM {role_assignments} ra JOIN {context} c ON c.id = ra.contextid
                                        WHERE ra.userid = :userid AND c.contextlevel = :level AND ra.roleid $in", $params);
    }

    private function render_staff_view(bool $ismanager, ?array $teachersummary): string {
        $html = html_writer::start_div('block-gestion-hee-staff');
        if ($ismanager) {
            $html .= html_writer::link(
                // Carry the current course so «Volver al curso» returns here.
                new moodle_url('/local/gestion_actividades/dashboard.php',
                    $this->course_params()),
                'Panel de Gestión HEE',
                ['class' => 'btn btn-sm btn-primary d-block w-100 mb-1']
            );
        }
        if ($teachersummary) {
            $html .= $this->render_teacher_tools($teachersummary);
        } else if (!$ismanager) {
            $html .= html_writer::tag('p', 'Todavía no tienes talleres HEE asignados. Cuando se te asigne uno aparecerá aquí.',
                ['class' => 'text-muted small mb-2']);
        }
        $html .= html_writer::start_div('mt-3 pt-2 border-top');
        $html .= $this->render_teacher_help();
        $html .= $this->render_student_help();
        $html .= html_writer::tag('p', 'Las instrucciones para alumnos son las mismas que ve el alumnado.', ['class' => 'text-muted small mb-0']);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        return $html;
    }

    private function render_help_section(string $titlekey, string $textkey): string {
        $html = html_writer::tag('h6', get_string($titlekey, 'block_gestion_hee'), ['class' => 'fw-bold mt-3 mb-1']);
        $html .= html_writer::tag('p', get_string($textkey, 'block_gestion_hee'), ['class' => 'mb-2']);
        return $html;
    }

    private function render_teacher_tools(array $summary): string {
        $active = (int)($summary['activecount'] ?? 0);
        $finished = (int)($summary['finishedcount'] ?? 0);

        $html = html_writer::start_div('block-gestion-hee-teacher-tools mt-2');
        $html .= html_writer::tag('p', 'Gestiona únicamente los talleres HEE que tienes asignados.', ['class' => 'text-muted small mb-2']);
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/my_workshops.php', ['view' => 'active']),
            'Talleres vigentes (' . $active . ')',
            ['class' => 'btn btn-sm btn-primary d-block w-100 mb-1']
        );
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/my_workshops.php', ['view' => 'finished']),
            'Mis talleres finalizados (' . $finished . ')',
            ['class' => 'btn btn-sm btn-outline-secondary d-block w-100']
        );
        $html .= html_writer::end_div();
        return $html;
    }

    /** ['courseid' => N] on a course page, so «Volver al curso» returns here. */
    private function course_params(): array {
        $course = $this->page->course ?? null;
        return ($course && (int)$course->id !== (int)SITEID) ? ['courseid' => (int)$course->id] : [];
    }

    private function render_metric(string $label, float $value, string $badgeclass = 'bg-secondary'): string {
        $valueformatted = format_float($value, 2, true) . ' h';
        $content = html_writer::span(s($label), 'local-ga-label');
        $content .= html_writer::span($valueformatted, 'badge ' . $badgeclass . ' float-end');
        return html_writer::div($content, 'mb-2');
    }
}
