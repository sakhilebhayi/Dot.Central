<?php

namespace App\Services\Memory;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DowntimeClassification;
use App\Models\Equipment;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * One subject vocabulary shared with Dot.Mines and the guardian: a machine
 * is `equipment` with the Dot.Mines machine id, so Memory's context for it
 * is cross-platform. Anything without a machine belongs to its room; shifts
 * and line-ups are `shift` subjects.
 */
final class LoopSubject
{
    /** @return array{type:string,id:string,label:?string} */
    public static function for(Model $model): array
    {
        $equipment = match (true) {
            $model instanceof Alert, $model instanceof OperationalException, $model instanceof DowntimeClassification, $model instanceof ShiftOperatorAssignment => $model->equipment()->withoutGlobalScopes()->first(),
            default => null,
        };

        if ($equipment instanceof Equipment) {
            return ['type' => 'equipment', 'id' => (string) $equipment->mines_machine_id, 'label' => $equipment->name];
        }

        if ($model instanceof Shift) {
            return ['type' => 'shift', 'id' => (string) $model->id, 'label' => $model->label()];
        }

        if ($model instanceof ShiftLineup) {
            $shift = $model->shift()->withoutGlobalScopes()->first();

            return ['type' => 'shift', 'id' => (string) $model->shift_id, 'label' => $shift?->label()];
        }

        $roomId = $model instanceof ControlRoom ? $model->id : ($model->getAttribute('control_room_id') ?? null);
        $room = $model instanceof ControlRoom ? $model : ($roomId !== null ? ControlRoom::withoutGlobalScopes()->find($roomId) : null);

        return ['type' => 'control_room', 'id' => (string) ($roomId ?? 0), 'label' => $room?->name];
    }
}
