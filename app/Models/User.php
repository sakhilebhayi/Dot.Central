<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * The control-room role this user holds on a team. Team owners are
     * administrators; non-members have no role.
     */
    public function roleOn(Team $team): ?Role
    {
        if ($this->ownsTeam($team)) {
            return Role::Administrator;
        }

        if (! $this->belongsToTeam($team)) {
            return null;
        }

        $key = $this->teamRole($team)?->key;

        return $key === null ? null : Role::tryFrom($key);
    }

    /**
     * Control rooms this user is assigned to (site scope).
     */
    public function controlRooms(): BelongsToMany
    {
        return $this->belongsToMany(ControlRoom::class)->withTimestamps();
    }

    public function hasAnyRoleOn(Team $team, Role ...$roles): bool
    {
        $role = $this->roleOn($team);

        return $role !== null && in_array($role, $roles, true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
