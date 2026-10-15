<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $mines_connection_id
 * @property string $delivery_id
 * @property string $event
 * @property Carbon|null $occurred_at
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $error
 */
class IntegrationWebhookDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MinesConnection::class, 'mines_connection_id');
    }
}
