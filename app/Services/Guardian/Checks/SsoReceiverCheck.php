<?php

namespace App\Services\Guardian\Checks;

use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use App\Support\SsoReceiverStatus;

/**
 * Can this host receive a sign-in from InfoDot? (R-52)
 *
 * A blank secret, a blank or malformed redeem address, or a redeem host that is not InfoDot's public host each stop
 * every sign-in while looking, to the person, like being logged out. This reports them without waiting for a visitor.
 * It reads settings only (no call to InfoDot, no database), and reports the presence of the secret, never its value.
 */
class SsoReceiverCheck implements GuardianCheck
{
    public function key(): string
    {
        return 'sso_receiver_configured';
    }

    public function run(): CheckResult
    {
        $problems = SsoReceiverStatus::problems();
        $metrics = [
            'secret_present' => filled(config('ecosystem.infodot.secret')),
            'platform_key' => (string) config('ecosystem.infodot.platform'),
            'redeem_host' => SsoReceiverStatus::redeemHost(),
            'infodot_host' => SsoReceiverStatus::infodotHost(),
            'problems' => $problems,
        ];

        if ($problems === []) {
            return CheckResult::healthy('Sign-in from InfoDot is set up.', $metrics);
        }

        $message = 'Sign-in from InfoDot is not set up correctly: '.implode(', ', $problems).'.';

        return array_intersect($problems, SsoReceiverStatus::BLOCKING) !== []
            ? CheckResult::critical($message, $metrics)
            : CheckResult::warning($message, $metrics);
    }
}
