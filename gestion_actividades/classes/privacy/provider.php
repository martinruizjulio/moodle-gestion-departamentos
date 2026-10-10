<?php
namespace local_gestion_actividades\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\plugin\provider as request_provider;
use core_privacy\local\metadata\provider as metadata_provider;

/**
 * Privacy provider for Gestión HEE.
 *
 * Academic evidence is exported, but deliberately not deleted automatically.
 * Retention/deletion of those records requires an institutional policy decision.
 */
class provider implements metadata_provider, request_provider, core_userlist_provider {
    public static function get_metadata(collection $collection): collection {
        $tables = [
            'local_ga_imports' => ['userid'],
            'local_ga_candidates' => ['userid', 'grade', 'status'],
            'local_ga_participants' => ['userid', 'grade', 'status'],
            'local_ga_completions' => ['userid', 'status'],
            'local_ga_grades' => ['userid', 'grade', 'usermodified'],
            'local_ga_grade_log' => ['userid', 'grade', 'usermodified'],
            'local_ga_edition_enrolments' => ['userid', 'status', 'attended', 'attendedby'],
            'local_ga_edition_teachers' => ['userid'],
            'local_ga_certificates' => ['userid', 'status'],
            'local_ga_typeb_certs' => ['userid', 'status', 'reviewedby'],
            'local_ga_typeb_reflections' => ['userid', 'reflectiontext'],
            'local_ga_typeb_transfers' => ['userid', 'reflectiontext', 'status'],
            'local_ga_task_submissions' => ['userid', 'grade', 'gradedby'],
            'local_ga_institutional_hours' => ['userid', 'typeahours', 'typebhours', 'taskgrade', 'typebreflection', 'usermodified'],
            'local_ga_course_settings' => ['usermodified'],
            'local_ga_authorized' => ['userid'],
            'local_ga_typeb_pat' => ['userid', 'studentid', 'dni', 'email', 'attendance', 'grade', 'passed'],
        ];
        foreach ($tables as $table => $fields) {
            $metadata = [];
            foreach ($fields as $field) {
                if (in_array($field, ['grade', 'taskgrade', 'typeahours', 'typebhours'], true)) {
                    $metadata[$field] = 'privacy:metadata:grade';
                } else if (in_array($field, ['status'], true)) {
                    $metadata[$field] = 'privacy:metadata:status';
                } else {
                    $metadata[$field] = 'privacy:metadata:userid';
                }
            }
            $collection->add_database_table($table, $metadata, 'privacy:metadata');
        }
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($userid <= 0) {
            return $contextlist;
        }
        $contextlist->add_context(\context_system::instance());

        $courselevel = CONTEXT_COURSE;
        if (self::table_exists('local_ga_certificates')) {
            $contextlist->add_from_sql(
                "SELECT ctx.id
                   FROM {context} ctx
                   JOIN {local_ga_certificates} cert ON cert.courseid = ctx.instanceid
                  WHERE ctx.contextlevel = :contextlevel AND cert.userid = :userid",
                ['contextlevel' => $courselevel, 'userid' => $userid]
            );
        }
        if (self::table_exists('local_ga_edition_enrolments') && self::table_exists('local_ga_workshop_editions')
                && self::table_exists('local_ga_workshops')) {
            $contextlist->add_from_sql(
                "SELECT DISTINCT ctx.id
                   FROM {context} ctx
                   JOIN {local_ga_workshops} w ON w.courseid = ctx.instanceid
                   JOIN {local_ga_workshop_editions} e ON e.workshopid = w.id
                   JOIN {local_ga_edition_enrolments} ee ON ee.editionid = e.id
                  WHERE ctx.contextlevel = :contextlevel AND ee.userid = :userid",
                ['contextlevel' => $courselevel, 'userid' => $userid]
            );
        }
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_SYSTEM) {
                self::export_system_data($context, $userid);
                continue;
            }
            if ($context->contextlevel === CONTEXT_COURSE) {
                self::export_course_data($context, $userid, (int)$context->instanceid);
            }
        }
    }

    private static function export_system_data(\context $context, int $userid): void {
        global $DB;
        $definitions = [
            'Formaciones Tipo B externas' => ['local_ga_typeb_certs', 'userid'],
            'Traspasos Tipo A a Tipo B' => ['local_ga_typeb_transfers', 'userid'],
            'Reconocimiento institucional' => ['local_ga_institutional_hours', 'userid'],
            'Historial de calificaciones HEE' => ['local_ga_grade_log', 'userid'],
            'Autorización HEE' => ['local_ga_authorized', 'userid'],
            'Talleres Tipo B PAT' => ['local_ga_typeb_pat', 'userid'],
        ];
        foreach ($definitions as $label => [$table, $field]) {
            if (!self::table_exists($table)) {
                continue;
            }
            $records = $DB->get_records($table, [$field => $userid], 'id ASC');
            if ($records) {
                writer::with_context($context)->export_data(['Gestión HEE', $label], (object)[
                    'records' => array_values($records),
                ]);
                if ($table === 'local_ga_typeb_certs') {
                    // Evidence uploaded by the student for external Type B review.
                    foreach ($records as $record) {
                        writer::with_context($context)->export_area_files(['Gestión HEE', $label, (string)$record->id],
                            'local_gestion_actividades', 'typeb_certificate', (int)$record->id);
                    }
                }
            }
        }
    }

    private static function export_course_data(\context $context, int $userid, int $courseid): void {
        global $DB;
        if (self::table_exists('local_ga_certificates')) {
            $records = $DB->get_records('local_ga_certificates', ['userid' => $userid, 'courseid' => $courseid], 'timeissued ASC, id ASC');
            if ($records) {
                writer::with_context($context)->export_data(['Gestión HEE', 'Certificados'], (object)['records' => array_values($records)]);
                foreach ($records as $record) {
                    writer::with_context($context)->export_area_files(['Gestión HEE', 'Certificados', (string)$record->id],
                        'local_gestion_actividades', 'certificate', (int)$record->id);
                }
            }
        }

        if (!self::table_exists('local_ga_workshops') || !self::table_exists('local_ga_workshop_editions')) {
            return;
        }
        if (self::table_exists('local_ga_edition_enrolments')) {
            $sql = "SELECT ee.*, e.name AS editionname, e.editioncode, w.code AS workshopcode, w.name AS workshopname
                      FROM {local_ga_edition_enrolments} ee
                      JOIN {local_ga_workshop_editions} e ON e.id = ee.editionid
                      JOIN {local_ga_workshops} w ON w.id = e.workshopid
                     WHERE ee.userid = :userid AND w.courseid = :courseid
                  ORDER BY e.sessiondate ASC, ee.id ASC";
            $records = $DB->get_records_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
            if ($records) {
                writer::with_context($context)->export_data(['Gestión HEE', 'Inscripciones y asistencia'], (object)['records' => array_values($records)]);
            }
        }
        if (self::table_exists('local_ga_typeb_reflections')) {
            $sql = "SELECT r.*, e.name AS editionname, w.name AS workshopname
                      FROM {local_ga_typeb_reflections} r
                      JOIN {local_ga_workshop_editions} e ON e.id = r.editionid
                      JOIN {local_ga_workshops} w ON w.id = e.workshopid
                     WHERE r.userid = :userid AND w.courseid = :courseid
                  ORDER BY r.id ASC";
            $records = $DB->get_records_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
            if ($records) {
                writer::with_context($context)->export_data(['Gestión HEE', 'Reflexiones Tipo B'], (object)['records' => array_values($records)]);
            }
        }
        if (self::table_exists('local_ga_task_submissions')) {
            $sql = "SELECT ts.*, e.name AS editionname, w.name AS workshopname
                      FROM {local_ga_task_submissions} ts
                      JOIN {local_ga_workshop_editions} e ON e.id = ts.editionid
                      JOIN {local_ga_workshops} w ON w.id = e.workshopid
                     WHERE ts.userid = :userid AND w.courseid = :courseid
                  ORDER BY ts.id ASC";
            $records = $DB->get_records_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
            if ($records) {
                writer::with_context($context)->export_data(['Gestión HEE', 'Entregas internas'], (object)['records' => array_values($records)]);
                foreach ($records as $record) {
                    if (!empty($record->fileitemid)) {
                        writer::with_context($context)->export_area_files(['Gestión HEE', 'Entregas internas', (string)$record->id],
                            'local_gestion_actividades', 'tasksubmission', (int)$record->fileitemid);
                    }
                }
            }
        }
    }

    /**
     * Academic evidence is retained. Deletion requires an institutional retention policy.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        // Deliberately no-op: do not destroy academic records automatically.
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        // Deliberately no-op: do not destroy academic records automatically.
    }

    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            foreach (['local_ga_typeb_certs', 'local_ga_typeb_transfers', 'local_ga_institutional_hours', 'local_ga_grade_log', 'local_ga_authorized', 'local_ga_typeb_pat'] as $table) {
                if (self::table_exists($table)) {
                    $userlist->add_from_sql('userid', "SELECT DISTINCT userid FROM {{$table}} WHERE userid > 0", []);
                }
            }
            return;
        }
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $courseid = (int)$context->instanceid;
        if (self::table_exists('local_ga_certificates')) {
            $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_ga_certificates} WHERE courseid = :courseid AND userid > 0', ['courseid' => $courseid]);
        }
        if (self::table_exists('local_ga_edition_enrolments') && self::table_exists('local_ga_workshop_editions') && self::table_exists('local_ga_workshops')) {
            $userlist->add_from_sql(
                'userid',
                "SELECT DISTINCT ee.userid
                   FROM {local_ga_edition_enrolments} ee
                   JOIN {local_ga_workshop_editions} e ON e.id = ee.editionid
                   JOIN {local_ga_workshops} w ON w.id = e.workshopid
                  WHERE w.courseid = :courseid AND ee.userid > 0",
                ['courseid' => $courseid]
            );
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        // Deliberately no-op: do not destroy academic records automatically.
    }

    private static function table_exists(string $table): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table($table));
    }
}
