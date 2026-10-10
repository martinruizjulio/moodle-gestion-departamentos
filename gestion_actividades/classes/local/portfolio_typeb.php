<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Class portfolio_typeb for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

class portfolio_typeb {
    public const STATUS_VALIDATED_PENDING_REFLECTION = 'validated_pending_reflection';

    public static function ensure_table(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_typeb_certs'))) {
            throw new \coding_exception('El esquema de certificados Tipo B no está instalado. Ejecuta la actualización de Moodle.');
        }
    }

    public static function create_upload(int $userid, string $activityname, int $activitydate, float $hours, string $activitydescription, string $filename, string $tmpfilepath): int {
        global $DB;
        self::ensure_table();
        $now = time();
        $record = (object)[
            'userid' => $userid,
            'activityname' => trim($activityname),
            'activitydate' => $activitydate,
            'hours' => max(0, $hours),
            'activitydescription' => trim($activitydescription),
            'authorizedconfirm' => 1,
            'filename' => clean_filename($filename),
            'status' => 'pending',
            'reviewcomment' => '',
            'reflectiontext' => '',
            'reflectiontime' => 0,
            'reviewedby' => 0,
            'timereviewed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $id = $DB->insert_record('local_ga_typeb_certs', $record);

        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, 'local_gestion_actividades', 'typeb_certificate', $id);
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_gestion_actividades',
            'filearea' => 'typeb_certificate',
            'itemid' => $id,
            'filepath' => '/',
            'filename' => clean_filename($filename),
            'mimetype' => function_exists('mimeinfo') ? mimeinfo('type', clean_filename($filename)) : 'application/octet-stream',
            'userid' => $userid,
            'author' => fullname(\core_user::get_user($userid)),
        ], $tmpfilepath);

        self::after_change($userid, false);
        return (int)$id;
    }

    /**
     * New request for a taller of the catalogue: name and hours come from the
     * catalogue. One request per student and taller (unless rejected).
     */
    public static function create_from_catalog(int $userid, int $catalogid, int $activitydate, string $filename, string $tmpfilepath): int {
        global $DB;
        $item = typeb_catalog::get($catalogid);
        if (!$item || empty($item->active)) {
            throw new \invalid_parameter_exception('Elige un taller Tipo B del catálogo.');
        }
        if (typeb_catalog::user_has_request($userid, $catalogid)) {
            throw new \invalid_parameter_exception('Ya has presentado una solicitud para este taller. Puedes editarla en «Mis solicitudes de validación».');
        }
        $id = self::create_upload($userid, (string)$item->name, $activitydate, (float)$item->hours, '', $filename, $tmpfilepath);
        $DB->set_field('local_ga_typeb_certs', 'catalogid', $catalogid, ['id' => $id]);
        return $id;
    }

    /** Whether the student can still edit the request (not validated yet). */
    public static function is_editable(\stdClass $record): bool {
        return in_array((string)($record->status ?? ''), ['pending', 'rejected'], true);
    }

    /**
     * The student corrects a request that is pending or was rejected: taller,
     * date and (optionally) the certificate. It goes back to «pending».
     */
    public static function update_request(int $id, int $userid, int $catalogid, int $activitydate, string $filename = '', string $tmpfilepath = ''): void {
        global $DB;
        $record = self::get($id);
        if ((int)$record->userid !== $userid || !self::is_editable($record)) {
            throw new \invalid_parameter_exception('Esta solicitud ya no se puede editar.');
        }
        $item = typeb_catalog::get($catalogid);
        if (!$item || (empty($item->active) && (int)$record->catalogid !== $catalogid)) {
            throw new \invalid_parameter_exception('Elige un taller Tipo B del catálogo.');
        }
        if (typeb_catalog::user_has_request($userid, $catalogid, $id)) {
            throw new \invalid_parameter_exception('Ya has presentado otra solicitud para este taller.');
        }
        $record->catalogid = $catalogid;
        $record->activityname = (string)$item->name;
        $record->hours = (float)$item->hours;
        $record->activitydate = $activitydate;
        $record->status = 'pending';
        $record->timemodified = time();
        if ($filename !== '' && $tmpfilepath !== '' && is_readable($tmpfilepath)) {
            $fs = get_file_storage();
            $context = \context_system::instance();
            $fs->delete_area_files($context->id, 'local_gestion_actividades', 'typeb_certificate', $id);
            $fs->create_file_from_pathname([
                'contextid' => $context->id, 'component' => 'local_gestion_actividades', 'filearea' => 'typeb_certificate',
                'itemid' => $id, 'filepath' => '/', 'filename' => clean_filename($filename),
                'mimetype' => function_exists('mimeinfo') ? mimeinfo('type', clean_filename($filename)) : 'application/octet-stream',
                'userid' => $userid, 'author' => fullname(\core_user::get_user($userid)),
            ], $tmpfilepath);
            $record->filename = clean_filename($filename);
        }
        $DB->update_record('local_ga_typeb_certs', $record);
        self::after_change($userid);
    }

    public static function get(int $id): \stdClass {
        global $DB;
        self::ensure_table();
        return $DB->get_record('local_ga_typeb_certs', ['id' => $id], '*', MUST_EXIST);
    }

    public static function list_for_user(int $userid): array {
        global $DB;
        self::ensure_table();
        return $DB->get_records('local_ga_typeb_certs', ['userid' => $userid], 'activitydate DESC, timecreated DESC, id DESC');
    }

    public static function list_pending(): array {
        global $DB;
        self::ensure_table();
        $sql = "SELECT c.*, u.firstname, u.lastname, u.email
                  FROM {local_ga_typeb_certs} c
                  JOIN {user} u ON u.id = c.userid
                 WHERE c.status = :status
              ORDER BY c.timecreated ASC, c.id ASC";
        return $DB->get_records_sql($sql, ['status' => 'pending']);
    }

    /**
     * List external Type B requests, optionally filtered by student and status.
     * Callers (portfolio_admin.php) rely on both filters.
     */
    public static function list_all(int $userid = 0, string $status = ''): array {
        global $DB;
        self::ensure_table();
        $where = [];
        $params = [];
        if ($userid > 0) {
            $where[] = 'c.userid = :userid';
            $params['userid'] = $userid;
        }
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT c.*, u.firstname, u.lastname, u.email
                  FROM {local_ga_typeb_certs} c
                  JOIN {user} u ON u.id = c.userid
                 $wheresql
              ORDER BY c.timecreated DESC, c.id DESC";
        return $DB->get_records_sql($sql, $params);
    }

    public static function count_pending(): int {
        global $DB;
        self::ensure_table();
        return (int)$DB->count_records('local_ga_typeb_certs', ['status' => 'pending']);
    }

    /**
     * Backwards-compatible entry point used by typeb_review.php. Accepts the
     * legacy 'pending' value to return a request to the review queue.
     */
    public static function set_status(int $id, string $status, string $comment, int $reviewerid): bool {
        global $DB;
        if ($status !== 'pending') {
            return self::review($id, $status, $comment, $reviewerid);
        }
        self::ensure_table();
        $record = self::get($id);
        $record->status = 'pending';
        $record->reviewcomment = trim($comment);
        $record->reviewedby = $reviewerid;
        $record->timereviewed = time();
        $record->timemodified = time();
        $DB->update_record('local_ga_typeb_certs', $record);
        self::after_change((int)$record->userid);
        return true;
    }

    public static function review(int $id, string $status, string $comment, int $reviewedby): bool {
        global $DB;
        self::ensure_table();
        if (!in_array($status, ['validated', 'rejected'], true)) {
            return false;
        }
        $record = self::get($id);
        // Catalogue talleres: the validated hours are the catalogue's.
        if ($status === 'validated' && !empty($record->catalogid) && ($item = typeb_catalog::get((int)$record->catalogid))) {
            $record->activityname = (string)$item->name;
            $record->hours = (float)$item->hours;
        }
        if ($status === 'validated' && trim((string)($record->reflectiontext ?? '')) === '') {
            $status = self::STATUS_VALIDATED_PENDING_REFLECTION;
        }
        $record->status = $status;
        $record->reviewcomment = trim($comment);
        $record->reviewedby = $reviewedby;
        $record->timereviewed = time();
        $record->timemodified = time();
        $DB->update_record('local_ga_typeb_certs', $record);
        self::after_change((int)$record->userid);
        return true;
    }

    public static function save_reflection(int $id, int $userid, string $reflectiontext): bool {
        global $DB;
        self::ensure_table();
        $record = self::get($id);
        if ((int)$record->userid !== $userid) {
            return false;
        }
        if (!in_array((string)$record->status, [self::STATUS_VALIDATED_PENDING_REFLECTION, 'validated'], true)) {
            return false;
        }
        $reflectiontext = trim($reflectiontext);
        if ($reflectiontext === '') {
            return false;
        }
        $record->reflectiontext = $reflectiontext;
        $record->reflectiontime = time();
        $record->status = 'validated';
        $record->timemodified = time();
        $DB->update_record('local_ga_typeb_certs', $record);
        self::after_change($userid);
        return true;
    }

    public static function is_countable(\stdClass $record): bool {
        return (string)($record->status ?? '') === 'validated'
            && trim((string)($record->reflectiontext ?? '')) !== '';
    }

    public static function total_validated_hours(int $userid): float {
        $sum = 0.0;
        foreach (self::list_for_user($userid) as $record) {
            if (self::is_countable($record)) {
                $sum += (float)$record->hours;
            }
        }
        return $sum;
    }

    /**
     * All hours the student has submitted for external Type B review, whatever
     * their status (shown as "Tipo B subido" in portfolio.php).
     */
    public static function total_uploaded_hours(int $userid): float {
        $sum = 0.0;
        foreach (self::list_for_user($userid) as $record) {
            $sum += (float)$record->hours;
        }
        return $sum;
    }

    /**
     * Refresh derived state after a Type B record changes: block cache and,
     * when hours may have changed, the HEE gradebook items.
     */
    private static function after_change(int $userid, bool $syncgrades = true): void {
        global $CFG;
        if ($userid <= 0) {
            return;
        }
        if (!function_exists('local_gestion_actividades_invalidate_block_gestion_hee_user_cache')) {
            require_once($CFG->dirroot . '/local/gestion_actividades/lib.php');
        }
        local_gestion_actividades_invalidate_block_gestion_hee_user_cache($userid);
        if ($syncgrades) {
            grade_manager::sync_user_safely($userid);
        }
    }

    public static function count_validated(int $userid): int {
        $count = 0;
        foreach (self::list_for_user($userid) as $record) {
            if (self::is_countable($record)) {
                $count++;
            }
        }
        return $count;
    }

    public static function get_file(\stdClass $record): ?\stored_file {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $filename = trim((string)($record->filename ?? ''));
        if ($filename !== '') {
            $file = $fs->get_file($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$record->id, '/', $filename);
            if ($file && !$file->is_directory()) {
                return $file;
            }
        }
        foreach ($fs->get_area_files($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$record->id, 'filename', false) as $file) {
            if (!$file->is_directory()) {
                return $file;
            }
        }
        return null;
    }
}
