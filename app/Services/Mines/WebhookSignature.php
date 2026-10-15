<?php

namespace App\Services\Mines;

/**
 * Verifies Dot.Mines' outbound signature: `t=<unix>,v1=<hmac_sha256(t.body, secret)>`
 * with a bounded clock skew and a constant-time comparison.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Mines-Signature';

    public static function verify(string $header, string $rawBody, string $secret, int $skewSeconds, ?int $now = null): bool
    {
        $now ??= time();
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if (abs($now - $timestamp) > $skewSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $parts['v1']);
    }
}
