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
 * Class hook_callbacks for local_gestion_actividades.
 *
 * @package    local_gestion_actividades
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gestion_actividades;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks (Moodle 4.4+) delegating to the existing lib.php functions.
 */
class hook_callbacks {
    private static function load_lib(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/gestion_actividades/lib.php');
    }

    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        self::load_lib();
        local_gestion_actividades_before_http_headers();
    }

    public static function before_footer_html_generation(\core\hook\output\before_footer_html_generation $hook): void {
        self::load_lib();
        local_gestion_actividades_before_footer();
    }

    public static function before_standard_head_html_generation(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        self::load_lib();
        $hook->add_html(local_gestion_actividades_before_standard_html_head());
    }
}
