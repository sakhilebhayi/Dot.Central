<?php

namespace App\Http\Controllers;

use App\Models\DowntimeClassification;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\Shift;
use App\Services\Shifts\ShiftDerivation;
use App\Services\Shifts\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Engine Hours, Production and Downtime for a shift — derived views with
 * one action: classifying an observed stoppage.
 */
class ShiftPagesController extends Controller
{
    public function __construct(private readonly ShiftService $shifts, private readonly ShiftDerivation $derivation) {}

    public function hours(Request $request, Shift $shift): View
    {
        $this->authorize('view', $shift);
        $review = $this->shifts->review($shift);

        return view('shifts.hours', $this->base($shift, $review) + [
            'budget' => (float) $shift->controlRoom->settings()->get('engine_hours_budget', 10),
            'shiftHours' => round($shift->starts_at->diffInMinutes($shift->ends_at, true) / 60, 1),
        ]);
    }

    public function production(Request $request, Shift $shift): View
    {
        $this->authorize('view', $shift);
        $review = $this->shifts->review($shift);

        return view('shifts.production', $this->base($shift, $review));
    }

    public function downtime(Request $request, Shift $shift): View
    {
        $this->authorize('view', $shift);
        $review = $this->shifts->review($shift);

        return view('shifts.downtime', $this->base($shift, $review) + [
            'stoppages' => $this->derivation->stoppages($shift),
            'reasons' => $shift->controlRoom->downtimeReasons()->where('is_active', true)->get(),
            'canClassify' => $request->user()->can('create', [DowntimeClassification::class, $shift->controlRoom]),
        ]);
    }

    public function classify(Request $request, Shift $shift): RedirectResponse
    {
        $room = $shift->controlRoom;
        $this->authorize('create', [DowntimeClassification::class, $room]);

        $validated = $request->validate([
            'equipment_id' => ['required', Rule::exists('equipment', 'id')->where('control_room_id', $room->id)],
            'downtime_reason_id' => ['required', Rule::exists('downtime_reasons', 'id')->where('control_room_id', $room->id)],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after:started_at'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = DowntimeReason::findOrFail($validated['downtime_reason_id']);

        if ($reason->requires_description && trim((string) ($validated['note'] ?? '')) === '') {
            return back()->withErrors(['note' => $reason->name.' needs a description of what happened.'])->withInput();
        }

        $timezone = $room->timezone();

        DowntimeClassification::create([
            'control_room_id' => $room->id,
            'equipment_id' => $validated['equipment_id'],
            'shift_id' => $shift->id,
            'downtime_reason_id' => $reason->id,
            'started_at' => Carbon::parse($validated['started_at'], $timezone)->utc(),
            'ended_at' => isset($validated['ended_at']) ? Carbon::parse($validated['ended_at'], $timezone)->utc() : null,
            'note' => $validated['note'] ?? null,
            'classified_by' => $request->user()->id,
        ]);

        return redirect()->route('shifts.downtime', $shift)->with('status', Equipment::find($validated['equipment_id'])?->name.' stoppage classified as '.$reason->name.'.');
    }

    /**
     * @param  array{units: Collection<int, mixed>, totals: array<string, mixed>, blocking: Collection<int, mixed>}  $review
     * @return array<string, mixed>
     */
    private function base(Shift $shift, array $review): array
    {
        return [
            'shift' => $shift,
            'controlRoom' => $shift->controlRoom,
            'units' => $review['units'],
            'totals' => $review['totals'],
            'timezone' => $shift->controlRoom->timezone(),
        ];
    }
}
