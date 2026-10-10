<?php
defined('MOODLE_INTERNAL') || die();

function local_gestion_actividades_add_index_if_possible($dbman, string $tablename, string $indexname, array $fields): void {
    $table = new xmldb_table($tablename);
    if (!$dbman->table_exists($table)) {
        return;
    }

    foreach ($fields as $fieldname) {
        $field = new xmldb_field($fieldname);
        if (!$dbman->field_exists($table, $field)) {
            return;
        }
    }

    $index = new xmldb_index($indexname, XMLDB_INDEX_NOTUNIQUE, $fields);
    if ($dbman->index_exists($table, $index)) {
        return;
    }

    try {
        $dbman->add_index($table, $index);
    } catch (Throwable $e) {
        if (function_exists('debugging')) {
            debugging('No se ha podido crear el índice ' . $indexname . ' en ' . $tablename . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}

function xmldb_local_gestion_actividades_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026071013) {
        $table = new xmldb_table('local_ga_workshops');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('allowrepeat', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('coursecode', XMLDB_INDEX_UNIQUE, ['courseid', 'code']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ga_workshop_editions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('editioncode', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sessiondate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enrolenddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('places', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attendancecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('certificatecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('workshopid', XMLDB_INDEX_NOTUNIQUE, ['workshopid']);
            $table->add_index('activityid', XMLDB_INDEX_NOTUNIQUE, ['activityid']);
            $table->add_index('groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ga_edition_teachers');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('editionuserid', XMLDB_INDEX_UNIQUE, ['editionid', 'userid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ga_edition_enrolments');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'enrolled');
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('editionuser', XMLDB_INDEX_UNIQUE, ['editionid', 'userid']);
            $table->add_index('workshopuser', XMLDB_INDEX_NOTUNIQUE, ['workshopid', 'userid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026071013, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071019) {
        $table = new xmldb_table('local_ga_workshop_editions');
        $field = new xmldb_field('requiredcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'certificatecmid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('requiredmodname', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'requiredcmid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026071019, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071021) {
        $table = new xmldb_table('local_ga_workshop_editions');
        $fields = [
            new xmldb_field('activitycreationtype', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'requiredmodname'),
            new xmldb_field('tasknumericgrade', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'activitycreationtype'),
            new xmldb_field('quizgradingmode', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'tasknumericgrade'),
            new xmldb_field('archived', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'quizgradingmode'),
            new xmldb_field('timearchived', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'archived'),
        ];
        if ($dbman->table_exists($table)) {
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026071021, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071022) {
        $table = new xmldb_table('local_ga_workshops');
        $field = new xmldb_field('hours', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'allowrepeat');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026071022, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071023) {
        $table = new xmldb_table('local_ga_hour_history');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('workshopcode', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('workshopname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('editionname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('hours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('certificatecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('certificatestatus', XMLDB_TYPE_CHAR, '40', null, null, null, 'pending');
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('useridx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('editionuseridx', XMLDB_INDEX_UNIQUE, ['editionid', 'userid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026071023, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071024) {
        $table = new xmldb_table('local_ga_workshops');
        $field = new xmldb_field('sectionnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'hours');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026071024, 'local', 'gestion_actividades');
    }

    foreach ([2026071027, 2026071028, 2026071029, 2026071030, 2026071031, 2026071032, 2026071033, 2026071034,
              2026071035, 2026071036, 2026071037, 2026071038, 2026071039, 2026071040, 2026071041, 2026071042] as $version) {
        if ($oldversion < $version) {
            upgrade_plugin_savepoint(true, $version, 'local', 'gestion_actividades');
        }
    }

    if ($oldversion < 2026071043) {
        $table = new xmldb_table('local_ga_authorized');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('addedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ga_materials');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('url', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('workshopid_idx', XMLDB_INDEX_NOTUNIQUE, ['workshopid']);
            $table->add_index('editionid_idx', XMLDB_INDEX_NOTUNIQUE, ['editionid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ga_workshop_editions');
        $fields = [
            new xmldb_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('completedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
        ];
        foreach ($fields as $field) {
            if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026071043, 'local', 'gestion_actividades');
    }

    foreach ([2026071044, 2026071045, 2026071046] as $version) {
        if ($oldversion < $version) {
            upgrade_plugin_savepoint(true, $version, 'local', 'gestion_actividades');
        }
    }

    if ($oldversion < 2026071047) {
        $table = new xmldb_table('local_ga_materials');
        $field = new xmldb_field('fileitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026071047, 'local', 'gestion_actividades');
    }

    foreach ([2026071048, 2026071049, 2026071050, 2026071051] as $version) {
        if ($oldversion < $version) {
            upgrade_plugin_savepoint(true, $version, 'local', 'gestion_actividades');
        }
    }

    if ($oldversion < 2026071052) {
        $table = new xmldb_table('local_ga_edition_enrolments');
        if ($dbman->table_exists($table)) {
            $fields = [
                new xmldb_field('attended', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timeattended', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('attendedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026071052, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071053) {
        $table = new xmldb_table('local_ga_edition_enrolments');
        if ($dbman->table_exists($table)) {
            $fields = [
                new xmldb_field('attended', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timeattended', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('attendedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026071053, 'local', 'gestion_actividades');
    }

    foreach ([2026071054, 2026071055, 2026071056, 2026071057, 2026071058, 2026071059, 2026071060, 2026071061,
              2026071062, 2026071063, 2026071064, 2026071065, 2026071066] as $version) {
        if ($oldversion < $version) {
            upgrade_plugin_savepoint(true, $version, 'local', 'gestion_actividades');
        }
    }

    if ($oldversion < 2026071067) {
        $table = new xmldb_table('local_ga_certificates');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('certcode', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'generated');
            $table->add_field('timeissued', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('editionuser', XMLDB_INDEX_UNIQUE, ['editionid', 'userid']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }
        if (get_config('local_gestion_actividades', 'certificatetemplatehtml') === false) {
            set_config('certificatetemplatehtml',
                '<p>Se certifica que <strong>{alumno}</strong> ha participado y completado satisfactoriamente el taller <strong>{taller}</strong>, realizado el día <strong>{fecha}</strong>, con una duración de <strong>{horas}</strong> horas, dentro del programa de <strong>Talleres Tipo A</strong>.</p>',
                'local_gestion_actividades'
            );
        }
        upgrade_plugin_savepoint(true, 2026071067, 'local', 'gestion_actividades');
    }

    foreach ([2026071068, 2026071069] as $version) {
        if ($oldversion < $version) {
            upgrade_plugin_savepoint(true, $version, 'local', 'gestion_actividades');
        }
    }

    if ($oldversion < 2026071084) {
        $table = new xmldb_table('local_ga_typeb_certs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('activityname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('activitydate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('hours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('authorizedconfirm', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('reviewcomment', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('activitydate', XMLDB_INDEX_NOTUNIQUE, ['activitydate']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026071084, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071086) {
        $table = new xmldb_table('local_ga_grade_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('activitykey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('academicyear', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('grade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('activitykeyuser', XMLDB_INDEX_NOTUNIQUE, ['activitykey', 'userid']);
            $table->add_index('activityid', XMLDB_INDEX_NOTUNIQUE, ['activityid']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('importid', XMLDB_INDEX_NOTUNIQUE, ['importid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026071086, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071092) {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $oldnames = ['Mi portafolio HEE', 'Mi portafolio HEE: horas y certificados', 'Mis certificados', 'Mis horas', 'Ver mis horas'];
        $targets = [];
        foreach (['url', 'label', 'page'] as $modname) {
            if (!$DB->record_exists('modules', ['name' => $modname]) || !$dbman->table_exists(new xmldb_table($modname))) {
                continue;
            }
            foreach ($oldnames as $oldname) {
                $alias = 'x';
                $sql = "SELECT cm.id AS cmid FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module JOIN {{$modname}} $alias ON $alias.id = cm.instance WHERE m.name = :modname AND " . $DB->sql_like("$alias.name", ':oldname', false);
                $records = $DB->get_records_sql($sql, ['modname' => $modname, 'oldname' => $DB->sql_like_escape($oldname) . '%']);
                foreach ($records as $record) {
                    $targets[(int)$record->cmid] = true;
                }
            }
        }
        if ($DB->record_exists('modules', ['name' => 'url']) && $dbman->table_exists(new xmldb_table('url'))) {
            foreach (['/local/gestion_actividades/portfolio.php', '/local/gestion_actividades/mycertificates.php', '/local/gestion_actividades/myhours.php', '/local/gestion_actividades/typeb_upload.php'] as $fragment) {
                $sql = "SELECT cm.id AS cmid FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module JOIN {url} u ON u.id = cm.instance WHERE m.name = 'url' AND " . $DB->sql_like('u.externalurl', ':fragment', false);
                $records = $DB->get_records_sql($sql, ['fragment' => '%' . $DB->sql_like_escape($fragment) . '%']);
                foreach ($records as $record) {
                    $targets[(int)$record->cmid] = true;
                }
            }
        }
        foreach (array_keys($targets) as $cmid) {
            try {
                course_delete_module((int)$cmid);
            } catch (Throwable $e) {
                // Continue with other legacy shortcuts.
            }
        }
        if ($DB->record_exists('block', ['name' => 'gestion_hee']) && $dbman->table_exists(new xmldb_table('local_ga_workshops'))) {
            $courseids = $DB->get_records_sql("SELECT DISTINCT courseid AS id FROM {local_ga_workshops} WHERE courseid > 1");
            foreach ($courseids as $courseidrow) {
                $courseid = (int)$courseidrow->id;
                $context = context_course::instance($courseid, IGNORE_MISSING);
                if (!$context) {
                    continue;
                }
                $exists = $DB->record_exists_select('block_instances', 'blockname = :blockname AND parentcontextid = :parentcontextid', ['blockname' => 'gestion_hee', 'parentcontextid' => $context->id]);
                if ($exists) {
                    continue;
                }
                $block = (object)[
                    'blockname' => 'gestion_hee', 'parentcontextid' => $context->id, 'showinsubcontexts' => 0,
                    'pagetypepattern' => 'course-view-*', 'subpagepattern' => null, 'defaultregion' => 'side-pre',
                    'defaultweight' => 0, 'configdata' => '', 'timecreated' => time(), 'timemodified' => time(),
                ];
                $columns = $DB->get_columns('block_instances');
                foreach (array_keys((array)$block) as $field) {
                    if (!isset($columns[$field])) {
                        unset($block->$field);
                    }
                }
                $DB->insert_record('block_instances', $block);
                rebuild_course_cache($courseid, true);
            }
        }
        upgrade_plugin_savepoint(true, 2026071092, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071113) {
        $table = new xmldb_table('local_ga_workshop_editions');
        $field = new xmldb_field('requiredassigncmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'requiredcmid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('requiredquizcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'requiredassigncmid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026071113, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071115) {
        $table = new xmldb_table('local_ga_workshop_editions');
        $fields = [
            new xmldb_field('taskdescription', XMLDB_TYPE_TEXT, null, null, null, null, null, 'tasknumericgrade'),
            new xmldb_field('taskurl', XMLDB_TYPE_TEXT, null, null, null, null, null, 'taskdescription'),
            new xmldb_field('taskfileitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'taskurl'),
            new xmldb_field('taskduedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'taskfileitemid'),
        ];
        if ($dbman->table_exists($table)) {
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        $table = new xmldb_table('local_ga_task_submissions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('fileitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('grade', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
            $table->add_field('gradedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timegraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'submitted');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('edition_user', XMLDB_INDEX_UNIQUE, ['editionid', 'userid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026071115, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071123) {
        $table = new xmldb_table('local_ga_typeb_certs');
        $field = new xmldb_field('activitydescription', XMLDB_TYPE_TEXT, null, null, null, null, null, 'hours');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026071123, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071125) {
        local_gestion_actividades_add_index_if_possible($dbman, 'local_ga_certificates', 'userid', ['userid']);
        local_gestion_actividades_add_index_if_possible($dbman, 'local_ga_hour_history', 'useridx', ['userid']);
        local_gestion_actividades_add_index_if_possible($dbman, 'local_ga_typeb_certs', 'userid', ['userid']);
        local_gestion_actividades_add_index_if_possible($dbman, 'local_ga_typeb_certs', 'userstatus', ['userid', 'status']);
        upgrade_plugin_savepoint(true, 2026071125, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071128) {
        $table = new xmldb_table('local_ga_institutional_hours');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fullname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('courselevel', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('groupname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('typeahours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('typebhours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('source', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'Reconocimiento institucional');
            $table->add_field('importid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('originalrow', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('rawdata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);
            $table->add_index('email', XMLDB_INDEX_NOTUNIQUE, ['email']);
            $dbman->create_table($table);
        } else {
            $fields = [
                new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id'),
                new xmldb_field('email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'userid'),
                new xmldb_field('fullname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'email'),
                new xmldb_field('courselevel', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'fullname'),
                new xmldb_field('groupname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'courselevel'),
                new xmldb_field('typeahours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'groupname'),
                new xmldb_field('typebhours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'typeahours'),
                new xmldb_field('source', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'Reconocimiento institucional', 'typebhours'),
                new xmldb_field('importid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'source'),
                new xmldb_field('originalrow', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'importid'),
                new xmldb_field('rawdata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'originalrow'),
                new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'rawdata'),
                new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated'),
                new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
            $index = new xmldb_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
            $index = new xmldb_index('email', XMLDB_INDEX_NOTUNIQUE, ['email']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2026071128, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071132) {
        $table = new xmldb_table('local_ga_task_submissions');
        if ($dbman->table_exists($table)) {
            $fields = [
                new xmldb_field('grade', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'fileitemid'),
                new xmldb_field('gradedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'grade'),
                new xmldb_field('timegraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'gradedby'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026071132, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071134) {
        $table = new xmldb_table('local_ga_institutional_hours');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('taskgrade', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'typebhours');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026071134, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071135) {
        $table = new xmldb_table('local_ga_workshops');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('workshoptype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'typea', 'hours');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_ga_certificates');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('certificatetype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'typea', 'editionid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $index = new xmldb_index('certificatetype', XMLDB_INDEX_NOTUNIQUE, ['certificatetype']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        $table = new xmldb_table('local_ga_typeb_reflections');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reflectiontext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('editionuser', XMLDB_INDEX_UNIQUE, ['editionid', 'userid']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026071135, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071137) {
        $table = new xmldb_table('local_ga_typeb_transfers');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('certificateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('editionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('hours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reflectiontext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('certificateid', XMLDB_INDEX_UNIQUE, ['certificateid']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('userstatus', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
            $table->add_index('editionid', XMLDB_INDEX_NOTUNIQUE, ['editionid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026071137, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071400) {
        $table = new xmldb_table('local_ga_course_settings');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('selfassessmentcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('courseid', XMLDB_INDEX_UNIQUE, ['courseid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026071400, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071401) {
        $table = new xmldb_table('local_ga_institutional_hours');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('typebreflection', XMLDB_TYPE_TEXT, null, null, null, null, null, 'taskgrade');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('typebreflectionmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'typebreflection');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $editiontable = new xmldb_table('local_ga_workshop_editions');
        $workshoptable = new xmldb_table('local_ga_workshops');
        if ($dbman->table_exists($editiontable) && $dbman->table_exists($workshoptable)) {
            $DB->execute("UPDATE {local_ga_workshop_editions} SET requiredmodname = 'assign', activitycreationtype = 'assign' WHERE workshopid IN (SELECT id FROM {local_ga_workshops} WHERE workshoptype = 'typea' OR workshoptype IS NULL OR workshoptype = '')");
            $DB->execute("UPDATE {local_ga_workshop_editions} SET requiredmodname = '', activitycreationtype = '', requiredcmid = 0, requiredassigncmid = 0, requiredquizcmid = 0 WHERE workshopid IN (SELECT id FROM {local_ga_workshops} WHERE workshoptype = 'typeb')");
        }
        upgrade_plugin_savepoint(true, 2026071401, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071402) {
        try {
            $courses = \local_gestion_actividades\local\grade_manager::get_managed_courses();
            foreach ($courses as $course) {
                $courseid = (int)$course->id;
                \local_gestion_actividades\local\grade_manager::sync_course_safely($courseid);
                \local_gestion_actividades\local\grade_manager::ensure_selfassessment_availability($courseid);
            }
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('La migración del criterio de autoevaluación HEE continuará de forma diferida: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        upgrade_plugin_savepoint(true, 2026071402, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071404) {
        try {
            $courses = \local_gestion_actividades\local\grade_manager::get_managed_courses();
            foreach ($courses as $course) {
                \local_gestion_actividades\local\grade_manager::ensure_selfassessment_availability((int)$course->id);
            }
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('La restricción de sección de autoevaluación HEE se reintentará al guardar el selector: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        upgrade_plugin_savepoint(true, 2026071404, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071405) {
        try {
            \local_gestion_actividades\local\manager::ensure_all_workshop_course_visuals();
            \local_gestion_actividades\local\grade_manager::repair_configured_selfassessment_availability();
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('La reparación visual HEE puede repetirse desde el panel: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        upgrade_plugin_savepoint(true, 2026071405, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026071411) {
        try {
            \local_gestion_actividades\local\manager::ensure_all_workshop_course_visuals();
            \local_gestion_actividades\local\grade_manager::repair_configured_selfassessment_availability();
        } catch (\Throwable $e) {
            if (function_exists('debugging')) {
                debugging('La normalización de horas y orden de secciones HEE puede repetirse desde el panel: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        upgrade_plugin_savepoint(true, 2026071411, 'local', 'gestion_actividades');
    }

    foreach ([2026071420, 2026071421, 2026071422, 2026071423, 2026071424, 2026071429] as $version) {
        if ($oldversion < $version) {
            try {
                \local_gestion_actividades\local\manager::ensure_all_workshop_course_visuals();
            } catch (\Throwable $e) {
                if (function_exists('debugging')) {
                    debugging('No se pudieron reconstruir todas las tarjetas HEE durante la actualización: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            upgrade_plugin_savepoint(true, $version, 'local', 'gestion_actividades');
        }
    }

    if ($oldversion < 2026100510) {
        // Formal, non-destructive consolidation of structures that had previously
        // been created defensively at runtime by the HEE classes.
        $table = new xmldb_table('local_ga_edition_enrolments');
        if ($dbman->table_exists($table)) {
            $fields = [
                new xmldb_field('attended', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timeattended', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('attendedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }

        local_gestion_actividades_add_index_if_possible($dbman, 'local_ga_typeb_certs', 'userstatus', ['userid', 'status']);
        upgrade_plugin_savepoint(true, 2026100510, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026100513) {
        $series = new xmldb_table('local_ga_workshop_series');
        if (!$dbman->table_exists($series)) {
            $series->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $series->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $series->add_field('datefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('dateto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('calendarcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('calendarsectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $series->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $series->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $series->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $series->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($series);
        }

        $items = new xmldb_table('local_ga_series_items');
        if (!$dbman->table_exists($items)) {
            $items->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $items->add_field('seriesid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('sessionenddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('subsectioncmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('subsectionsectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('notescmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $items->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $items->add_index('seriesworkshop', XMLDB_INDEX_UNIQUE, ['seriesid', 'workshopid']);
            $items->add_index('seriesorder', XMLDB_INDEX_NOTUNIQUE, ['seriesid', 'sortorder']);
            $items->add_index('workshopid', XMLDB_INDEX_NOTUNIQUE, ['workshopid']);
            $dbman->create_table($items);
        } else {
            $endfield = new xmldb_field('sessionenddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sortorder');
            if (!$dbman->field_exists($items, $endfield)) {
                $dbman->add_field($items, $endfield);
            }
        }

        $editions = new xmldb_table('local_ga_workshop_editions');
        $seriesfield = new xmldb_field('seriesid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'workshopid');
        if ($dbman->table_exists($editions) && !$dbman->field_exists($editions, $seriesfield)) {
            $dbman->add_field($editions, $seriesfield);
        }
        if ($dbman->table_exists($editions)) {
            $seriesindex = new xmldb_index('seriesid', XMLDB_INDEX_NOTUNIQUE, ['seriesid']);
            if (!$dbman->index_exists($editions, $seriesindex)) {
                $dbman->add_index($editions, $seriesindex);
            }
            $rows = $DB->get_records_select('local_ga_workshop_editions', 'seriesid = 0 OR seriesid IS NULL');
            foreach ($rows as $edition) {
                $sql = "SELECT s.id
                          FROM {local_ga_workshop_series} s
                          JOIN {local_ga_series_items} i ON i.seriesid = s.id
                         WHERE i.workshopid = :workshopid
                           AND s.datefrom <= :sessionfrom
                           AND s.dateto >= :sessionto
                      ORDER BY s.datefrom DESC, s.id DESC";
                $matches = $DB->get_records_sql($sql, [
                    'workshopid' => (int)$edition->workshopid,
                    'sessionfrom' => (int)$edition->sessiondate,
                    'sessionto' => (int)$edition->sessiondate,
                ], 0, 1);
                if ($matches) {
                    $match = reset($matches);
                    $DB->set_field('local_ga_workshop_editions', 'seriesid', (int)$match->id, ['id' => (int)$edition->id]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026100513, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026100516) {
        // Several tables and fields used to be created at runtime by the HEE
        // classes; that code was removed, so sites that never visited those
        // pages (or upgrade from 1.5.86) could miss them, e.g.
        // local_ga_typeb_certs.reflectiontext/reflectiontime. Reconcile the
        // live schema with install.xml, adding only what is missing.
        local_gestion_actividades_reconcile_schema_with_install_xml($dbman);
        upgrade_plugin_savepoint(true, 2026100516, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026100563) {
        // Teacher name shown in the Edición calendar (Excel «Nombre profesor»).
        // Non-destructive: adds one nullable column.
        $table = new xmldb_table('local_ga_series_items');
        $field = new xmldb_field('teachernames', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'notescmid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026100563, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026100575) {
        // Two-day workshops: optional second day (Excel «Fecha 2 / Inicio 2 /
        // Fin 2»). Non-destructive: adds two columns defaulting to 0.
        $table = new xmldb_table('local_ga_series_items');
        foreach (['session2date' => 'teachernames', 'session2enddate' => 'session2date'] as $name => $after) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', $after);
            if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026100575, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026100579) {
        // External Type B catalogue: the admin lists the talleres (name +
        // hours) and students pick one when uploading the certificate.
        // Non-destructive: new table + one column defaulting to 0.
        $table = new xmldb_table('local_ga_typeb_catalog');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('hours', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('active', XMLDB_INDEX_NOTUNIQUE, ['active']);
            $dbman->create_table($table);
        }
        $certs = new xmldb_table('local_ga_typeb_certs');
        $field = new xmldb_field('catalogid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if ($dbman->table_exists($certs) && !$dbman->field_exists($certs, $field)) {
            $dbman->add_field($certs, $field);
        }
        $index = new xmldb_index('usercatalog', XMLDB_INDEX_NOTUNIQUE, ['userid', 'catalogid']);
        if ($dbman->table_exists($certs) && !$dbman->index_exists($certs, $index)) {
            $dbman->add_index($certs, $index);
        }
        upgrade_plugin_savepoint(true, 2026100579, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026100590) {
        // Curso académico per Edición de talleres (non-destructive: new
        // column, filled from each Edición's start date, 1 September rule).
        $series = new xmldb_table('local_ga_workshop_series');
        $field = new xmldb_field('academicyear', XMLDB_TYPE_CHAR, '9', null, XMLDB_NOTNULL, null, '', 'timemodified');
        if ($dbman->table_exists($series) && !$dbman->field_exists($series, $field)) {
            $dbman->add_field($series, $field);
        }
        if ($dbman->table_exists($series)) {
            $rs = $DB->get_recordset('local_ga_workshop_series', null, '', 'id, datefrom, timecreated, academicyear');
            foreach ($rs as $row) {
                if (!\local_gestion_actividades\local\academic_year::is_valid((string)$row->academicyear)) {
                    $DB->set_field('local_ga_workshop_series', 'academicyear',
                        \local_gestion_actividades\local\academic_year::for_time((int)$row->datefrom ?: (int)$row->timecreated),
                        ['id' => $row->id]);
                }
            }
            $rs->close();
        }
        upgrade_plugin_savepoint(true, 2026100590, 'local', 'gestion_actividades');
    }

    if ($oldversion < 2026100592) {
        // Historical Tipo B PAT results. Non-destructive: new table only.
        $table = new xmldb_table('local_ga_typeb_pat');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('studentkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('studentid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('dni', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('firstname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('lastname', XMLDB_TYPE_CHAR, '150', null, XMLDB_NOTNULL, null, '');
            $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('workshopkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('workshopname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('academicyear', XMLDB_TYPE_CHAR, '9', null, XMLDB_NOTNULL, null, '');
            $table->add_field('attendance', XMLDB_TYPE_NUMBER, '10,2', null, null, null, null);
            $table->add_field('grade', XMLDB_TYPE_NUMBER, '10,2', null, null, null, null);
            $table->add_field('hours', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('passed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcefile', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('studentkey', XMLDB_INDEX_NOTUNIQUE, ['studentkey']);
            $table->add_index('workshopkey', XMLDB_INDEX_NOTUNIQUE, ['workshopkey']);
            $table->add_index('academicyear', XMLDB_INDEX_NOTUNIQUE, ['academicyear']);
            $table->add_index('studentworkshopyear', XMLDB_INDEX_UNIQUE, ['studentkey', 'workshopkey', 'academicyear']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026100592, 'local', 'gestion_actividades');
    }

    return true;
}

/**
 * Add missing tables, fields and indexes declared in db/install.xml.
 * Non-destructive: nothing is dropped, renamed or altered.
 */
function local_gestion_actividades_reconcile_schema_with_install_xml($dbman): void {
    global $CFG;

    $xmldbfile = new xmldb_file($CFG->dirroot . '/local/gestion_actividades/db/install.xml');
    if (!$xmldbfile->fileExists() || !$xmldbfile->loadXMLStructure()) {
        throw new moodle_exception('Cannot load local_gestion_actividades install.xml');
    }
    $structure = $xmldbfile->getStructure();

    foreach ($structure->getTables() as $table) {
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            continue;
        }
        foreach ($table->getFields() as $field) {
            if ($dbman->field_exists($table, $field)) {
                continue;
            }
            $field->setPrevious(null);
            if ($field->getNotNull() && $field->getDefault() === null && $field->getType() !== XMLDB_TYPE_TEXT) {
                // A NOT NULL column without default cannot be added to a table
                // with rows; give it a neutral default for existing records.
                $field->setDefault(in_array($field->getType(), [XMLDB_TYPE_CHAR], true) ? '' : '0');
            }
            if ($field->getType() === XMLDB_TYPE_TEXT) {
                $field->setNotNull(false);
            }
            $dbman->add_field($table, $field);
        }
        foreach ($table->getIndexes() as $index) {
            if ($dbman->index_exists($table, $index)) {
                continue;
            }
            if ($index->getUnique()) {
                global $DB;
                $cols = implode(', ', $index->getFields());
                $dups = $DB->get_records_sql('SELECT ' . $cols . ', COUNT(1) AS n FROM {' . $table->getName() . '} GROUP BY '
                    . $cols . ' HAVING COUNT(1) > 1', [], 0, 1);
                if ($dups) {
                    debugging('Gestión HEE: índice único ' . $index->getName() . ' omitido en ' . $table->getName()
                        . ' porque hay filas duplicadas; revísalas manualmente.', DEBUG_DEVELOPER);
                    continue;
                }
            }
            try {
                $dbman->add_index($table, $index);
            } catch (Throwable $e) {
                // Typically a UNIQUE index over legacy duplicates: keep data, report it.
                debugging('Gestión HEE: no se pudo crear el índice ' . $index->getName() . ' en '
                    . $table->getName() . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
