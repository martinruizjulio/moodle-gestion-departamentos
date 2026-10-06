<?php
// Datos de demostración HEE · acceso directo desde el curso (solo administradores).
defined('MOODLE_INTERNAL') || die();

/**
 * Adds «Datos de demostración HEE» to the course «Más» menu for site admins.
 */
function local_hee_demo_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    if (!has_capability('moodle/site:config', context_system::instance())) {
        return;
    }
    $navigation->add(
        get_string('pluginname', 'local_hee_demo'),
        new moodle_url('/local/hee_demo/index.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_hee_demo',
        new pix_icon('i/settings', '')
    );
}
