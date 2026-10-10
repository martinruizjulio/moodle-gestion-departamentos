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
 * portfolio_pdf_all.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_gestion_actividades\local\portfolio_pdf;
use local_gestion_actividades\local\portfolio_typeb;

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
if (!\local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/gestion_actividades:manage', 'nopermissions', '');
}
require_sesskey();

portfolio_typeb::ensure_table();

$userids = [];

if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_certificates'))) {
    $rows = $DB->get_records_sql("SELECT DISTINCT userid FROM {local_ga_certificates} WHERE userid > 0");
    foreach ($rows as $r) {
        $userids[(int)$r->userid] = true;
    }
}

if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_typeb_certs'))) {
    $rows = $DB->get_records_sql("SELECT DISTINCT userid FROM {local_ga_typeb_certs} WHERE userid > 0");
    foreach ($rows as $r) {
        $userids[(int)$r->userid] = true;
    }
}

if (!$userids) {
    redirect(new moodle_url('/local/gestion_actividades/portfolio_admin.php'), 'No hay portafolios para descargar.', null, \core\output\notification::NOTIFY_INFO);
}

$packer = get_file_packer('application/zip');
$tempdir = make_request_directory();
$files = [];

foreach (array_keys($userids) as $userid) {
    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
    if (!$user) {
        continue;
    }
    $pdf = portfolio_pdf::render_pdf_string((int)$userid);
    $filename = portfolio_pdf::filename_for_user($user);
    $path = $tempdir . '/' . $filename;
    file_put_contents($path, $pdf);
    $files[$filename] = $path;
}

if (!$files) {
    redirect(new moodle_url('/local/gestion_actividades/portfolio_admin.php'), 'No se ha podido generar ningún portafolio.', null, \core\output\notification::NOTIFY_ERROR);
}

$zipname = 'portafolios_certificados_' . date('Ymd_His') . '.zip';
$zippath = $tempdir . '/' . $zipname;
$packer->archive_to_pathname($files, $zippath);

send_temp_file($zippath, $zipname);
