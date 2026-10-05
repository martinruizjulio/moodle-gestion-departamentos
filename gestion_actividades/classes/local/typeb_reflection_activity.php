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
            $update->intro = '<p>Escribe un breve párrafo describiendo cómo ha sido el taller y tus impresiones sobre la actividad. Puedes responder mediante texto en línea o, si lo prefieres, adjuntar un archivo.</p>';
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

        // Enable both simple submission routes: online paragraph or one attachment.
        self::set_plugin_config((int)$assign->id, 'onlinetext', 'enabled', '1');
        self::set_plugin_config((int)$assign->id, 'file', 'enabled', '1');
        self::set_plugin_config((int)$assign->id, 'file', 'maxfilesubmissions', '1');

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
        $edition = manager::get_workshop_edition($editionid);
        $cmid = (int)($edition->requiredcmid ?? 0);
        return $cmid > 0 && manager::user_completed_required_activity($userid, $cmid);
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
