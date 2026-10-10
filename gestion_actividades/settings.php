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
 * settings.php page/script for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Administration settings for Gestion_actividades.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_gestion_actividades',
        get_string('pluginname', 'local_gestion_actividades'),
        new moodle_url('/local/gestion_actividades/index.php'),
        'local/gestion_actividades:manage'
    ));
}


$ADMIN->add('localplugins', new admin_externalpage(
    'local_gestion_actividades_dashboard',
    get_string('dashboard', 'local_gestion_actividades'),
    new moodle_url('/local/gestion_actividades/dashboard.php'),
    'local/gestion_actividades:manage'
));
