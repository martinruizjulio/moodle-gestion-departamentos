<?php
namespace local_gestion_actividades\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Issues certificates and records hours automatically when workshops finish.
 */
class generate_workshop_certificates extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_generate_workshop_certificates', 'local_gestion_actividades');
    }

    public function execute(): void {
        $issued = \local_gestion_actividades\local\auto_certificates::run();
        mtrace('Certificados HEE emitidos automáticamente: ' . $issued);
    }
}
