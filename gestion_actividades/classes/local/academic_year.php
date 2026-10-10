<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Academic year («Curso académico») helpers. Format «2026/2027».
 *
 * The current academic year changes automatically on 1 September.
 * Ediciones de talleres store their own year (editable); everything else
 * (Tipo B externo, traspasos, reconocimientos, certificados sueltos) takes
 * the year of the date it was created. The year is only used to filter
 * and sort listings: hours keep accumulating across the whole degree.
 */
class academic_year {
    /** URL parameter used by every listing. */
    public const PARAM = 'cursoac';
    /** Value meaning «all academic years». */
    public const ALL = 'all';

    /** Academic year a timestamp belongs to (1 September boundary). */
    public static function for_time(int $timestamp): string {
        if ($timestamp <= 0) {
            $timestamp = time();
        }
        $year = (int)userdate($timestamp, '%Y');
        $month = (int)userdate($timestamp, '%m');
        $start = $month >= 9 ? $year : $year - 1;
        return $start . '/' . ($start + 1);
    }

    /** Current academic year. */
    public static function current(): string {
        return self::for_time(time());
    }

    /** Whether the text is a valid «YYYY/YYYY+1» academic year. */
    public static function is_valid(string $value): bool {
        if (!preg_match('~^(\d{4})/(\d{4})$~', trim($value), $m)) {
            return false;
        }
        return (int)$m[2] === (int)$m[1] + 1 && (int)$m[1] >= 2000 && (int)$m[1] <= 2100;
    }

    /** Normalise «2026-27», «2026/27», «2026-2027» to «2026/2027»; '' if not valid. */
    public static function normalise(string $value): string {
        $value = trim($value);
        if (preg_match('~^(\d{4})\s*[/-]\s*(\d{2}|\d{4})$~', $value, $m)) {
            $start = (int)$m[1];
            $end = strlen($m[2]) === 2 ? (int)(substr($m[1], 0, 2) . $m[2]) : (int)$m[2];
            $candidate = $start . '/' . $end;
            return self::is_valid($candidate) ? $candidate : '';
        }
        return '';
    }

    /** Year of an Edición de talleres (stored value, or derived from its start date). */
    public static function for_series(?\stdClass $series): string {
        if (!$series) {
            return '';
        }
        $stored = (string)($series->academicyear ?? '');
        if (self::is_valid($stored)) {
            return $stored;
        }
        return self::for_time((int)($series->datefrom ?? 0) ?: (int)($series->timecreated ?? 0));
    }

