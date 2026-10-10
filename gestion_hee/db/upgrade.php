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
 * Upgrade definitions for block_gestion_hee.
 *
 * @package    block_gestion_hee
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade steps for block_gestion_hee.
 *
 * @param mixed $oldversion
 * @return bool
 */
function xmldb_block_gestion_hee_upgrade($oldversion): bool {
    if ($oldversion < 2026071127) {
        if (class_exists('\block_gestion_hee\local\student_hours_cache')) {
            \block_gestion_hee\local\student_hours_cache::invalidate_schema();
            \block_gestion_hee\local\student_hours_cache::invalidate_all();
        }

        upgrade_block_savepoint(true, 2026071127, 'gestion_hee');
    }

    if ($oldversion < 2026071128) {
        if (class_exists('\block_gestion_hee\local\student_hours_cache')) {
            \block_gestion_hee\local\student_hours_cache::invalidate_schema();
            \block_gestion_hee\local\student_hours_cache::invalidate_all();
        }

        upgrade_block_savepoint(true, 2026071128, 'gestion_hee');
    }

    return true;
}
