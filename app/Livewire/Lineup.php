<?php

namespace App\Livewire;

use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\Operator;
use App\Models\Shift;
use App\Services\Lineup\LineupService;
use App\Services\Lineup\LocationCatalogue;
use App\Services\Rules\RuleEngine;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Shift Line-up: at shift start the operator places every machine, gives
 * every placed machine its operator and every free machine a reason, then
 * confirms. Refusals show the code, the reason and a one-click fix.
 */
#[Layout('layouts.app')]
class Lineup extends Component
{
    public Shift $shift;

    public ?int $target = null;

    public string $changeAt = '';

    public string $poolFilter = 'ALL';

    public ?string $refusal = null;

    /** @var array<string, mixed> */
    public array $fix = [];

    public ?string $notice = null;

    public bool $faceFormOpen = false;

    public ?int $editingFaceId = null;

    /** @var array<string, mixed> */
    public array $faceForm = [];

    /** @var array<int, string> */
    public array $releaseReason = [];

    /** @var array<int, string> */
    public array $standbyPick = [];

    /** @var array<int, string> */
    public array $closeReason = [];

    public function mount(Shift $shift): void
    {
        $this->authorize('view', $shift);
        $this->shift = $shift;
        $this->resetFaceForm();
    }

    public function editable(): bool
    {
        return $this->shift->isCurrent() && auth()->user()?->can('lineup', $this->shift) === true;
    }

    // ---- faces -------------------------------------------------------------------------------------------

    public function openFaceForm(?int $faceId = null): void
    {
        $this->clearMessages();
        $this->resetFaceForm();
        $this->editingFaceId = $faceId;
        if ($faceId !== null) {
            $face = LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail($faceId);
            $loaders = $face->allocations()->whereNull('to_at')->where('role', 'LOADER')->pluck('equipment_id')->all();
            $this->faceForm = ['kind' => $face->kind, 'location' => $face->location_source !== null ? $face->location_source.':'.($face->location_ref ?? $face->location_name) : '', 'material' => (string) $face->material, 'activity' => (string) $face->activity, 'cut' => (string) $face->cut, 'blocks' => implode(' ', $face->blocks ?? []), 'notes' => (string) $face->notes, 'loader_ids' => array_map('strval', $loaders)];
        }
        $this->faceFormOpen = true;
    }

    public function cancelFaceForm(): void
    {
        $this->faceFormOpen = false;
        $this->editingFaceId = null;
        $this->resetFaceForm();
    }

    public function saveFace(): void
    {
        $this->run(function (LineupService $service): void {
            $attrs = [
                'kind' => $this->faceForm['kind'], 'location' => (string) ($this->faceForm['location'] ?? ''),
                'material' => $this->faceForm['material'] ?: null, 'activity' => $this->faceForm['activity'] ?: null, 'cut' => $this->faceForm['cut'] ?: null,
                'blocks' => preg_split('/[\s,]+/', trim((string) $this->faceForm['blocks'])) ?: [], 'notes' => $this->faceForm['notes'] ?: null,
            ];
            if ($this->editingFaceId !== null) {
                $face = LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail($this->editingFaceId);
                $service->editFace($face, $attrs, auth()->user());
                foreach (array_map('intval', $this->faceForm['loader_ids']) as $id) {
                    $service->place($this->shift, $face, Equipment::withoutGlobalScopes()->findOrFail($id), auth()->user(), $this->changeAt ?: null, true);
                }
                $this->notice = 'Saved '.$face->fresh()->label().'.';
            } else {
                $face = $service->addFace($this->shift, $attrs + ['loader_ids' => array_map('intval', $this->faceForm['loader_ids'])], auth()->user(), $this->changeAt ?: null);
                $this->notice = 'Added '.$face->label().'.';
            }
            $this->target = $face->id;
            $this->cancelFaceForm();
        });
    }

    public function closeFace(int $faceId): void
    {
        $this->run(function (LineupService $service) use ($faceId): void {
            $face = LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail($faceId);
            $service->closeFace($face, $this->closeReason[$faceId] ?? '', auth()->user(), $this->changeAt ?: null);
            $this->notice = 'Closed '.$face->label().'.';
            unset($this->closeReason[$faceId]);
        });
    }

