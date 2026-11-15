<?php

namespace App\Console\Commands;

use App\Support\SsoReceiverStatus;
use Illuminate\Console\Command;

class CheckSsoReceiver extends Command
{
    protected $signature = 'sso:check';

    protected $description = 'Check this host is set up to receive a sign-in from InfoDot (no network call, no secret printed)';

    public function handle(): int
    {
        $secret = (string) config('ecosystem.infodot.secret');

        $this->table(['Setting', 'State'], [
            ['INFODOT_SERVICE_SECRET', $secret === '' ? 'EMPTY' : 'set ('.strlen($secret).' characters, sha256 starts '.substr(hash('sha256', $secret), 0, 8).')'],
            ['INFODOT_PLATFORM_KEY', (string) config('ecosystem.infodot.platform') ?: 'EMPTY'],
            ['INFODOT_REDEEM_URL host', SsoReceiverStatus::redeemHost() ?? 'EMPTY or invalid'],
            ['InfoDot public host (DOT_INFODOT_URL)', SsoReceiverStatus::infodotHost() ?? 'EMPTY or invalid'],
        ]);

        $problems = SsoReceiverStatus::problems();

        foreach ($problems as $problem) {
            $this->line(($this->isBlocking($problem) ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>').' '.SsoReceiverStatus::ADVICE[$problem]);
        }

        if ($problems === []) {
            $this->info('Sign-in from InfoDot is set up. Compare the secret hash above with the one InfoDot prints for ECOSYSTEM_SECRET_CENTRAL.');
        }

        return SsoReceiverStatus::blocking() === [] ? self::SUCCESS : self::FAILURE;
    }

    private function isBlocking(string $problem): bool
    {
        return in_array($problem, SsoReceiverStatus::BLOCKING, true);
    }
}
