<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Readout formatting for the control room: space thousands separator,
 * fixed decimals, "—" for absent values, and ages in words.
 */
final class Format
{
    public static function number(int|float|null $value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format($value, $decimals, '.', ' ');
    }

    public static function integer(int|float|null $value): string
    {
        return self::number($value, 0);
    }

    public static function age(?CarbonInterface $then, ?CarbonInterface $now = null): string
    {
        if ($then === null) {
            return '—';
        }

        $seconds = (int) $then->diffInSeconds($now ?? now(), true);

        return match (true) {
            $seconds < 60 => $seconds.' s ago',
            $seconds < 3600 => intdiv($seconds, 60).' min ago',
            $seconds < 86400 => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min ago',
            default => intdiv($seconds, 86400).' d ago',
        };
    }

    public static function stamp(?CarbonInterface $value, string $timezone = 'UTC'): string
    {
        return $value === null ? '—' : $value->copy()->setTimezone($timezone)->format('Y-m-d H:i');
    }
}
