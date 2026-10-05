<?php
defined('MOODLE_INTERNAL') || die();

// Cache invalidation is triggered explicitly by local_gestion_actividades at the
// points where certificates, recognised hours and Type B records change. Moodle
// core does not expose generic file_created/file_deleted events for this purpose.
$observers = [];
