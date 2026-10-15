<?php

namespace App\Models;

use Database\Factories\IntegrationSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $mines_connection_id
 * @property string $trigger
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $cursor_before
 * @property int|null $cursor_after
 * @property int $fetched_machines
 * @property int $upserted
 * @property int $retired
 * @property int $alerts_upserted
 * @property int $hours_updated
 * @property string|null $error_class
 * @property string|null $error_message
 */
class IntegrationSyncRun extends Model
{
    /** @use HasFactory<IntegrationSyncRunFactory> */
    use HasFactory;

    public const STATUSES = ['running', 'succeeded', 'partial', 'failed'];

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cursor_before' => 'integer',
        'cursor_after' => 'integer',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MinesConnection::class, 'mines_connection_id');
    }
}
