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
 * card_status_frame.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Server-rendered, per-user enrolment status for workshop cards.

define('NO_DEBUG_DISPLAY', true);
require_once(__DIR__ . '/../../config.php');

use local_gestion_actividades\local\manager;

global $DB, $USER;

$editionid = required_param('editionid', PARAM_INT);
$edition = $DB->get_record('local_ga_workshop_editions', ['id' => $editionid], '*', MUST_EXIST);
$workshop = $DB->get_record('local_ga_workshops', ['id' => (int)$edition->workshopid], '*', MUST_EXIST);
$course = get_course((int)$workshop->courseid);
require_login($course);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: SAMEORIGIN');

$enrolment = manager::get_edition_enrolment($editionid, (int)$USER->id);
$isenrolled = manager::is_active_enrolment($enrolment);
$closed = manager::is_edition_enrolment_closed($edition);

$label = get_string('enrolme', 'local_gestion_actividades');
$style = 'background:#4b0000;border:1px solid #4b0000;color:#fff;';
$href = new moodle_url('/local/gestion_actividades/enrol.php', ['id' => $editionid, 'sesskey' => sesskey()]);
$disabled = false;

if ($isenrolled) {
    $label = get_string('enrolledbutton', 'local_gestion_actividades');
    $style = 'background:#dff3e4;border:1px solid #9fd3ad;color:#1f6b35;font-weight:600;';
    $disabled = true;
} else if ($closed) {
    $label = get_string('enrolmentclosed', 'local_gestion_actividades');
    $style = 'background:#fff0d5;border:1px solid #efbd68;color:#8a4b00;font-weight:600;';
    $disabled = true;
}

?><!doctype html>
<html lang="<?php echo s(current_language()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
html,body{margin:0;padding:0;background:transparent;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
.ga-status{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:.45rem .85rem;border-radius:.5rem;text-decoration:none;font-size:16px;line-height:1.2;white-space:nowrap;<?php echo $style; ?>}
</style>
</head>
<body>
<?php if ($disabled) : ?>
<span class="ga-status" aria-disabled="true"><?php echo s($label); ?></span>
<?php else : ?>
<a class="ga-status" href="<?php echo $href->out(false); ?>" target="_top"><?php echo s($label); ?></a>
<?php endif; ?>
</body>
</html>