    public function setTarget(int $faceId): void
    {
        $this->target = $faceId;
    }

    // ---- placements --------------------------------------------------------------------------------------

    public function place(int $unitId, bool $move = false, ?int $faceId = null): void
    {
        $this->run(function (LineupService $service) use ($unitId, $move, $faceId): void {
            $faceId ??= $this->target;
            if ($faceId === null) {
                $this->refusal = 'Add a face first, then click machines to put them on it.';

                return;
            }
            $face = LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail($faceId);
            $unit = Equipment::withoutGlobalScopes()->where('control_room_id', $this->shift->control_room_id)->findOrFail($unitId);
            try {
                $allocation = $service->place($this->shift, $face, $unit, auth()->user(), $this->changeAt ?: null, $move);
            } catch (ValidationException $e) {
                $message = $e->errors()['lineup'][0] ?? 'Refused.';
                if (str_starts_with($message, 'LU-01')) {
                    $this->fix = ['move' => $unitId, 'face' => $faceId];
                }
                throw $e;
            }
            $this->target = $face->id;
            $this->notice = $unit->name.' → '.$face->label().($allocation->from_at->equalTo($this->shift->starts_at) ? '' : ' at '.$allocation->from_at->setTimezone($this->shift->controlRoom->timezone())->format('H:i')).'.';
        });
    }

    public function release(int $allocationId): void
    {
        $this->run(function (LineupService $service) use ($allocationId): void {
            $allocation = LineupAllocation::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail($allocationId);
            $service->release($allocation, $this->releaseReason[$allocationId] ?? null, auth()->user(), $this->changeAt ?: null);
            $this->notice = $allocation->equipment?->name.' taken off the face.';
            unset($this->releaseReason[$allocationId]);
        });
    }

    public function setStandby(int $unitId): void
    {
        $this->run(function (LineupService $service) use ($unitId): void {
            $reason = DowntimeReason::where('control_room_id', $this->shift->control_room_id)->findOrFail((int) ($this->standbyPick[$unitId] ?? 0));
            $unit = Equipment::withoutGlobalScopes()->where('control_room_id', $this->shift->control_room_id)->findOrFail($unitId);
            $service->setStandby($this->shift, $unit, $reason, auth()->user(), $this->changeAt ?: null);
            $this->notice = $unit->name.': '.$reason->name.'.';
        });
    }

    public function endStandby(int $standbyId): void
    {
        $this->run(function (LineupService $service) use ($standbyId): void {
            $standby = LineupStandby::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail($standbyId);
            $service->endStandby($standby, auth()->user(), $this->changeAt ?: null);
            $this->notice = 'Standby ended.';
        });
    }

    // ---- operators ---------------------------------------------------------------------------------------

    public function assignOperator(int $unitId, string $operatorId, bool $move = false): void
    {
        $this->run(function (LineupService $service) use ($unitId, $operatorId, $move): void {
            $unit = Equipment::withoutGlobalScopes()->where('control_room_id', $this->shift->control_room_id)->findOrFail($unitId);
            if ($operatorId === '') {
                $service->clearOperator($this->shift, $unit, auth()->user());
                $this->notice = $unit->name.': operator cleared.';

                return;
            }
            $operator = Operator::withoutGlobalScopes()->where('control_room_id', $this->shift->control_room_id)->findOrFail((int) $operatorId);
            try {
                $assignment = $service->assignOperator($this->shift, $unit, $operator, auth()->user(), $move);
            } catch (ValidationException $e) {
                $message = $e->errors()['lineup'][0] ?? '';
                if (str_contains($message, 'Move operator')) {
                    $this->fix = ['operator' => $operatorId, 'unit' => $unitId];
                }
                throw $e;
            }
            $this->notice = $operator->name().' operates '.$unit->name.'.'.($assignment->push_note !== null ? ' ('.$assignment->push_note.')' : '');
        });
    }

    // ---- shift-level actions ------------------------------------------------------------------------------

