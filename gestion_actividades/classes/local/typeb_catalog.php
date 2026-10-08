<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Catalogue of external Type B talleres (name + hours) managed by Gestión HEE.
 * Students choose one of the active talleres when they upload the
 * certificate; the hours always come from here, never from the student.
 * A retired taller is no longer offered but its requests are kept.
 */
class typeb_catalog {
    public const TABLE = 'local_ga_typeb_catalog';

    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table(self::TABLE));
    }

    /** All talleres (active first, then by name). */
    public static function all(bool $activeonly = false): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        return $DB->get_records(self::TABLE, $activeonly ? ['active' => 1] : null, 'active DESC, name ASC, id ASC');
    }

    public static function get(int $id): ?\stdClass {
        global $DB;
        if ($id <= 0 || !self::available()) {
            return null;
        }
        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Create or update a taller. Hours must be > 0.
     *
     * @return int id
     */
    public static function save(int $id, string $name, float $hours, int $userid): int {
        global $DB;
        $name = \core_text::substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 255);
        if ($name === '') {
            throw new \invalid_parameter_exception('Indica el nombre del taller.');
        }
        if ($hours <= 0 || $hours > 500) {
            throw new \invalid_parameter_exception('Las horas deben ser superiores a 0 y no superar 500.');
        }
        $now = time();
        foreach ($DB->get_records(self::TABLE) as $other) {
            if ((int)$other->id !== $id && \core_text::strtolower(trim($other->name)) === \core_text::strtolower($name)) {
                throw new \invalid_parameter_exception('Ya existe un taller con ese nombre en el catálogo.');
            }
        }
        if ($id > 0) {
            $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            $record->name = $name;
            $record->hours = round($hours, 2);
            $record->usermodified = $userid;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
            // Requests not yet validated follow the catalogue (name and hours).
            $DB->execute("UPDATE {local_ga_typeb_certs} SET activityname = :name, hours = :hours, timemodified = :now
                           WHERE catalogid = :id AND status IN ('pending', 'rejected')",
                ['name' => $name, 'hours' => round($hours, 2), 'now' => $now, 'id' => $id]);
            return $id;
        }
        return (int)$DB->insert_record(self::TABLE, (object)[
            'name' => $name, 'hours' => round($hours, 2), 'active' => 1,
            'usermodified' => $userid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /** Retire (0) or reactivate (1) a taller. Its requests are kept. */
    public static function set_active(int $id, bool $active, int $userid): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)['id' => $id, 'active' => $active ? 1 : 0,
            'usermodified' => $userid, 'timemodified' => time()]);
    }

    /** Number of requests per taller: id => count. */
    public static function request_counts(): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        return $DB->get_records_sql_menu("SELECT catalogid, COUNT(1) FROM {local_ga_typeb_certs}
                                           WHERE catalogid > 0 GROUP BY catalogid");
    }

    /** Delete a taller only when nobody has used it. */
    public static function delete_unused(int $id): bool {
        global $DB;
        if ($DB->record_exists('local_ga_typeb_certs', ['catalogid' => $id])) {
            return false;
        }
        $DB->delete_records(self::TABLE, ['id' => $id]);
        return true;
    }

    /**
     * Whether the student already has a request (not rejected) for this taller,
     * ignoring $exceptrequestid (the request being edited).
     */
    public static function user_has_request(int $userid, int $catalogid, int $exceptrequestid = 0): bool {
        global $DB;
        return $DB->record_exists_select('local_ga_typeb_certs',
            "userid = :userid AND catalogid = :catalogid AND status <> 'rejected' AND id <> :except",
            ['userid' => $userid, 'catalogid' => $catalogid, 'except' => $exceptrequestid]);
    }

    /**
     * Dropdown options for a student: active talleres not yet requested.
     * When editing, the request's current taller is always included.
     *
     * @return array id => «Nombre · N h»
     */
    public static function options_for_user(int $userid, int $exceptrequestid = 0, int $currentcatalogid = 0): array {
        $out = [];
        foreach (self::all() as $item) {
            $current = (int)$item->id === $currentcatalogid;
            if (!$current && (empty($item->active) || self::user_has_request($userid, (int)$item->id, $exceptrequestid))) {
                continue;
            }
            $out[(int)$item->id] = format_string($item->name) . ' · ' . format_float((float)$item->hours, 2, true) . ' h';
        }
        return $out;
    }
}
