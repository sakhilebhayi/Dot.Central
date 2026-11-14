<?php

namespace App\Support;

/**
 * Is this host set up to receive a sign-in from InfoDot? (R-52)
 *
 * The receiver fails the same way for several different host settings, so each one is named here and reused by the
 * sign-in controller, the Guardian check and `php artisan sso:check`. Only the presence of the secret is ever
 * reported, never its value.
 */
final class SsoReceiverStatus
{
    public const SECRET_MISSING = 'secret_missing';

    public const REDEEM_URL_MISSING = 'redeem_url_missing';

    public const REDEEM_URL_INVALID = 'redeem_url_invalid';

    public const REDEEM_URL_NOT_HTTPS = 'redeem_url_not_https';

    public const REDEEM_HOST_MISMATCH = 'redeem_host_mismatch';

    public const PLATFORM_KEY_MISSING = 'platform_key_missing';

    /** Problems that stop every sign-in. The rest only weaken it. */
    public const BLOCKING = [
        self::SECRET_MISSING,
        self::REDEEM_URL_MISSING,
        self::REDEEM_URL_INVALID,
        self::REDEEM_HOST_MISMATCH,
        self::PLATFORM_KEY_MISSING,
    ];

    /** What to do, per problem: setting names only, never values. */
    public const ADVICE = [
        self::SECRET_MISSING => 'INFODOT_SERVICE_SECRET is empty on this host. Set it to the value of ECOSYSTEM_SECRET_CENTRAL in InfoDot, then run php artisan config:cache.',
        self::REDEEM_URL_MISSING => 'INFODOT_REDEEM_URL is empty. Set it to https://infodot.co.za/api/ecosystem/redeem, then run php artisan config:cache.',
        self::REDEEM_URL_INVALID => 'INFODOT_REDEEM_URL is not a web address. Set it to https://infodot.co.za/api/ecosystem/redeem, then run php artisan config:cache.',
        self::REDEEM_URL_NOT_HTTPS => 'INFODOT_REDEEM_URL does not use https. In production the secret travels in that request: use the https address.',
        self::REDEEM_HOST_MISMATCH => "The host in INFODOT_REDEEM_URL is not InfoDot's public host (DOT_INFODOT_URL), so every sign-in InfoDot sends is refused as coming from the wrong site. Make the two hosts the same, then run php artisan config:cache.",
        self::PLATFORM_KEY_MISSING => 'INFODOT_PLATFORM_KEY is empty. It must be this platform\'s key in InfoDot (central).',
    ];

    /** @return list<string> */
    public static function problems(): array
    {
        $problems = [];
        $url = trim((string) config('ecosystem.infodot.redeem_url', ''));
        $redeemHost = self::redeemHost();

        if (! filled(config('ecosystem.infodot.secret'))) {
            $problems[] = self::SECRET_MISSING;
        }

        if (! filled(config('ecosystem.infodot.platform'))) {
            $problems[] = self::PLATFORM_KEY_MISSING;
        }

        if ($url === '') {
            $problems[] = self::REDEEM_URL_MISSING;
        } elseif ($redeemHost === null) {
            $problems[] = self::REDEEM_URL_INVALID;
        } else {
            $infodotHost = self::infodotHost();

            if ($infodotHost !== null && $infodotHost !== $redeemHost) {
                $problems[] = self::REDEEM_HOST_MISMATCH;
            }

            if (app()->isProduction() && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                $problems[] = self::REDEEM_URL_NOT_HTTPS;
            }
        }

        return $problems;
    }

    /** @return list<string> the problems that stop sign-in outright */
    public static function blocking(): array
    {
        return array_values(array_intersect(self::problems(), self::BLOCKING));
    }

    public static function redeemHost(): ?string
    {
        return self::hostOf((string) config('ecosystem.infodot.redeem_url', ''));
    }

    public static function infodotHost(): ?string
    {
        return self::hostOf((string) config('ecosystem.platforms.infodot.url', ''));
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
