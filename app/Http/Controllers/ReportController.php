<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\Shift;
use App\Services\Audits;
use App\Services\Lineup\LineupService;
use App\Services\Mines\FreshnessEvaluator;
use App\Services\Shifts\ShiftDerivation;
use App\Support\Csv;
use App\Support\EquipmentState;
use App\Support\RoleClass;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports are query definitions over persisted records and derivations;
 * none has its own storage. CSV export is gated and audited.
 */
class ReportController extends Controller
{
    public const REPORTS = ['shift' => 'Shift summary', 'hours' => 'Engine hour report', 'production' => 'Production report', 'downtime' => 'Downtime report', 'handover' => 'Shift handover', 'lineup' => 'Shift line-up', 'equipment' => 'Equipment report'];

    public function __construct(private readonly ShiftDerivation $derivation, private readonly FreshnessEvaluator $freshness, private readonly Audits $audits, private readonly LineupService $lineup) {}

    public function index(Request $request): View|StreamedResponse
    {
        $roomIds = ControlRoom::accessibleBy($request->user())->pluck('id');
        $filters = $request->validate(['r' => ['nullable', 'string'], 'shift' => ['nullable', 'integer'], 'room' => ['nullable', 'integer'], 'export' => ['nullable', 'in:csv']]);
        $report = array_key_exists($filters['r'] ?? '', self::REPORTS) ? $filters['r'] : 'shift';

        $shifts = Shift::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->with(['shiftType', 'controlRoom'])->orderByDesc('starts_at')->limit(60)->get();
        $shift = isset($filters['shift']) ? $shifts->firstWhere('id', (int) $filters['shift']) : $shifts->first();
        $room = isset($filters['room']) ? ControlRoom::accessibleBy($request->user())->find((int) $filters['room']) : ($shift->controlRoom ?? ControlRoom::accessibleBy($request->user())->first());
        $timezone = $room?->timezone() ?? 'UTC';

        [$columns, $rows, $title] = $this->build($report, $shift, $room);

        if (($filters['export'] ?? null) === 'csv') {
            $this->authorize('export-reports');
            $this->audits->record('generate', null, ['report' => ['old' => null, 'new' => $report.' ('.count($rows).' rows)']], $title, $room, entityType: 'report');

            return response()->streamDownload(function () use ($columns, $rows): void {
                $out = fopen('php://output', 'w');
                fputcsv($out, Csv::row(array_values($columns)));
                foreach ($rows as $row) {
                    fputcsv($out, Csv::row(array_map(fn ($k) => $row[$k] ?? '', array_keys($columns))));
                }
                fclose($out);
            }, $report.'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('reports.index', compact('report', 'shifts', 'shift', 'room', 'columns', 'rows', 'title', 'timezone') + ['reports' => self::REPORTS, 'generatedAt' => now()]);
    }

    /**
     * @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string}
     */
    private function build(string $report, ?Shift $shift, ?ControlRoom $room): array
    {
        if (in_array($report, ['shift', 'hours', 'production', 'downtime', 'handover', 'lineup'], true) && $shift === null) {
            return [[], [], 'No shift'];
        }

        return match ($report) {
            'hours' => $this->hours($shift),
            'production' => $this->production($shift),
            'downtime' => $this->downtime($shift),
            'handover' => $this->handover($shift),
            'lineup' => $this->lineupReport($shift),
            'equipment' => $this->equipment($room),
            default => $this->shiftSummary($shift),
        };
    }

    /** @return array{0: list<mixed>, 1: string} */
    private function units(Shift $shift): array
    {
        $units = $shift->isClosed() && $shift->unitSummaries()->exists()
            ? $shift->unitSummaries()->with('equipment')->get()->map(fn ($s) => ['equipment' => $s->equipment] + $s->toArray())
            : $this->derivation->unitSummaries($shift);

        return [$units->values()->all(), $shift->label()];
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string} */
    private function hours(Shift $shift): array
    {
        [$units, $label] = $this->units($shift);
        $rows = array_map(fn (array $u) => ['unit' => $u['equipment']->name, 'type' => $u['equipment']->machine_type, 'state' => EquipmentState::from($u['state'])->label(), 'opening' => $u['opening_hours'], 'closing' => $u['closing_hours'], 'run' => $u['run_hours'], 'idle' => $u['idle_hours'], 'loads' => $u['loads'], 'stoppage_min' => $u['stoppage_minutes']], $units);

        return [['unit' => 'Unit', 'type' => 'Type', 'state' => 'State at end', 'opening' => 'Opening h', 'closing' => 'Closing h', 'run' => 'Run h', 'idle' => 'Idle h', 'loads' => 'Loads', 'stoppage_min' => 'Stoppage min'], $rows, 'Engine hour report · '.$label];
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string} */
    private function production(Shift $shift): array
    {
        [$units, $label] = $this->units($shift);
        $rows = array_values(array_map(fn (array $u) => ['unit' => $u['equipment']->name, 'type' => $u['equipment']->machine_type, 'loads' => $u['loads'], 'quantity' => $u['production_quantity'], 'unit_of_measure' => $u['production_unit'], 'run' => $u['run_hours']], array_filter($units, fn (array $u) => $u['loads'] !== null || $u['production_quantity'] !== null)));

        return [['unit' => 'Unit', 'type' => 'Type', 'loads' => 'Loads', 'quantity' => 'Production', 'unit_of_measure' => 'Unit', 'run' => 'Run h'], $rows, 'Production report · '.$label];
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string} */
    private function downtime(Shift $shift): array
    {
        $rows = $this->derivation->stoppages($shift)->map(fn ($s) => ['unit' => $s['equipment']->name, 'cause' => $s['cause'], 'stopped' => $s['started_at']->toDateTimeString(), 'resumed' => $s['ended_at']?->toDateTimeString() ?? 'still stopped', 'minutes' => $s['minutes'], 'reason' => $s['classification']?->reason?->name, 'note' => $s['classification']?->note])->values()->all();

        return [['unit' => 'Unit', 'cause' => 'Observed', 'stopped' => 'Stopped (UTC)', 'resumed' => 'Resumed (UTC)', 'minutes' => 'Minutes', 'reason' => 'Reason', 'note' => 'Note'], $rows, 'Downtime report · '.$shift->label()];
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string} */
    private function handover(Shift $shift): array
    {
        $snapshot = $shift->handover->snapshot ?? [];
        $fleet = $snapshot['fleet'] ?? $this->derivation->totals($this->derivation->unitSummaries($shift));
        $rows = [];
        foreach (['units' => 'Machines', 'with_reading' => 'With readings', 'run_hours' => 'Run hours', 'idle_hours' => 'Idle hours', 'loads' => 'Loads', 'production_quantity' => 'Production', 'stoppage_minutes' => 'Stoppage minutes'] as $k => $label) {
            $rows[] = ['item' => $label, 'value' => $fleet[$k] ?? null];
        }
        $rows[] = ['item' => 'Stopped at end', 'value' => implode(', ', array_map(fn ($s) => $s['name'].' ('.$s['state'].')', $fleet['stopped_now'] ?? []))];
        $rows[] = ['item' => 'Without telemetry at end', 'value' => implode(', ', $fleet['unknown_now'] ?? [])];
        $rows[] = ['item' => 'Notes', 'value' => $shift->handover->notes ?? $shift->notes];
        $rows[] = ['item' => 'Issued', 'value' => $shift->handover?->issued_at?->toDateTimeString() ?? 'not yet'];
        $rows[] = ['item' => 'Acknowledged', 'value' => $shift->handover?->acknowledged_at?->toDateTimeString() ?? 'not yet'];

        return [['item' => 'Item', 'value' => 'Value'], $rows, 'Shift handover · '.$shift->label()];
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string} */
    private function lineupReport(Shift $shift): array
    {
        $pool = $this->lineup->pool($shift)->keyBy(fn (array $r) => $r['equipment']->id);
        $tz = $shift->controlRoom->timezone();
        $corrections = LineupCorrection::withoutGlobalScopes()->where('shift_id', $shift->id)->orderBy('id')->get();
        $noteFor = function (LineupAllocation $a) use ($corrections, $tz): ?string {
            $own = $corrections->where('lineup_allocation_id', $a->id)->map(fn (LineupCorrection $c) => ($c->edge === LineupCorrection::ARRIVAL ? 'arrival' : 'departure').' corrected from '.$c->old_at->copy()->setTimezone($tz)->format('H:i').' ('.$tz.'): '.$c->reason);
            $moved = $corrections->where('partner_allocation_id', $a->id)->map(fn (LineupCorrection $c) => 'moved with a correction ('.$c->reason.')');
            $notes = collect([$a->ended_reason])->merge($own)->merge($moved)->filter();

            return $notes->isEmpty() ? null : $notes->implode('; ');
        };
        $rows = [];
        foreach ($this->lineup->faces($shift, true) as $face) {
            $link = $this->lineup->linkedLabel($face);
            foreach ($face->allocations()->orderBy('from_at')->get() as $a) {
                $p = $pool[$a->equipment_id] ?? null;
                $rows[] = ['face' => $face->label().($link !== '' ? ' · same loading unit as '.$link : '').($face->isClosed() ? ' (closed)' : ''), 'role' => LineupAllocation::ROLE_LABELS[$a->role] ?? $a->role, 'unit' => $p['equipment']->name ?? '#'.$a->equipment_id, 'operator' => $p['operator']?->label(), 'from' => $a->from_at->copy()->setTimezone($tz)->format('Y-m-d H:i'), 'until' => $a->to_at?->copy()->setTimezone($tz)->format('Y-m-d H:i') ?? 'still there', 'note' => $noteFor($a)];
            }
        }
        foreach ($pool->whereIn('state', ['FREE', 'STANDBY', 'DOWN']) as $p) {
            $rows[] = ['face' => '— not on a face —', 'role' => RoleClass::label($p['role_class']), 'unit' => $p['equipment']->name, 'operator' => $p['operator']?->label(), 'from' => null, 'until' => null, 'note' => match ($p['state']) {
                'DOWN' => 'down: '.($p['down']['cause'] ?? ''), 'STANDBY' => $p['standby']?->reason?->name, default => 'no place and no reason'
            }];
        }

        return [['face' => 'Face', 'role' => 'Role', 'unit' => 'Machine', 'operator' => 'Operator', 'from' => 'From ('.$tz.')', 'until' => 'Until ('.$tz.')', 'note' => 'Note'], $rows, 'Shift line-up · '.$shift->label().' · '.$this->lineup->lineup($shift)->status];
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string} */
    private function equipment(?ControlRoom $room): array
    {
        if ($room === null) {
            return [[], [], 'No room'];
        }
        $connection = $room->minesConnection;
        $rows = $room->equipment()->orderBy('name')->get()->map(function ($e) use ($connection) {
            $state = $connection === null ? EquipmentState::Unknown : $this->freshness->forEquipment($e, $connection)['state'];

            return ['unit' => $e->name, 'type' => $e->machine_type, 'model' => $e->model, 'serial' => $e->serial_number, 'status' => $state->label(), 'hours' => $e->engine_hours, 'hours_at' => $e->engine_hours_recorded_at?->toDateTimeString(), 'last_heard' => $e->last_heard_at?->toDateTimeString(), 'area' => $e->mine_area_name, 'retired' => $e->retired_at?->toDateString()];
        })->all();

        return [['unit' => 'Unit', 'type' => 'Type', 'model' => 'Model', 'serial' => 'Serial', 'status' => 'State', 'hours' => 'Engine h', 'hours_at' => 'Hours as of (UTC)', 'last_heard' => 'Last heard (UTC)', 'area' => 'Area', 'retired' => 'Retired'], $rows, 'Equipment report · '.$room->name];
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: string} */
    private function shiftSummary(Shift $shift): array
    {
        [$units, $label] = $this->units($shift);
        $totals = $this->derivation->totals(collect($units)->values());
        $rows = [];
        foreach (['units' => 'Machines', 'with_reading' => 'With readings', 'run_hours' => 'Run hours', 'idle_hours' => 'Idle hours', 'loads' => 'Loads', 'production_quantity' => 'Production', 'stoppage_minutes' => 'Stoppage minutes'] as $k => $name) {
            $rows[] = ['item' => $name, 'value' => $totals[$k] ?? null];
        }
        foreach ($totals['by_state'] ?? [] as $state => $n) {
            $rows[] = ['item' => 'State at end: '.$state, 'value' => $n];
        }

        return [['item' => 'Item', 'value' => 'Value'], $rows, 'Shift summary · '.$label];
    }
}