    /** Cached map seriesid => academic year. */
    public static function series_map(): array {
        global $DB;
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_series'))) {
            return $map;
        }
        foreach ($DB->get_records('local_ga_workshop_series') as $series) {
            $map[(int)$series->id] = self::for_series($series);
        }
        return $map;
    }

    /** Year of a listing row: its Edición de talleres if any, otherwise the given date. */
    public static function for_row(int $seriesid, int $fallbacktime): string {
        $map = self::series_map();
        if ($seriesid > 0 && isset($map[$seriesid])) {
            return $map[$seriesid];
        }
        return self::for_time($fallbacktime);
    }

    /**
     * Selected filter from the URL: a valid year, or '' for all.
     * Defaults to the current academic year.
     */
    public static function selected(bool $defaultall = false): string {
        $value = optional_param(self::PARAM, '', PARAM_RAW_TRIMMED);
        if ($value === self::ALL) {
            return '';
        }
        if ($value === '') {
            return $defaultall ? '' : self::current();
        }
        $value = self::normalise($value);
        return $value !== '' ? $value : self::current();
    }

    /** URL value for the selected filter. */
    public static function param_value(string $selected): string {
        return $selected === '' ? self::ALL : $selected;
    }

    /** Whether a row year passes the filter. */
    public static function matches(string $selected, string $rowyear): bool {
        return $selected === '' || $selected === $rowyear;
    }

    /**
     * Years to offer in the selector: every year with data, the current one
     * and the next one (for Ediciones prepared in advance), newest first.
     */
    public static function options(array $extra = []): array {
        $years = [self::current() => true];
        foreach (self::series_map() as $year) {
            if ($year !== '') {
                $years[$year] = true;
            }
        }
        foreach (self::data_years() as $year) {
            $years[$year] = true;
        }
        foreach ($extra as $year) {
            if (self::is_valid((string)$year)) {
                $years[(string)$year] = true;
            }
        }
        $list = array_keys($years);
        rsort($list);
        return $list;
    }

    /** Years derived from dated records (Tipo B, traspasos, reconocimientos, certificados). */
    private static function data_years(): array {
        global $DB;
        static $years = null;
        if ($years !== null) {
            return $years;
        }
        $years = [];
        $sources = [
            'local_ga_typeb_certs' => 'timecreated',
            'local_ga_typeb_transfers' => 'timecreated',
            'local_ga_institutional_hours' => 'timemodified',
            'local_ga_certificates' => 'timeissued',
            'local_ga_workshop_editions' => 'sessiondate',
        ];
        $dbman = $DB->get_manager();
        foreach ($sources as $table => $field) {
            if (!$dbman->table_exists(new \xmldb_table($table))) {
                continue;
            }
            try {
                $range = $DB->get_record_sql("SELECT MIN($field) AS minv, MAX($field) AS maxv FROM {{$table}} WHERE $field > 0");
            } catch (\Throwable $e) {
                continue;
            }
            if (!$range || empty($range->minv)) {
                continue;
            }
            $from = (int)explode('/', self::for_time((int)$range->minv))[0];
            $to = (int)explode('/', self::for_time((int)$range->maxv))[0];
            for ($y = $from; $y <= $to && $y - $from < 30; $y++) {
                $years[] = $y . '/' . ($y + 1);
            }
        }
        if ($dbman->table_exists(new \xmldb_table('local_ga_typeb_pat'))) {
            foreach ($DB->get_fieldset_sql("SELECT DISTINCT academicyear FROM {local_ga_typeb_pat}") as $year) {
                if (self::is_valid((string)$year)) {
                    $years[] = (string)$year;
                }
            }
        }
        return $years;
    }

    /**
     * Selector «Curso académico» (GET form) keeping the other URL params.
     *
     * @param \moodle_url $base page URL with its own params (without the year)
     */
    public static function selector(\moodle_url $base, string $selected, string $note = ''): string {
        $options = [self::ALL => 'Todos los cursos'];
        foreach (self::options($selected !== '' ? [$selected] : []) as $year) {
            $options[$year] = $year . ($year === self::current() ? ' (actual)' : '');
        }
        $hidden = '';
        foreach ($base->params() as $name => $value) {
            if ($name === self::PARAM || is_array($value)) {
                continue;
            }
            $hidden .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        $select = \html_writer::select($options, self::PARAM, self::param_value($selected), false,
            ['class' => 'form-select form-select-sm d-inline-block w-auto', 'id' => 'local-ga-cursoac', 'onchange' => 'this.form.submit()']);
        $form = \html_writer::tag('form',
            \html_writer::label('Curso académico:', 'local-ga-cursoac', false, ['class' => 'fw-bold me-2 mb-0'])
            . $select . $hidden
            . \html_writer::tag('noscript', \html_writer::tag('button', 'Ver', ['type' => 'submit', 'class' => 'btn btn-sm btn-secondary ms-2']))
            . ($note !== '' ? \html_writer::span($note, 'text-muted small ms-2') : ''),
            ['method' => 'get', 'action' => $base->out_omit_querystring(), 'class' => 'd-flex flex-wrap align-items-center mb-3']);
        return \html_writer::div($form, 'local-ga-academicyear-filter');
    }

    /**
     * Academic years in which each student has HEE activity: enrolment in a
     * taller, certificate, Tipo B externo, traspaso or institutional import.
     *
     * @return array userid => [year => true]
     */
    public static function user_years(): array {
        global $DB;
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $add = static function(int $userid, string $year) use (&$map): void {
            if ($userid > 0 && $year !== '') {
                $map[$userid][$year] = true;
            }
        };
        $dbman = $DB->get_manager();
        $exists = static fn(string $t): bool => $dbman->table_exists(new \xmldb_table($t));
        $hasseries = $exists('local_ga_workshop_editions') && array_key_exists('seriesid', $DB->get_columns('local_ga_workshop_editions'));
        $seriesfield = $hasseries ? 'e.seriesid' : '0';
        if ($exists('local_ga_edition_enrolments') && $exists('local_ga_workshop_editions')) {
            $rs = $DB->get_recordset_sql("SELECT ee.id, ee.userid, e.sessiondate, $seriesfield AS seriesid
                                            FROM {local_ga_edition_enrolments} ee
                                            JOIN {local_ga_workshop_editions} e ON e.id = ee.editionid
                                           WHERE " . manager::active_enrolment_sql('ee'));
            foreach ($rs as $r) {
                $add((int)$r->userid, self::for_row((int)$r->seriesid, (int)$r->sessiondate));
            }
            $rs->close();
        }
        if ($exists('local_ga_certificates')) {
            $join = $exists('local_ga_workshop_editions') ? 'LEFT JOIN {local_ga_workshop_editions} e ON e.id = c.editionid' : '';
            $sf = $join !== '' ? "COALESCE($seriesfield, 0)" : '0';
            $rs = $DB->get_recordset_sql("SELECT c.id, c.userid, c.timeissued, $sf AS seriesid FROM {local_ga_certificates} c $join");
            foreach ($rs as $r) {
                $add((int)$r->userid, self::for_row((int)$r->seriesid, (int)$r->timeissued));
            }
            $rs->close();
        }
        foreach (['local_ga_typeb_certs' => 'timecreated', 'local_ga_typeb_transfers' => 'timecreated',
                'local_ga_institutional_hours' => 'timemodified'] as $table => $field) {
            if (!$exists($table)) {
                continue;
            }
            $rs = $DB->get_recordset_select($table, 'userid > 0', null, '', "id, userid, $field AS t");
            foreach ($rs as $r) {
                $add((int)$r->userid, self::for_time((int)$r->t));
            }
            $rs->close();
        }
        // Tipo B PAT stores the academic year chosen at import time.
        if ($exists('local_ga_typeb_pat')) {
            $rs = $DB->get_recordset_select('local_ga_typeb_pat', 'userid > 0', null, '', 'id, userid, academicyear');
            foreach ($rs as $r) {
                if (self::is_valid((string)$r->academicyear)) {
                    $add((int)$r->userid, (string)$r->academicyear);
                }
            }
            $rs->close();
        }
        return $map;
    }

    /** «2025/2026, 2026/2027» for a student, or '-'. */
    public static function user_years_text(int $userid): string {
        $years = array_keys(self::user_years()[$userid] ?? []);
        sort($years);
        return $years ? implode(', ', $years) : '-';
    }

    /** Whether a student has activity in the selected year ('' = any). */
    public static function user_in_year(int $userid, string $selected): bool {
        return $selected === '' || !empty(self::user_years()[$userid][$selected]);
    }

    /** Hidden-free params array to append the year filter to a URL. */
    public static function url_params(string $selected): array {
        return [self::PARAM => self::param_value($selected)];
    }
}
