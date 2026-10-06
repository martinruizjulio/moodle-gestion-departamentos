<?php
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
