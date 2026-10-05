<?php
namespace local_gestion_actividades\privacy;

defined('MOODLE_INTERNAL') || die();

class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('local_ga_imports', [
            'userid' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_candidates', [
            'userid' => 'privacy:metadata:userid',
            'grade' => 'privacy:metadata:grade',
            'status' => 'privacy:metadata:status',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_participants', [
            'userid' => 'privacy:metadata:userid',
            'grade' => 'privacy:metadata:grade',
            'status' => 'privacy:metadata:status',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_completions', [
            'userid' => 'privacy:metadata:userid',
            'status' => 'privacy:metadata:status',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_grades', [
            'userid' => 'privacy:metadata:userid',
            'grade' => 'privacy:metadata:grade',
            'usermodified' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_grade_log', [
            'userid' => 'privacy:metadata:userid',
            'grade' => 'privacy:metadata:grade',
            'usermodified' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_edition_teachers', [
            'userid' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_edition_enrolments', [
            'userid' => 'privacy:metadata:userid',
            'status' => 'privacy:metadata:status',
            'attendedby' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_hour_history', [
            'userid' => 'privacy:metadata:userid',
            'hours' => 'privacy:metadata:hours',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_authorized', [
            'userid' => 'privacy:metadata:userid',
            'addedby' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_certificates', [
            'userid' => 'privacy:metadata:userid',
            'status' => 'privacy:metadata:status',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_typeb_certs', [
            'userid' => 'privacy:metadata:userid',
            'hours' => 'privacy:metadata:hours',
            'status' => 'privacy:metadata:status',
            'reviewedby' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_typeb_reflections', [
            'userid' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_typeb_transfers', [
            'userid' => 'privacy:metadata:userid',
            'hours' => 'privacy:metadata:hours',
            'status' => 'privacy:metadata:status',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_task_submissions', [
            'userid' => 'privacy:metadata:userid',
            'grade' => 'privacy:metadata:grade',
            'gradedby' => 'privacy:metadata:userid',
            'status' => 'privacy:metadata:status',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_institutional_hours', [
            'userid' => 'privacy:metadata:userid',
            'typeahours' => 'privacy:metadata:hours',
            'typebhours' => 'privacy:metadata:hours',
            'taskgrade' => 'privacy:metadata:grade',
            'usermodified' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        $collection->add_database_table('local_ga_course_settings', [
            'usermodified' => 'privacy:metadata:userid',
        ], 'privacy:metadata');
        return $collection;
    }
}
