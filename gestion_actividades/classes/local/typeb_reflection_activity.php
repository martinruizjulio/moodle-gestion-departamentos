<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates and normalises the Moodle assignment used as the reflection activity
 * for internal Type B workshops.
 */
class typeb_reflection_activity {
    /**
     * Ensure that a Type B workshop edition has a Moodle assignment configured
     * for a short reflection. The assignment accepts online text and one file.
     */
    public static function ensure_for_edition(int $editionid): \stdClass {
        $edition = manager::get_workshop_edition($editionid);
        $workshop = manager::get_workshop((int)$edition->workshopid);

        $result = (object)[
            'success' => false,
            'cmid' => 0,
            'message' => '',
        ];

        if (!manager::is_typeb_workshop($workshop)) {
            $result->message = 'La actividad de reflexión solo corresponde a talleres Tipo B.';
            return $result;
        }

        $created = manager::create_required_activity_for_edition($editionid, null, 'assign');
        if (empty($created->success) || empty($created->cmid)) {
            $result->message = (string)($created->message ?? 'No se pudo crear la tarea de reflexión.');
            return $result;
        }

        $cmid = (int)$created->cmid;
        if (!self::configure_assignment($editionid, $cmid)) {
            $result->message = 'La tarea se creó, pero no se pudo completar su configuración como reflexión Tipo B.';
            $result->cmid = $cmid;
            return $result;
        }

        $result->success = true;
        $result->cmid = $cmid;
        $result->message = 'Tarea de reflexión Tipo B preparada.';
        return $result;
    }

    /**
     * Configure a standard Moodle assignment as the Type B reflection activity.
     * Completion is automatic when the student submits the assignment.
     */
    public static function configure_assignment(int $editionid, int $cmid): bool {
        global $DB;

        if ($cmid <= 0) {
            return false;
        }

        $edition = manager::get_workshop_edition($editionid);
        $workshop = manager::get_workshop((int)$edition->workshopid);
        if (!manager::is_typeb_workshop($workshop)) {
            return false;
        }

        $sql = "SELECT cm.id, cm.course, cm.instance, cm.module, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.id = :cmid";
        $cm = $DB->get_record_sql($sql, ['cmid' => $cmid], IGNORE_MISSING);
        if (!$cm || (string)$cm->modname !== 'assign') {
            return false;
        }

        $assign = $DB->get_record('assign', ['id' => (int)$cm->instance], '*', IGNORE_MISSING);
        if (!$assign) {
            return false;
        }

        $columns = $DB->get_columns('assign');
        $update = (object)['id' => (int)$assign->id];
        if (isset($columns['name'])) {
            $update->name = 'Reflexión · ' . $workshop->name;
        }
        if (isset($columns['intro'])) {
            $update->intro = '<p>Escribe un breve párrafo describiendo cómo ha sido el taller y tus impresiones sobre la actividad. Escríbela directamente en el cuadro de texto en línea.</p>';
        }
        if (isset($columns['introformat'])) {
            $update->introformat = FORMAT_HTML;
        }
        if (isset($columns['grade'])) {
            $update->grade = 0;
        }
        if (isset($columns['completionsubmit'])) {
            $update->completionsubmit = 1;
        }
        if (isset($columns['submissiondrafts'])) {
            $update->submissiondrafts = 0;
        }
        if (isset($columns['requiresubmissionstatement'])) {
            $update->requiresubmissionstatement = 0;
        }
        $DB->update_record('assign', $update);

        // Type B reflection = online text only (agreed 2026-10-06): the student
        // writes the assessment of the workshop; no file attachment.
        self::set_plugin_config((int)$assign->id, 'onlinetext', 'enabled', '1');
        self::set_plugin_config((int)$assign->id, 'file', 'enabled', '0');

        // Mark completion automatically on submission; this is the canonical Apto
        // condition used by HEE together with attendance.
        $cmupdate = (object)[
            'id' => $cmid,
            'completion' => 2,
            'visible' => 1,
        ];
        $cmcolumns = $DB->get_columns('course_modules');
        if (isset($cmcolumns['visibleoncoursepage'])) {
            $cmupdate->visibleoncoursepage = 1;
        }
        $DB->update_record('course_modules', $cmupdate);

        $editioncolumns = $DB->get_columns('local_ga_workshop_editions');
        $editionupdate = (object)[
            'id' => $editionid,
            'requiredcmid' => $cmid,
            'requiredmodname' => 'assign',
            'activitycreationtype' => 'assign',
            'timemodified' => time(),
        ];
        if (isset($editioncolumns['requiredassigncmid'])) {
            $editionupdate->requiredassigncmid = $cmid;
        }
        if (isset($editioncolumns['requiredquizcmid'])) {
            $editionupdate->requiredquizcmid = 0;
        }
        $DB->update_record('local_ga_workshop_editions', $editionupdate);

        workshop_series::refresh_for_workshop((int)$workshop->id);
        rebuild_course_cache((int)$cm->course, true);
        return true;
    }

    public static function is_complete(int $editionid, int $userid): bool {
        return typeb_certificate_policy::has_reflection($editionid, $userid);
    }

