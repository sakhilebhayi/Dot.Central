<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The frozen per-machine derivation of a closed shift. */
class ShiftUnitSummary extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'opening_at' => 'datetime', 'closing_at' => 'datetime', 'opening_hours' => 'float', 'closing_hours' => 'float',
        'run_hours' => 'float', 'idle_hours' => 'float', 'loads' => 'integer', 'production_quantity' => 'float', 'flags' => 'array',
    ];

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
