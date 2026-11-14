<?php

namespace App\View\Components;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\MinesConnection;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The state bar answers "does anything need me?" in words, on every page,
 * before any figure is read (docs/dot-central/11-operator-experience.md §2).
 * It is computed from the rooms the user can see, and it counts the SAME two
 * things the Overview headline and the navigation badges count: exceptions
 * that need attention plus open alerts (R-51: one truth for "what needs me").
 */
class ShellStateBar extends Component
{
    public string $level = 'idle';

    public string $state = 'SIGNED OUT';

    public string $detail = '';

    public ?string $detailUrl = null;

    public int $rooms = 0;

    public int $critical = 0;

    public int $warning = 0;

    /** Exceptions needing attention plus open alerts, across the rooms the user can see. */
    public int $open = 0;

    public ?string $title = null;

    public string $dataLink = 'Dot.Mines: not connected';

    public ?Shift $currentShift = null;

    public int $openExceptions = 0;

    public int $awaitingApproval = 0;

    public ?string $startUrl = null;

    public string $dataLinkLevel = 'idle';

    public function __construct(?string $title = null)
    {
        $this->title = $title;
        $user = Auth::user();

        if (! $user instanceof User || $user->currentTeam === null) {
            return;
        }

        $roomIds = ControlRoom::accessibleBy($user)->pluck('id');
        $this->rooms = $roomIds->count();
        $this->openExceptions = OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->where('status', 'OPEN')->count();
        $this->awaitingApproval = OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->awaitingDecision()->count();
        $this->currentShift = Shift::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->current()->with('shiftType')->orderByDesc('starts_at')->first();
        $firstRoom = $roomIds->first();
        $this->startUrl = $firstRoom !== null ? route('control-rooms.shifts.start', $firstRoom) : null;

        if ($this->rooms === 0) {
            $this->level = 'idle';
            $this->state = 'NO CONTROL ROOM';
            $this->detail = $user->can('create', ControlRoom::class)
                ? 'Create a control room to begin.'
                : 'You are not assigned to a control room yet.';
            $this->detailUrl = route('control-rooms.index');

            return;
        }

        $alerts = Alert::query()->whereIn('control_room_id', $roomIds)->whereNull('cleared_at');
        $exceptions = OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->needsAttention();
        $this->critical = (clone $alerts)->where('severity', 'critical')->count() + (clone $exceptions)->where('severity', 'CRITICAL')->count();
        $this->warning = (clone $alerts)->where('severity', 'warning')->count() + (clone $exceptions)->where('severity', 'WARNING')->count();
        $this->open = (clone $alerts)->count() + (clone $exceptions)->count();

        $connections = MinesConnection::query()->whereIn('control_room_id', $roomIds)->get();
        $this->describeDataLink($connections->count(), $connections->pluck('status'));

        if ($this->critical > 0) {
            $this->level = 'bad';
            $this->state = 'NEEDS ATTENTION';
            $this->detail = $this->critical.' critical, '.$this->open.' open, '.$this->rooms.' control room'.($this->rooms === 1 ? '' : 's').'.';
            $this->detailUrl = route('control-rooms.index');

            return;
        }

        if ($this->open > 0 || $this->dataLinkLevel === 'bad') {
            $this->level = 'warn';
            $this->state = 'WATCH';
            $this->detail = $this->open > 0
                ? '0 critical, '.$this->open.' open, '.$this->rooms.' control room'.($this->rooms === 1 ? '' : 's').'.'
                : 'A Dot.Mines credential has been rejected; synchronisation is paused.';
            $this->detailUrl = route('control-rooms.index');

            return;
        }

        $this->level = 'ok';
        $this->state = 'ALL QUIET';
        $this->detail = 'Nothing open across '.$this->rooms.' control room'.($this->rooms === 1 ? '' : 's').'.';
    }

    /**
     * @param  Collection<int, string>  $statuses
     */
    private function describeDataLink(int $count, $statuses): void
    {
        if ($count === 0) {
            $this->dataLink = 'Dot.Mines: not connected';
            $this->dataLinkLevel = 'idle';

            return;
        }

        if ($statuses->contains('credential_invalid')) {
            $this->dataLink = 'Dot.Mines: credential rejected';
            $this->dataLinkLevel = 'bad';

            return;
        }

        if ($statuses->every(fn (string $status) => $status === 'verified')) {
            $staleAfter = now()->subMinutes(3 * max(1, (int) config('mines.sync_interval_minutes', 5)));
            $stale = MinesConnection::query()->whereIn('control_room_id', ControlRoom::accessibleBy(Auth::user())->pluck('id'))
                ->where('status', 'verified')
                ->where(fn ($q) => $q->whereNull('last_success_at')->orWhere('last_success_at', '<', $staleAfter))
                ->exists();

            $this->dataLink = 'Dot.Mines: '.$count.' link'.($count === 1 ? '' : 's').($stale ? ' stale' : ' verified');
            $this->dataLinkLevel = $stale ? 'warn' : 'ok';

            return;
        }

        $this->dataLink = 'Dot.Mines: '.$count.' link'.($count === 1 ? '' : 's').' unverified';
        $this->dataLinkLevel = 'warn';
    }

    public function render(): View
    {
        return view('components.shell-state-bar');
    }
}