    /**
     * Return the canonical Moodle Assignment submission state for one internal
     * Type B reflection. File-only submissions are valid even when text is empty.
     */
    public static function submission_summary(int $editionid, int $userid): \stdClass {
        global $DB;

        $summary = (object)[
            'submitted' => false,
            'text' => '',
            'timemodified' => 0,
            'submissionid' => 0,
        ];
        if ($editionid <= 0 || $userid <= 0) {
            return $summary;
        }

        $edition = manager::get_workshop_edition($editionid);
        $cmid = (int)($edition->requiredcmid ?? 0);
        if ($cmid <= 0) {
            return $summary;
        }

        // Source of truth: the latest Moodle assignment submission must be in
        // status 'submitted'. Completion or grades are not used, so a removed or
        // reverted submission stops counting as a reflection immediately.

        $sql = "SELECT s.id, s.status, s.timemodified
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {assign} a ON a.id = cm.instance
                  JOIN {assign_submission} s ON s.assignment = a.id
                 WHERE cm.id = :cmid
                   AND s.userid = :userid
              ORDER BY s.latest DESC, s.attemptnumber DESC, s.timemodified DESC, s.id DESC";
        $submissions = $DB->get_records_sql($sql, ['cmid' => $cmid, 'userid' => $userid], 0, 1);
        $submission = $submissions ? reset($submissions) : null;
        if (!$submission) {
            return $summary;
        }

        $summary->submissionid = (int)$submission->id;
        $summary->timemodified = (int)($submission->timemodified ?? 0);
        $summary->submitted = (string)($submission->status ?? '') === 'submitted';
        if (!$summary->submitted) {
            return $summary;
        }

        $onlinetable = new \xmldb_table('assignsubmission_onlinetext');
        if ($DB->get_manager()->table_exists($onlinetable)) {
            $textrow = $DB->get_record('assignsubmission_onlinetext', [
                'submission' => (int)$submission->id,
            ], 'onlinetext, onlineformat', IGNORE_MISSING);
            if ($textrow && trim(strip_tags((string)$textrow->onlinetext)) !== '') {
                $summary->text = trim((string)$textrow->onlinetext);
            }
        }

        return $summary;
    }

    /**
     * Keep the old compatibility table in sync after a real Moodle Assignment
     * submission has been accepted. New UI/report logic never treats that table
     * as the source of truth; it only supports old grade calculations safely.
     */
    public static function sync_legacy_compatibility(int $editionid, int $userid): bool {
        global $DB;

        $summary = self::submission_summary($editionid, $userid);
        if (empty($summary->submitted)) {
            return false;
        }
        $table = new \xmldb_table('local_ga_typeb_reflections');
        if (!$DB->get_manager()->table_exists($table)) {
            return true;
        }

        $text = trim(strip_tags((string)($summary->text ?? '')));
        if ($text === '') {
            // File-only reflections are valid in the canonical Moodle Assignment.
            // The marker prevents legacy calculations from treating them as missing.
            $text = '[Entrega de reflexión registrada en Moodle]';
        }
        $now = max(time(), (int)($summary->timemodified ?? 0));
        $existing = $DB->get_record('local_ga_typeb_reflections', [
            'editionid' => $editionid,
            'userid' => $userid,
        ], '*', IGNORE_MISSING);
        if ($existing) {
            $existing->reflectiontext = $text;
            $existing->timemodified = $now;
            $DB->update_record('local_ga_typeb_reflections', $existing);
        } else {
            $DB->insert_record('local_ga_typeb_reflections', (object)[
                'editionid' => $editionid,
                'userid' => $userid,
                'reflectiontext' => $text,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        return true;
    }

    /**
     * Backfill compatibility rows for existing internal Type B submissions.
     */
    public static function backfill_legacy_compatibility(): int {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshop_editions'))
                || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_workshops'))
                || !$DB->get_manager()->table_exists(new \xmldb_table('local_ga_edition_enrolments'))) {
            return 0;
        }
        $sql = "SELECT ee.id, ee.editionid, ee.userid
                  FROM {local_ga_edition_enrolments} ee
                  JOIN {local_ga_workshop_editions} e ON e.id = ee.editionid
                  JOIN {local_ga_workshops} w ON w.id = e.workshopid
                 WHERE w.workshoptype = 'typeb'
                   AND e.requiredcmid > 0
                   AND ee.userid > 0";
        $count = 0;
        foreach ($DB->get_records_sql($sql) as $record) {
            try {
                if (self::sync_legacy_compatibility((int)$record->editionid, (int)$record->userid)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                // One damaged legacy edition must not stop an upgrade/backfill.
            }
        }
        return $count;
    }

    private static function set_plugin_config(int $assignmentid, string $plugin, string $name, string $value): void {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('assign_plugin_config'))) {
            return;
        }

        $params = [
            'assignment' => $assignmentid,
            'plugin' => $plugin,
            'subtype' => 'assignsubmission',
            'name' => $name,
        ];
        $existing = $DB->get_record('assign_plugin_config', $params, '*', IGNORE_MISSING);
        if ($existing) {
            $existing->value = $value;
            $DB->update_record('assign_plugin_config', $existing);
            return;
        }

        $params['value'] = $value;
        $DB->insert_record('assign_plugin_config', (object)$params);
    }
}
