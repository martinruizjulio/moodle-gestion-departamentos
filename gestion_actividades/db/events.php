<?php
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
];
