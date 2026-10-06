<?php
// This file is part of Moodle - http://moodle.org/
//
// Datos de demostración HEE: plugin TEMPORAL para presentaciones. Genera
// alumnos ficticios con movimiento real en las Ediciones de talleres y los
// borra después. No forma parte de la versión final de Gestión HEE.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_hee_demo';
$plugin->version   = 2026100701;
$plugin->requires  = 2022041900;
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.1-demo';
$plugin->dependencies = ['local_gestion_actividades' => 2026100563];
