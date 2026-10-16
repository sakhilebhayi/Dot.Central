<?php

namespace App\Support;

/**
 * What the control room shows for a machine, derived from Dot.Mines' own
 * status and how fresh our last contact is (docs/dot-central/03-architecture.md §5).
 * Stale or missing telemetry is always shown as such, never as "stopped".
 */
enum EquipmentState: string
{
    case Operating = 'operating';
    case Idle = 'idle';
    case Offline = 'offline';
    case Unavailable = 'unavailable';
    case Stale = 'stale';
    case Unknown = 'unknown';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operating',
            self::Idle => 'Idle',
            self::Offline => 'Offline',
            self::Unavailable => 'Unavailable',
            self::Stale => 'Stale',
            self::Unknown => 'Unknown',
            self::Retired => 'Retired',
        };
    }

    /** ok | warn | bad | neutral — maps to the status colour tokens. */
    public function tone(): string
    {
        return match ($this) {
            self::Operating => 'ok',
            self::Idle => 'info',
            self::Offline => 'bad',
            self::Unavailable => 'warn',
            self::Stale => 'warn',
            self::Unknown, self::Retired => 'neutral',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Operating => 'Engine running, reported within the provider cadence.',
            self::Idle => 'Engine off or not running, reported within the provider cadence.',
            self::Offline => 'Dot.Mines has had no contact with this machine for longer than its provider allows.',
            self::Unavailable => 'Marked as in maintenance in Dot.Mines.',
            self::Stale => 'Last report is older than twice the provider cadence; the last known state is shown dimmed.',
            self::Unknown => 'No usable telemetry: nothing reported, or the last report is older than four times the cadence.',
            self::Retired => 'Removed from the Dot.Mines fleet.',
        };
    }
}
