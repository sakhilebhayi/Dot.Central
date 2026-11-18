<?php

namespace App\Sso;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Mail\SsoClaimCodeMail;
use App\Models\User;
use Dot\SsoClient\Contracts\UserProvisioner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;

/**
 * What the shared sign-in package needs to know about Central's users table. Matching rules and the claim flow live in
 * the package; this class only reads and writes users. It never touches a password, a team or control-room membership,
 * two-factor settings or tokens. The stored `infodot_user_uuid` and the email lock (R-52) are the same ones the
 * /auth/ecosystem fallback receiver uses.
 */
class CentralProvisioner implements UserProvisioner
{
    public function findByUuid(string $uuid): ?Authenticatable
    {
        return User::query()->where('infodot_user_uuid', strtolower($uuid))->first();
    }

    public function findByEmail(string $email): ?Authenticatable
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    public function uuidOf(Authenticatable $user): ?string
    {
        return $user instanceof User ? $user->infodot_user_uuid : null;
    }

    public function hasVerifiedEmail(Authenticatable $user): bool
    {
        return $user instanceof User && $user->email_verified_at !== null;
    }

    public function link(Authenticatable $user, string $uuid): void
    {
        $user->forceFill(['infodot_user_uuid' => strtolower($uuid)])->save();
    }

    public function confirmEmail(Authenticatable $user): void
    {
        if ($user instanceof User && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function create(array $claims): ?Authenticatable
    {
        return app(ProvisionUserFromInfoDot::class)->create($claims['email'], $claims['name'], $claims['sub']);
    }

    public function afterSignIn(Authenticatable $user, array $claims): void
    {
        // Company teams are not in the OIDC token (phase 3/4); the personal workspace is made at creation. Control-room
        // membership, line-ups and roles are never touched here.
    }

    public function sendClaimCode(Authenticatable $user, string $code, int $minutes): void
    {
        if ($user instanceof User) {
            Mail::to($user->email)->send(new SsoClaimCodeMail($user->name, $code, $minutes));
        }
    }
}
