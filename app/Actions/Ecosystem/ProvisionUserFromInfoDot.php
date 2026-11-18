<?php

namespace App\Actions\Ecosystem;

use App\Exceptions\ClaimRequired;
use App\Models\Team;
use App\Models\User;
use App\Support\SsoFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Turns an InfoDot identity (already proven by the redeem endpoint) into a local user.
 *
 * R-52: matches by InfoDot's user uuid first (stable, survives an email change), then by verified email. An email
 * match stamps the uuid on the account; an account already stamped with a different uuid is never taken over by
 * email, because that would be a different InfoDot person using a reused address. Nothing is ever re-pointed.
 *
 * R-54: an existing account whose email was never verified here is NO LONGER taken over by replacing its password. It
 * goes to the claim flow (ClaimRequired): the person proves the address with an emailed code first, and nothing is
 * replaced, detached or wiped. A person InfoDot vouches for but who has no account here gets one, with a random
 * password and a personal team. This is the FALLBACK receiver's matching; with DOTSSO_ENABLED the shared package does it.
 */
class ProvisionUserFromInfoDot
{
    /** Why the last call returned null: SsoFailure::NO_ACCOUNT or SsoFailure::IDENTITY_CONFLICT. Null before a call or on success. */
    public ?string $failure = null;

    /**
     * @param  array<string, mixed>  $identity
     *
     * @throws ClaimRequired when an unverified local account holds the address and the InfoDot user id is known
     */
    public function __invoke(array $identity): ?User
    {
        $this->failure = null;

        $uuid = $this->uuidOf($identity);

        if ($uuid !== null && Schema::hasColumn('users', 'infodot_user_uuid')) {
            $linked = User::query()->where('infodot_user_uuid', $uuid)->first();

            if ($linked) {
                return $linked;
            }
        }

        $email = Str::lower(trim((string) ($identity['user']['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($identity['user']['email_verified'] ?? false) !== true) {
            $this->failure = SsoFailure::NO_ACCOUNT;

            return null;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user) {
            if ($uuid !== null && filled($user->infodot_user_uuid ?? null) && $user->infodot_user_uuid !== $uuid) {
                Log::warning('InfoDot sign-in refused: the account for this email is linked to a different InfoDot person.', ['user_id' => $user->id]);
                $this->failure = SsoFailure::IDENTITY_CONFLICT;

                return null;
            }

            if ($user->email_verified_at === null) {
                if ($uuid === null) {
                    // Nothing a claim could link to: refuse rather than guess.
                    Log::notice('InfoDot sign-in refused: unverified local account and no InfoDot user id in the answer.', ['user_id' => $user->id]);
                    $this->failure = SsoFailure::NO_ACCOUNT;

                    return null;
                }

                throw new ClaimRequired($user, ['sub' => $uuid, 'email' => $email, 'name' => Str::limit((trim((string) ($identity['user']['name'] ?? '')) ?: Str::before($email, '@')), 255, '')]);
            }

            $this->stamp($user, $uuid);

            return $user;
        }

        if (! config('ecosystem.infodot.provision', true)) {
            $this->failure = SsoFailure::NO_ACCOUNT;

            return null;
        }

        $name = trim((string) ($identity['user']['name'] ?? '')) ?: Str::before($email, '@');

        return $this->create($email, $name, $uuid);
    }

    /** A new, verified account with a random password and its personal team (also used by the shared package's provisioner). */
    public function create(string $email, string $name, ?string $uuid): User
    {
        return DB::transaction(function () use ($email, $name, $uuid) {
            $user = new User(['name' => Str::limit($name, 255, ''), 'email' => $email, 'password' => Hash::make(Str::random(48))]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->stamp($user, $uuid);

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]);
            $user->ownedTeams()->save($team);
            $user->switchTeam($team);

            return $user;
        });
    }

    /** @param array<string, mixed> $identity */
    private function uuidOf(array $identity): ?string
    {
        $uuid = $identity['user']['uuid'] ?? null;

        return is_string($uuid) && Str::isUuid($uuid) ? Str::lower($uuid) : null;
    }

    /** Remember the InfoDot uuid on a matched account that has none yet. A taken uuid is left alone. */
    private function stamp(User $user, ?string $uuid): void
    {
        if ($uuid === null || filled($user->infodot_user_uuid ?? null) || ! Schema::hasColumn('users', 'infodot_user_uuid')) {
            return;
        }

        if (User::query()->where('infodot_user_uuid', $uuid)->exists()) {
            return;
        }

        $user->forceFill(['infodot_user_uuid' => $uuid])->save();
    }
}
