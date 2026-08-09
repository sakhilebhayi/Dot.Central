<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\StaleSessionProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaleSessionProposal extends Model
{
    /** @use HasFactory<StaleSessionProposalFactory> */
    use HasControlRoomTeamScope, HasFactory;

    protected $fillable = [
        'operator_session_id', 'control_room_id', 'hours_silent',
        'status', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function operatorSession(): BelongsTo
    {
        return $this->belongsTo(OperatorSession::class);
    }

    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
