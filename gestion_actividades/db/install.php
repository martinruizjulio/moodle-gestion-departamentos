<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_gestion_actividades_install(): bool {
    require_once(__DIR__ . '/../classes/local/exception_access.php');
    \local_gestion_actividades\local\exception_access::ensure_tables();
    return true;
}
