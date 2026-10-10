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
 * portfolio_package_download.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;
use local_gestion_actividades\local\portfolio_pdf;
use local_gestion_actividades\local\portfolio_typeb;

$userid = optional_param('userid', 0, PARAM_INT);
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$canmanage = \local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id);

if ($userid <= 0) {
    $userid = (int)$USER->id;
}

if ((int)$userid !== (int)$USER->id && !$canmanage) {
    throw new required_capability_exception($context, 'local/gestion_actividades:manage', 'nopermissions', '');
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
portfolio_typeb::ensure_table();

$packer = get_file_packer('application/zip');
$tempdir = make_request_directory();
$files = [];

$mainpdf = portfolio_pdf::render_pdf_string((int)$userid);
$mainname = '00_portafolio_' . clean_filename(fullname($user)) . '.pdf';
$mainpath = $tempdir . '/' . $mainname;
file_put_contents($mainpath, $mainpdf);
$files[$mainname] = $mainpath;

$typeacerts = method_exists(manager::class, 'list_user_certificates') ? manager::list_user_certificates((int)$userid) : [];
usort($typeacerts, function ($a, $b) {
    return ((int)($b->timeissued ?? 0)) <=> ((int)($a->timeissued ?? 0));
});

$n = 1;
foreach ($typeacerts as $cert) {
    $course = $DB->get_record('course', ['id' => (int)$cert->courseid], '*', IGNORE_MISSING);
    if (!$course) {
        continue;
    }
    $coursecontext = context_course::instance((int)$course->id, IGNORE_MISSING);
    if (!$coursecontext) {
        continue;
    }
    $fs = get_file_storage();
    $file = $fs->get_file($coursecontext->id, 'local_gestion_actividades', 'certificate', (int)$cert->id, '/', $cert->filename);
    if (!$file || $file->is_directory()) {
        $area = $fs->get_area_files($coursecontext->id, 'local_gestion_actividades', 'certificate', (int)$cert->id, 'filename', false);
        foreach ($area as $candidate) {
            if (!$candidate->is_directory()) {
                $file = $candidate;
                break;
            }
        }
    }
    if (!$file || $file->is_directory()) {
        continue;
    }
    $name = sprintf('01_Tipo_A/%02d_%s_%s.pdf', $n++, userdate((int)$cert->timeissued, '%Y%m%d'), clean_filename(($cert->workshopcode ?? 'certificado') . '_' . ($cert->workshopname ?? 'tipo_a')));
    $path = $tempdir . '/tipoa_' . $n . '.pdf';
    $file->copy_content_to($path);
    $files[$name] = $path;
}

// Solo las solicitudes externas Tipo B validadas y con reflexión forman parte del expediente oficial.
$typebcerts = portfolio_pdf::get_typeb_certificates((int)$userid);
usort($typebcerts, function ($a, $b) {
    return ((int)($b->activitydate ?? 0)) <=> ((int)($a->activitydate ?? 0));
});

$n = 1;
$fs = get_file_storage();
foreach ($typebcerts as $cert) {
    $file = $fs->get_file($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$cert->id, '/', $cert->filename);
    if (!$file || $file->is_directory()) {
        $area = $fs->get_area_files($context->id, 'local_gestion_actividades', 'typeb_certificate', (int)$cert->id, 'filename', false);
        foreach ($area as $candidate) {
            if (!$candidate->is_directory()) {
                $file = $candidate;
                break;
            }
        }
    }
    if (!$file || $file->is_directory()) {
        continue;
    }

    $extension = pathinfo((string)$cert->filename, PATHINFO_EXTENSION);
    $extension = $extension !== '' ? strtolower($extension) : 'pdf';
    $name = sprintf(
        '02_Tipo_B_Externos_Validados/%02d_%s_%s.%s',
        $n++,
        userdate((int)$cert->activitydate, '%Y%m%d'),
        clean_filename($cert->activityname),
        clean_param($extension, PARAM_ALPHANUMEXT)
    );
    $path = $tempdir . '/tipob_' . $n . '_' . clean_filename($cert->filename);
    $file->copy_content_to($path);
    $files[$name] = $path;
}

$zipname = clean_filename('expediente_portafolio_' . fullname($user) . '.zip');
$zippath = $tempdir . '/' . $zipname;
$packer->archive_to_pathname($files, $zippath);

send_temp_file($zippath, $zipname);
