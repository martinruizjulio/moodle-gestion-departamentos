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
 * template.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}

$type = optional_param('type', 'users', PARAM_ALPHA);

if ($type === 'users') {
    $filename = 'plantilla_usuarios_gestion_actividades.csv';
    $content = "username;email;firstname;lastname;idnumber;nota\n";
    $content .= "alumno001;alumno001@universidad.es;Ana;Garcia;1001;9.4\n";
    $content .= "alumno002;alumno002@universidad.es;Luis;Perez;1002;8.7\n";
    $content .= "alumno003;alumno003@universidad.es;Maria;Soler;1003;9.1\n";
} else {
    $filename = 'plantilla_notas_gestion_actividades.csv';
    $content = "email;firstname;lastname;nota\n";
    $content .= "alumno001@universidad.es;Ana;Garcia;9.4\n";
}

\core\session\manager::write_close();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . clean_filename($filename) . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo "\xEF\xBB\xBF" . $content;
exit;
