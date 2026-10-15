<?php

namespace App\Services;

use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\ShiftType;

/**
 * Gives a new control room its editable defaults: two shift types and a
 * generic stoppage-reason catalogue. Nothing here is customer-specific;
 * administrators change all of it.
 */
final class RoomProvisioner
{
    public function provision(ControlRoom $room): void
    {
        if ($room->shiftTypes()->doesntExist()) {
            ShiftType::create(['control_room_id' => $room->id, 'code' => 'DAY', 'name' => 'Day shift', 'starts_at' => '06:00', 'ends_at' => '18:00', 'crosses_midnight' => false, 'sort' => 1]);
            ShiftType::create(['control_room_id' => $room->id, 'code' => 'NIGHT', 'name' => 'Night shift', 'starts_at' => '18:00', 'ends_at' => '06:00', 'crosses_midnight' => true, 'sort' => 2]);
        }

        if ($room->downtimeReasons()->doesntExist()) {
            foreach (self::defaultReasons() as [$code, $name, $category, $availability, $description]) {
                DowntimeReason::create(['control_room_id' => $room->id, 'code' => $code, 'name' => $name, 'category' => $category, 'counts_against_availability' => $availability, 'requires_description' => $description]);
            }
        }
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: bool, 4: bool}> */
    public static function defaultReasons(): array
    {
        return [
            ['SERVICE', 'Planned service', 'PLANNED_MAINTENANCE', true, false],
            ['BRK_MECH', 'Breakdown — mechanical', 'BREAKDOWN', true, true],
            ['BRK_HYD', 'Breakdown — hydraulic', 'BREAKDOWN', true, true],
            ['BRK_ELEC', 'Breakdown — electrical', 'BREAKDOWN', true, true],
            ['BRK_TYRE', 'Breakdown — tyres', 'BREAKDOWN', true, true],
            ['DAMAGE', 'Damage', 'BREAKDOWN', true, true],
            ['NO_OPERATOR', 'Standby — no operator', 'NO_OPERATOR', false, false],
            ['STANDBY', 'Standby — no work', 'STANDBY', false, false],
            ['BLASTING', 'Blasting delay', 'BLASTING', false, false],
            ['REFUEL', 'Refuelling / servicing stop', 'OPERATIONAL_DELAY', false, false],
            ['WEATHER', 'Weather', 'WEATHER', false, false],
            ['OFFSITE', 'Off site', 'OFFSITE', false, false],
            ['SAFETY', 'Safety stop', 'SAFETY', false, false],
            ['OTHER', 'Other (describe)', 'OTHER', false, true],
        ];
    }
}
