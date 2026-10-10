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
 * Events definitions for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\user_graded',
        'callback' => '\\local_gestion_actividades\\observer::user_graded',
        'priority' => 9999,
    ],
    [
        'eventname' => '\\mod_assign\\event\\submission_created',
        'callback' => '\\local_gestion_actividades\\observer::assign_submission_changed',
        'priority' => 9999,
    ],
    [
        'eventname' => '\\mod_assign\\event\\submission_updated',
        'callback' => '\\local_gestion_actividades\\observer::assign_submission_changed',
        'priority' => 9999,
    ],
    [
        // Fired after the submission status is stored as "submitted". The plugin
        // submission_created/updated events fire before that status change.
        'eventname' => '\\mod_assign\\event\\assessable_submitted',
        'callback' => '\\local_gestion_actividades\\observer::assign_submission_changed',
        'priority' => 9999,
    ],
    [
        // Student removes the submission or a teacher reverts it to draft: the
        // reflection must stop counting and the HEE grades be recalculated.
        'eventname' => '\\mod_assign\\event\\submission_removed',
        'callback' => '\\local_gestion_actividades\\observer::assign_submission_changed',
        'priority' => 9999,
    ],
    [
        'eventname' => '\\mod_assign\\event\\submission_status_updated',
        'callback' => '\\local_gestion_actividades\\observer::assign_submission_changed',
        'priority' => 9999,
    ],
    [
        // Workshop attendance list is the source of truth for HEE attendance.
        'eventname' => '\\mod_attendance\\event\\attendance_taken',
        'callback' => '\\local_gestion_actividades\\observer::attendance_taken',
        'priority' => 9999,
    ],
    [
        'eventname' => '\\mod_attendance\\event\\attendance_taken_by_student',
        'callback' => '\\local_gestion_actividades\\observer::attendance_taken',
        'priority' => 9999,
    ],
    [
        // Quiz/reflection dates drive the "Materiales T0X" folder window.
        'eventname' => '\\core\\event\\course_module_updated',
        'callback' => '\\local_gestion_actividades\\observer::course_module_updated',
        'priority' => 9999,
    ],
];
