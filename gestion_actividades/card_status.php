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
 * card_status.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

global $USER;
require_login();
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$statuses = [];
foreach (['typea', 'typeb'] as $type) {
    foreach (manager::list_workshops($courseid, $type) as $workshop) {
        $edition = manager::get_primary_workshop_edition((int)$workshop->id);
        if (!$edition) {
            continue;
        }
        $statuses[(int)$edition->id] = manager::enrol_button_status($edition, (int)$USER->id);
    }
}

echo json_encode(['statuses' => $statuses], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
