<?php
namespace block_gestion_hee\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * The block stores no personal data of its own. It only renders data owned by
 * local_gestion_actividades, which declares it in its own privacy provider.
 * Per-user cached summaries are short-lived MUC entries derived from that data.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
