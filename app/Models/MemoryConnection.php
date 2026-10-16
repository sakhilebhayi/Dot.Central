<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Dot.Memory connection per team: the organisation's memory, not a
 * room's. The token leaves the encrypted column only inside
 * MemoryClientFactory. See docs/dot-central/14-dot-memory-integration.md.
 *
 * @property int $id
 * @property int $team_id
 * @property string $base_url
 * @property string|null $api_token
 * @property bool $enabled
 * @property string $status
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $last_write_at
 * @property Carbon|null $last_context_at
 * @property string|null $last_error
 */
class MemoryConnection extends Model
{
    use Auditable;
    use HasFactory;
    use HasTeamScope;

    public const STATUSES = ['unverified', 'verified', 'credential_invalid', 'unreachable'];

    protected $fillable = ['team_id', 'base_url', 'api_token', 'enabled', 'status', 'last_verified_at', 'last_write_at', 'last_context_at', 'last_error'];

    protected $hidden = ['api_token'];

    protected $casts = [
        'api_token' => 'encrypted',
        'enabled' => 'boolean',
        'last_verified_at' => 'datetime',
        'last_write_at' => 'datetime',
        'last_context_at' => 'datetime',
    ];

    /** @return list<string> */
    public function auditRedactedAttributes(): array
    {
        return ['api_token'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function baseUrl(): string
    {
        return rtrim($this->base_url, '/');
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->api_token !== null && $this->api_token !== '' && $this->status !== 'credential_invalid';
    }
}
