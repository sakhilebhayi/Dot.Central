<?php

namespace App\Console\Commands;

use App\Models\ControlRoom;
use App\Services\Rules\RuleEngine;
use App\Services\Shifts\ShiftService;
use Illuminate\Console\Command;

class EvaluateRules extends Command
{
    protected $signature = 'rules:evaluate {room?}';

    protected $description = 'Evaluate the operational rules for every active control room and its current shift.';

    public function handle(RuleEngine $engine, ShiftService $shifts): int
    {
        $rooms = ControlRoom::withoutGlobalScopes()->where('is_active', true)->when($this->argument('room'), fn ($q, $id) => $q->whereKey((int) $id))->get();

        foreach ($rooms as $room) {
            $findings = $engine->evaluate($room, $shifts->current($room));
            $this->line($room->name.': '.$findings->count().' finding(s)');
        }

        return self::SUCCESS;
    }
}
