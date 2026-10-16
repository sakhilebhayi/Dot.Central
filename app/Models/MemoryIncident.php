<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Our own copy of an ops incident reported to Dot.Memory, so the reporter
 * knows what is open and can resolve it when the condition clears.
 *
 * @property int $id
 * @property int $team_id
 * @property string $incident_uid
 * @property string $signature
 * @property string $component
 * @property string $severity
 * @property string $status
 * @property Carbon $detected_at
 * @property Carbon|null $resolved_at
 */
class MemoryIncident extends Model
{
    protected $fillable = ['team_id', 'incident_uid', 'signature', 'component', 'severity', 'status', 'detected_at', 'resolved_at'];

    protected $casts = ['detected_at' => 'datetime', 'resolved_at' => 'datetime'];
}
