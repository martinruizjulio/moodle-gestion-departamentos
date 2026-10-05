<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

final class date_helper {
    public static function parse_user_datetime(string $value): int {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            return 0;
        }
        $timezone = \core_date::get_user_timezone_object();
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dt !== false) {
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0)) {
                    return $dt->getTimestamp();
                }
            }
        }
        return 0;
    }

    public static function input_datetime(int $timestamp): string {
        // fixday/fixhour must be false: userdate() would otherwise strip the
        // leading zero of %d (2026-10-5T…), which a datetime-local input rejects.
        return $timestamp > 0 ? userdate($timestamp, '%Y-%m-%dT%H:%M', 99, false, false) : '';
    }
}