    public function copyFromPrevious(): void
    {
        $this->run(function (LineupService $service): void {
            $result = $service->copyFromPrevious($this->shift, auth()->user());
            $this->notice = 'Line-up copied: '.$result['faces'].' face(s), '.$result['placed'].' machine(s) placed, '.$result['operators'].' operator(s) filled.'.($result['skipped'] !== [] ? ' Not copied: '.implode('; ', $result['skipped']) : '');
        });
    }

    public function fillOperators(): void
    {
        $this->run(function (LineupService $service): void {
            $n = $service->fillOperators($this->shift, auth()->user());
            $this->notice = $n.' operator(s) filled from the previous shift. Machines down or on standby, and people already assigned, were left alone.';
        });
    }

    public function confirm(): void
    {
        $this->run(function (LineupService $service): void {
            $lineup = $service->confirm($this->shift, auth()->user());
            app(RuleEngine::class)->evaluate($this->shift->controlRoom, $this->shift);
            $this->notice = 'Line-up confirmed (version '.$lineup->version.'). Every later change is recorded with its time.';
        });
    }

    public function useNow(): void
    {
        $this->changeAt = '';
    }

    public function render(LineupService $service, LocationCatalogue $locations): View
    {
        $this->shift->refresh();
        $room = $this->shift->controlRoom;
        $pool = $service->pool($this->shift);
        $faces = $service->faces($this->shift);
        $closedFaces = $service->faces($this->shift, true)->where('status', 'CLOSED');
        $byFace = LineupAllocation::withoutGlobalScopes()->where('shift_id', $this->shift->id)->get()->groupBy('lineup_face_id');
        $poolByUnit = $pool->keyBy(fn (array $r) => $r['equipment']->id);

        if ($this->target === null || ! $faces->contains('id', $this->target)) {
            $this->target = $faces->first()?->id;
        }

        $filtered = $pool->filter(fn (array $p) => match ($this->poolFilter) {
            'FREE' => $p['state'] === 'FREE',
            'PLACED' => $p['state'] === 'PLACED',
            'OUT' => in_array($p['state'], ['DOWN', 'STANDBY'], true),
            default => true,
        });

        return view('livewire.lineup', [
            'room' => $room,
            'editable' => $this->editable(),
            'planning' => $service->isPlanning($this->shift),
            'lineup' => $service->lineup($this->shift),
            'faces' => $faces,
            'closedFaces' => $closedFaces,
            'byFace' => $byFace,
            'pool' => $pool,
            'poolByUnit' => $poolByUnit,
            'byClass' => $filtered->groupBy('role_class')->sortKeys(),
            'summary' => $service->summary($this->shift),
            'checks' => $service->checks($this->shift),
            'proposal' => $faces->isEmpty() && $this->editable() ? $service->proposal($this->shift) : null,
            'operators' => Operator::withoutGlobalScopes()->where('control_room_id', $room->id)->where('is_active', true)->orderBy('last_name')->orderBy('first_name')->get(),
            'standbyReasons' => DowntimeReason::where('control_room_id', $room->id)->where('is_active', true)->whereIn('category', LineupStandby::CATEGORIES)->orderBy('name')->get(),
            'locations' => $locations->options($room),
            'timezone' => $room->timezone(),
        ]);
    }

    private function run(callable $action): void
    {
        $this->clearMessages();
        if (! $this->editable()) {
            $this->refusal = 'SH-04 '.$this->shift->label().' is '.$this->shift->status.' or your role cannot change the line-up.';

            return;
        }
        try {
            $action(app(LineupService::class));
        } catch (ValidationException $e) {
            $this->refusal = $e->errors()['lineup'][0] ?? collect($e->errors())->flatten()->first();
        }
    }

    private function clearMessages(): void
    {
        $this->refusal = null;
        $this->fix = [];
        $this->notice = null;
    }

    private function resetFaceForm(): void
    {
        $this->faceForm = ['kind' => 'LOADING', 'location' => '', 'material' => '', 'activity' => '', 'cut' => '', 'blocks' => '', 'notes' => '', 'loader_ids' => []];
    }
}
