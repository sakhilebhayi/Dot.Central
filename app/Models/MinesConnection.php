<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\MinesConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The Dot.Mines connection of a control room (architecture decision D1):
 * one Dot.Mines team, one human-minted Sanctum token, stored encrypted and
 * never rendered or logged.
 *
 * @property int $id
 * @property int $control_room_id
 * @property string $base_url
 * @property int|null $mines_team_id
 * @property string|null $api_token
 * @property string|null $webhook_secret
 * @property int $provider_cadence_seconds
 * @property string $timezone
 * @property string $status
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property int|null $sync_cursor
 */
class MinesConnection extends Model
{
    /** @use HasFactory<MinesConnectionFactory> */
    use Auditable, HasControlRoomTeamScope, HasFactory;

    public const STATUSES = ['unverified', 'verified', 'credential_invalid', 'disabled'];

    protected $fillable = [
        'control_room_id', 'base_url', 'mines_team_id', 'api_token', 'webhook_secret',
        'provider_cadence_seconds', 'timezone', 'status', 'last_success_at', 'last_failure_at',
        'last_error', 'consecutive_failures', 'sync_cursor',
    ];

    protected $hidden = ['api_token', 'webhook_secret'];

    /** @var list<string> */
    protected array $auditRedacted = ['api_token', 'webhook_secret'];

    protected $casts = [
        'api_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'mines_team_id' => 'integer',
        'provider_cadence_seconds' => 'integer',
        'consecutive_failures' => 'integer',
        'sync_cursor' => 'integer',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return HasMany<IntegrationSyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(IntegrationSyncRun::class);
    }

    /**
     * Base URL without a trailing slash; the client appends /api/v1.
     */
    public function baseUrl(): string
    {
        return rtrim($this->base_url, '/');
    }

    public function isUsable(): bool
    {
        return $this->api_token !== null
            && $this->api_token !== ''
            && ! in_array($this->status, ['credential_invalid', 'disabled'], true);
    }
}
