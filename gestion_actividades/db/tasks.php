<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        // Certificates + hours when each workshop finishes (quiz close /
        // reflection deadline, or end of the session).
        'classname' => 'local_gestion_actividades\task\generate_workshop_certificates',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
