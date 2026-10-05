<?php
// Capability definitions for Gestion_actividades.

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/gestion_actividades:manage' => [
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL | RISK_CONFIG,
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/gestion_actividades:view' => [
        'captype' => 'read',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],
    'local/gestion_actividades:takeattendance' => [
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],
    'local/gestion_actividades:viewowncertificates' => [
        'captype' => 'read',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],
];
