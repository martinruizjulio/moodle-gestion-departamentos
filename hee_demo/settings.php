<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_hee_demo',
        get_string('pluginname', 'local_hee_demo'),
        new moodle_url('/local/hee_demo/index.php'),
        'moodle/site:config'
    ));
}
