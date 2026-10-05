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
];
