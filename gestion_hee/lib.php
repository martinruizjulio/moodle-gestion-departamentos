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
 * lib.php page/script for block_gestion_hee.
 *
 * @package    block_gestion_hee
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Invalidate the cached hour summary of one student.
 *
 * @param int $userid
 */
function block_gestion_hee_invalidate_user_cache(int $userid): void {
    \block_gestion_hee\local\student_hours_cache::invalidate_user($userid);
}

/**
 * Invalidate the cached hour summaries of several students.
 *
 * @param array $userids
 */
function block_gestion_hee_invalidate_users_cache(array $userids): void {
    \block_gestion_hee\local\student_hours_cache::invalidate_users($userids);
}

/**
 * Invalidate every cached student hour summary.
 */
function block_gestion_hee_invalidate_all_user_caches(): void {
    \block_gestion_hee\local\student_hours_cache::invalidate_all();
}


/**
 * Invalidate the cached database schema information.
 */
function block_gestion_hee_invalidate_schema_cache(): void {
    \block_gestion_hee\local\student_hours_cache::invalidate_schema();
}
