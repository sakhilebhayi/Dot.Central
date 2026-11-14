<?php

namespace App\Support;

use App\Models\ControlRoom;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * What the Operational Overview says to the person who has just arrived:
 * a welcome from their own record, the state of the room in words, and the
 * ONE thing to do next. Everything is read from the state the Overview has
 * already loaded; nothing here writes, and no figure is invented.
 *
 * The next step follows the shift (docs/dot-central/11-operator-experience.md
 * §2: "the shift is the spine"): no shift → start one; a shift without a
 * confirmed line-up → build it; a shift past its window → end it and hand
 * over; otherwise → back to the running shift. People who cannot run a shift
 * (managers, administrators) get the next thing they can actually open.
 */
final class OverviewArrival
{
    /**
     * @param  array{exceptions: int, critical: int, alerts: int}  $needs
     * @return array{welcome: string, place: string, state: string, level: string, action: array{key: string, label: string, url: string, why: string}}
     */
    public static function for(User $user, ?ControlRoom $room, ?Shift $shift, bool $lineupConfirmed, bool $connected, array $needs, string $timezone = 'UTC'): array
    {
        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;
        $first = (string) Str::of((string) $user->name)->trim()->explode(' ')->first();

        if ($room === null) {
            return [
                'welcome' => $first !== '' ? 'Welcome, '.$first.'.' : 'Welcome.',
                'place' => $team !== null ? 'You are signed in to '.$team->name.'.' : 'You are not in a team yet.',
                'state' => $user->can('create', ControlRoom::class)
                    ? 'No control room exists for this team yet. Create one and its shifts, fleet and exceptions appear here.'
                    : 'You are not assigned to a control room yet. An administrator of your team adds you to one; then its shifts, fleet and exceptions appear here.',
                'level' => 'idle',
                'action' => self::withoutRoom($user, $team),
            ];
        }

        $isCurrent = $shift?->isCurrent() ?? false;

        return [
            'welcome' => $first !== '' ? 'Welcome, '.$first.'.' : 'Welcome.',
            'place' => 'You are in '.$room->name.($team !== null ? ', '.$team->name : '').'.',
            'state' => self::shiftSentence($shift, $isCurrent).' '.self::needsSentence($needs),
            'level' => $needs['critical'] > 0 ? 'bad' : ($needs['exceptions'] + $needs['alerts'] > 0 ? 'warn' : 'ok'),
            'action' => self::withRoom($user, $room, $shift, $isCurrent, $lineupConfirmed, $connected, $timezone),
        ];
    }

    private static function shiftSentence(?Shift $shift, bool $isCurrent): string
    {
        if ($shift === null) {
            return 'No shift has been started in this room yet.';
        }

        if (! $isCurrent) {
            return 'No shift is open. The last one, '.$shift->label().', is '.strtolower($shift->status).'.';
        }

        return $shift->label().' is '.match ($shift->status) {
            'IN_REVIEW' => 'in review',
            'REOPENED' => 'reopened',
            default => 'open',
        }.'.';
    }

    /**
     * @param  array{exceptions: int, critical: int, alerts: int}  $needs
     */
    private static function needsSentence(array $needs): string
    {
        $parts = [];
        if ($needs['exceptions'] > 0) {
            $parts[] = $needs['exceptions'].' '.Str::plural('exception', $needs['exceptions']);
        }
        if ($needs['alerts'] > 0) {
            $parts[] = $needs['alerts'].' open '.Str::plural('alert', $needs['alerts']);
        }

        if ($parts === []) {
            return 'Nothing needs you right now.';
        }

        $sentence = implode(' and ', $parts).($needs['exceptions'] + $needs['alerts'] === 1 ? ' needs' : ' need').' attention';

        if ($needs['critical'] > 0) {
            return $sentence.'; '.$needs['critical'].' critical '.Str::plural('exception', $needs['critical']).($needs['critical'] === 1 ? ' blocks' : ' block').' the shift close.';
        }

        return $sentence.'.';
    }

    /**
     * @return array{key: string, label: string, url: string, why: string}
     */
    private static function withoutRoom(User $user, ?Team $team): array
    {
        if ($user->can('create', ControlRoom::class)) {
            return ['key' => 'create-room', 'label' => 'Create a control room', 'url' => route('control-rooms.create'), 'why' => 'A control room is one site: its machines, shifts and people.'];
        }

        if ($team !== null) {
            return ['key' => 'team', 'label' => 'See who runs '.$team->name, 'url' => route('teams.show', $team), 'why' => 'The team page lists the administrators who can add you to a control room.'];
        }

        return ['key' => 'profile', 'label' => 'Open your profile', 'url' => route('profile.show'), 'why' => 'Your account has no team yet.'];
    }

    /**
     * @return array{key: string, label: string, url: string, why: string}
     */
    private static function withRoom(User $user, ControlRoom $room, ?Shift $shift, bool $isCurrent, bool $lineupConfirmed, bool $connected, string $timezone): array
    {
        if ($shift !== null && $isCurrent) {
            $operates = $user->can('lineup', $shift);

            if ($operates && ($shift->status === 'IN_REVIEW' || $shift->ends_at->isPast())) {
                return ['key' => 'end-shift', 'label' => 'End shift and hand over', 'url' => route('shifts.review', $shift), 'why' => 'The shift window ended at '.$shift->ends_at->copy()->timezone($timezone)->format('H:i').'. Review it, close it and issue the handover.'];
            }

            if ($operates && ! $lineupConfirmed) {
                return ['key' => 'lineup', 'label' => 'Build the line-up', 'url' => route('shifts.lineup', $shift), 'why' => 'The shift is open and its line-up is not confirmed yet.'];
            }

            return ['key' => 'shift', 'label' => 'Return to the shift', 'url' => route('shifts.show', $shift), 'why' => $operates ? 'The line-up is confirmed. Shift operations has hours, production and downtime.' : 'Shift operations shows hours, production and downtime as they are recorded.'];
        }

        if ($user->can('open', [Shift::class, $room])) {
            return ['key' => 'start-shift', 'label' => 'Start shift', 'url' => route('control-rooms.shifts.start', $room), 'why' => $shift === null ? 'The first shift of this room. The proposal and the expected machines are ready to confirm.' : 'The next shift is proposed with the last handover and what is still down.'];
        }

        if (! $connected && $user->can('manage', $room)) {
            return ['key' => 'connect', 'label' => 'Connect Dot.Mines', 'url' => route('control-rooms.connection.edit', $room), 'why' => 'Without the Dot.Mines link this room has no machines and no readings.'];
        }

        if ($shift !== null) {
            return ['key' => 'handover', 'label' => 'Read the last handover', 'url' => route('shifts.review', $shift), 'why' => 'An operator or supervisor starts the next shift. This view is read-only.'];
        }

        return ['key' => 'fleet', 'label' => 'Open the fleet board', 'url' => route('control-rooms.fleet', $room), 'why' => 'An operator or supervisor starts the first shift. The fleet board shows every machine as it reports.'];
    }
}
