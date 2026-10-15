<?php

namespace App\Support;

/**
 * The line-up role a machine can take. Dot.Mines does not model a class
 * (its machine_type is a brand), so Dot.Central guesses one from the
 * model, manufacturer, name and capacity and lets an administrator set it.
 */
final class RoleClass
{
    public const LOADER = 'LOADER';

    public const HAUL = 'HAUL';

    public const SUPPORT = 'SUPPORT';

    public const ALL = [self::LOADER, self::HAUL, self::SUPPORT];

    public const LABELS = [self::LOADER => 'Loading unit', self::HAUL => 'Haul truck', self::SUPPORT => 'Support machine'];

    public static function guess(?string $name, ?string $model, ?string $manufacturer, ?string $machineType, ?float $capacity): string
    {
        $text = strtolower(trim(implode(' ', array_filter([$name, $model, $manufacturer, $machineType]))));

        if (preg_match('/\b(ex\d*|pc\d+|ec\d+|excavator|shovel|loader|wa\d+|l\d{3}|fel|tlb|backhoe)\b/i', $text) === 1) {
            return self::LOADER;
        }

        if (preg_match('/\b(adt|a\d{2}[a-z]?|b\d{2}[a-z]?|hd\d+|7\d{2}[a-z]?|haul|truck|dumper|ht-?\d+)\b/i', $text) === 1 || ($capacity !== null && $capacity >= 15)) {
            return self::HAUL;
        }

        return self::SUPPORT;
    }

    public static function label(?string $class): string
    {
        return self::LABELS[$class] ?? 'Unclassified';
    }
}
