<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Notifications\AlertRaisedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ScanStaleSessions extends Command
{
    protected $signature = 'central:scan-stale-sessions';

    protected $description = 'Raise an alert when an active operator session has logged no dispatch activity for a while, and open a review proposal if the silence continues -- never ends a session on its own.';

    private const ALERT_THRESHOLD_HOURS = 4;

    private const PROPOSAL_THRESHOLD_HOURS = 8;

    private const ALERT_TITLE = 'No dispatch activity detected';

    public function handle(): int
    {
        $activeSessions = OperatorSession::whereNull('ended_at')->with('controlRoom')->get();
        $processed = 0;

        foreach ($activeSessions as $session) {
            try {
                $this->evaluate($session);
                $processed++;
            } catch (\Throwable $e) {
                $this->error("Failed to evaluate operator session #{$session->id}: {$e->getMessage()}");
            }
        }

        $this->info("Evaluated {$processed} active operator session(s).");

        return self::SUCCESS;
    }

    private function evaluate(OperatorSession $session): void
    {
        $controlRoom = $session->controlRoom;

        $lastDecidedAt = $controlRoom->dispatchDecisions()->max('decided_at');
        $sinceActivity = $lastDecidedAt ? max($session->started_at, $lastDecidedAt) : $session->started_at;
        // Carbon 3's diffInHours() returns a signed difference by default
        // (unlike Carbon 2, which was always absolute) -- $sinceActivity is
        // always in the past here, so pass absolute: true explicitly rather
        // than relying on either version's default.
        $hoursSilent = now()->diffInHours($sinceActivity, absolute: true);

        if ($hoursSilent < self::ALERT_THRESHOLD_HOURS) {
            return;
        }

        $alreadyAlerted = Alert::where('control_room_id', $controlRoom->id)
            ->where('title', self::ALERT_TITLE)
            ->whereNull('cleared_at')
            ->exists();

        if (! $alreadyAlerted) {
            $alert = $controlRoom->alerts()->create([
                'severity' => 'warning',
                'title' => self::ALERT_TITLE,
                'description' => "\"{$session->shift_label}\" shift has logged no dispatch decisions in over ".self::ALERT_THRESHOLD_HOURS.' hours.',
                'triggered_at' => now(),
            ]);

            Notification::send($controlRoom->team->allUsers(), new AlertRaisedNotification($alert));
        }

        if ($hoursSilent < self::PROPOSAL_THRESHOLD_HOURS) {
            return;
        }

        $alreadyProposed = StaleSessionProposal::where('operator_session_id', $session->id)
            ->where('status', 'pending')
            ->exists();

        if (! $alreadyProposed) {
            StaleSessionProposal::create([
                'operator_session_id' => $session->id,
                'control_room_id' => $controlRoom->id,
                'hours_silent' => $hoursSilent,
                'status' => 'pending',
            ]);
        }
    }
}
