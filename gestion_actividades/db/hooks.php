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
 * Hooks definitions for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle 4.4+ output hooks. They replace the legacy lib.php callbacks
// (before_http_headers, before_footer, before_standard_html_head), which
// Moodle 4.4+ reports as deprecated on every page with developer debugging
// and which then blocks automatic redirects ("Error output, so disabling
// automatic redirect"). Older Moodle versions keep using the lib.php callbacks.

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_http_headers::class,
        'callback' => [\local_gestion_actividades\hook_callbacks::class, 'before_http_headers'],
    ],
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_gestion_actividades\hook_callbacks::class, 'before_footer_html_generation'],
    ],
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => [\local_gestion_actividades\hook_callbacks::class, 'before_standard_head_html_generation'],
    ],
];
