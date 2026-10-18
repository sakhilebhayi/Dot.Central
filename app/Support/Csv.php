<?php

namespace App\Support;

/**
 * CSV cells that come from people (a reason, a note, a name) must not run as
 * a spreadsheet formula when the file is opened: a text cell that starts with
 * = + - @, a tab or a carriage return gets a leading single quote. Numbers
 * and numeric strings are left alone.
 */
final class Csv
{
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
