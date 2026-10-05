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

            if (!empty($teachersummary['total'])) {
                $this->content->text = $this->render_teacher_tools($teachersummary);
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

        $html .= $this->render_metric(get_string('typeahours', 'block_gestion_hee'), $typeahours, 'badge-success');
        $html .= $this->render_metric(get_string('typebhours', 'block_gestion_hee'), $typebhours, 'badge-success');
        $html .= html_writer::tag('hr', '');
        $html .= $this->render_metric(get_string('totalhours', 'block_gestion_hee'), $total, 'badge-success');

        $remainingclass = $remaining <= 0 ? 'badge-success' : 'local-ga-badge-remaining';
        $html .= $this->render_metric(get_string('remaininghours', 'block_gestion_hee'), $remaining, $remainingclass);

        if (!empty($summary['error'])) {
            $html .= html_writer::div(get_string('temporarilyunavailable', 'block_gestion_hee'), 'text-muted small mt-1');
        } else if (!empty($summary['stale'])) {
            $html .= html_writer::div(get_string('cachedstale', 'block_gestion_hee'), 'text-muted small mt-1');
        }

        $html .= html_writer::start_div('mt-2');
        $transfereligible = $typeahours > 32.0 && $typebhours < 22.0;
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/transfer_typeb.php'),
            get_string('transfertypeb', 'block_gestion_hee'),
            [
                'class' => 'btn btn-sm ' . ($transfereligible ? 'btn-warning' : 'btn-outline-secondary') . ' btn-block mb-1',
                'title' => $transfereligible
                    ? 'Puedes consultar y realizar los traspasos disponibles.'
                    : 'Consulta aquí las condiciones y los talleres que pueden traspasarse.',
            ]
        );
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/typeb_upload.php'),
            'Solicitar validación Tipo B',
            [
                'class' => 'btn btn-sm btn-outline-secondary btn-block mb-1',
                'title' => 'Sube un certificado de formación externa para solicitar su reconocimiento como Taller Tipo B.',
            ]
        );
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/portfolio.php'),
            get_string('myportfolio', 'block_gestion_hee'),
            ['class' => 'btn btn-sm btn-outline-secondary btn-block']
        );
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();

        return $html;
    }

    private function render_teacher_tools(array $summary): string {
        $active = (int)($summary['activecount'] ?? 0);
        $finished = (int)($summary['finishedcount'] ?? 0);

        $html = html_writer::start_div('block-gestion-hee-teacher-tools mt-3 pt-2 border-top');
        $html .= html_writer::tag('h5', 'Profesor HEE', ['class' => 'mb-1']);
        $html .= html_writer::tag('p', 'Gestiona únicamente los talleres HEE que tienes asignados.', ['class' => 'text-muted small mb-2']);
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/my_workshops.php', ['view' => 'active']),
            'Talleres vigentes (' . $active . ')',
            ['class' => 'btn btn-sm btn-primary btn-block mb-1']
        );
        $html .= html_writer::link(
            new moodle_url('/local/gestion_actividades/my_workshops.php', ['view' => 'finished']),
            'Mis talleres finalizados (' . $finished . ')',
            ['class' => 'btn btn-sm btn-outline-secondary btn-block']
        );
        $html .= html_writer::end_div();
        return $html;
    }

    private function render_metric(string $label, float $value, string $badgeclass = 'badge-secondary'): string {
        $valueformatted = format_float($value, 2, true) . ' h';
        $content = html_writer::span(s($label), 'local-ga-label');
        $content .= html_writer::span($valueformatted, 'badge ' . $badgeclass . ' float-right');
        return html_writer::div($content, 'mb-2');
    }
}
