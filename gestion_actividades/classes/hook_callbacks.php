<?php
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
