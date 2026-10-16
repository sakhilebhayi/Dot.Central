<?php

namespace App\Services\Rules;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\MinesConnection;
use App\Models\Shift;
use App\Services\Lineup\LineupService;
use App\Services\Mines\FreshnessEvaluator;
use App\Services\Shifts\ShiftDerivation;
use App\Support\EquipmentState;
use Illuminate\Support\Collection;

/**
 * The rule catalogue of 12-derived-operations-design.md §4, evaluated over
 * derived data only. Produces findings; the ledger turns them into
 * exceptions with upsert/resolve/reopen semantics.
 */
final class RuleEngine
{
    public const CODES = ['TEL-01', 'TEL-02', 'EQ-01', 'EQ-02', 'EQ-03', 'EQ-04', 'EQ-05', 'DT-01', 'AL-01', 'SH-01', 'LU-06', 'LU-07', 'LU-09', 'LU-10', 'LU-11', 'LU-13'];

    public function __construct(
        private readonly ShiftDerivation $derivation,
        private readonly FreshnessEvaluator $freshness,
        private readonly ExceptionLedger $ledger,
        private readonly LineupService $lineup,
    ) {}

    /**
     * Evaluate a room (and its current shift when given) and apply the
     * findings to the ledger. Returns the findings.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function evaluate(ControlRoom $room, ?Shift $shift = null): Collection
    {
        $findings = collect();
        $settings = $room->settings();
        $connection = $room->minesConnection;

        if ($connection !== null) {
            $findings = $findings->merge($this->telemetryRules($room, $connection, $shift, $settings->all()));
        }

        if ($shift !== null) {
            $findings = $findings->merge($this->shiftRules($room, $shift, $settings->all()));
            $findings = $findings->merge($this->lineupRules($shift));
        }

        $findings = $findings->merge($this->alertRules($room, $shift, $settings->all()));

        $this->ledger->apply($room, $findings, self::CODES, $shift);

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array<string, mixed>>
     */
    private function telemetryRules(ControlRoom $room, MinesConnection $connection, ?Shift $shift, array $s): array
    {
        $out = [];
        $cadence = max(1, $connection->provider_cadence_seconds);

        foreach (Equipment::withoutGlobalScopes()->where('control_room_id', $room->id)->whereNull('retired_at')->get() as $unit) {
            $e = $this->freshness->forEquipment($unit, $connection);
            $age = $e['age_seconds'];

            if ($e['state'] === EquipmentState::Unknown) {
                $out[] = $this->finding('TEL-01', 'Telemetry', 'CRITICAL', 'MISSING', $unit->name.' has no usable telemetry',
                    $age === null ? $unit->name.' has never reported to Dot.Mines.' : $unit->name.' last reported '.$this->age($age).' ago, beyond four times the provider cadence ('.$cadence.' s).',
                    'Check the machine and its tracker in Dot.Mines. If it is off site or in the workshop, explain this item.', $unit, $shift);
            } elseif ($e['state'] === EquipmentState::Stale && $age !== null && $age >= (int) $s['stale_minutes'] * 60) {
                $out[] = $this->finding('TEL-02', 'Telemetry', 'WARNING', 'SUSPICIOUS', $unit->name.' telemetry is stale',
                    'Last report '.$this->age($age).' ago; the last known state ('.$unit->mines_status.') is shown dimmed.',
                    'Nothing to enter. If the feed does not recover, see Integration health.', $unit, $shift);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array<string, mixed>>
     */
    private function shiftRules(ControlRoom $room, Shift $shift, array $s): array
    {
        $out = [];
        $units = $this->derivation->unitSummaries($shift);
        $haul = array_map('strtolower', (array) $s['haul_types']);

        foreach ($units as $u) {
            $unit = $u['equipment'];

            if ($u['run_hours'] !== null && $u['run_hours'] < 0) {
                $out[] = $this->finding('EQ-02', 'Equipment', 'CRITICAL', 'CONFLICT', $unit->name.' engine hours decreased',
                    'Opening '.$u['opening_hours'].' h, closing '.$u['closing_hours'].' h (difference '.$u['run_hours'].' h). Hour meters do not normally decrease.',
                    'Check the meter or its mapping in Dot.Mines. A supervisor or manager resolves which reading is authoritative.', $unit, $shift,
                    ['source_a' => 'reading '.$u['opening_at']?->format('H:i'), 'value_a' => $u['opening_hours'], 'source_b' => 'reading '.$u['closing_at']?->format('H:i'), 'value_b' => $u['closing_hours']]);
            }

            if (in_array(strtolower((string) $unit->machine_type), $haul, true) && $u['run_hours'] !== null && $u['run_hours'] >= (float) $s['zero_loads_hours'] && $u['loads'] === 0) {
                $out[] = $this->finding('EQ-03', 'Production', 'REVIEW', 'REQUIRES_EXPLANATION', $unit->name.' ran '.$u['run_hours'].' h with no loads',
                    'The cumulative load counter did not change while the engine ran '.$u['run_hours'].' h.',
                    'Explain what the truck was doing (assisting, standby, road work).', $unit, $shift);
            }

            if ($u['idle_hours'] !== null && (float) $s['idle_minutes_threshold'] <= $u['idle_hours'] * 60) {
                $out[] = $this->finding('EQ-04', 'Equipment', 'REVIEW', 'REQUIRES_EXPLANATION', $unit->name.' idled '.$u['idle_hours'].' h',
                    'Idle hours grew by '.$u['idle_hours'].' h during the shift, above the room threshold of '.round((float) $s['idle_minutes_threshold'] / 60, 1).' h.',
                    'Explain the idle time or have the supervisor review it.', $unit, $shift);
            }

            if ($shift->ends_at->lessThan(now()) && $u['run_hours'] !== null && $u['run_hours'] > 0 && $u['production_quantity'] === null && in_array(strtolower((string) $unit->machine_type), $haul, true)) {
                $out[] = $this->finding('EQ-05', 'Production', 'INFO', 'INFO', $unit->name.' has no production record for this shift',
                    'Dot.Mines has not published a production record for this date and shift although the engine ran '.$u['run_hours'].' h.',
                    'Nothing to enter; Dot.Mines publishes records after its backfill.', $unit, $shift);
            }
        }

        foreach ($this->derivation->stoppages($shift) as $stop) {
            $unit = $stop['equipment'];
            if ($stop['cause'] === 'offline' && $stop['minutes'] >= (int) $s['offline_minutes']) {
                $out[] = $this->finding('EQ-01', 'Equipment', 'WARNING', 'REQUIRES_EXPLANATION', $unit->name.' offline for '.$stop['minutes'].' min',
                    'Dot.Mines reported '.$unit->name.' offline from '.$stop['started_at']->format('H:i').($stop['open'] ? ' and it is still offline' : ' to '.$stop['ended_at']?->format('H:i')).'.',
                    'Classify the stoppage on the Downtime page; the item clears automatically.', $unit, $shift, [], 'EQ-01|'.$shift->id.'|'.$unit->id.'|'.$stop['started_at']->timestamp, $stop['classification'] !== null);
            }
            if ($stop['classification'] === null && $stop['minutes'] >= (int) $s['classify_after_minutes']) {
                $out[] = $this->finding('DT-01', 'Downtime', 'REVIEW', 'REQUIRES_EXPLANATION', $unit->name.' stoppage not yet classified',
                    $stop['minutes'].' min '.$stop['cause'].' from '.$stop['started_at']->format('H:i').' without a reason.',
                    'Pick a reason on the Downtime page.', $unit, $shift, [], 'DT-01|'.$shift->id.'|'.$unit->id.'|'.$stop['started_at']->timestamp);
            }
        }

        if ($shift->isCurrent() && $shift->ends_at->lessThan(now())) {
            $out[] = $this->finding('SH-01', 'Shift', 'WARNING', 'SUSPICIOUS', 'Shift has passed its end and is not closed',
                $shift->label().' ended at '.$shift->ends_at->format('H:i').' and is still '.$shift->status.'.',
                'Review and close the shift to issue the handover.', null, $shift, [], 'SH-01|'.$shift->id);
        }

        return array_values(array_filter($out, fn (array $f) => ! ($f['satisfied'] ?? false)));
    }

    /**
     * Line-up rules (13-shift-lineup-design.md §7): the live checks become
     * exceptions once the shift is under way, plus LU-11 (ran but never
     * placed, final review) and LU-13 (never confirmed).
     *
     * @return list<array<string, mixed>>
     */
    private function lineupRules(Shift $shift): array
    {
        $out = [];
        $faces = $this->lineup->faces($shift);
        $started = ! $this->lineup->isPlanning($shift);

        if ($faces->isEmpty() && ! $this->lineup->lineup($shift)->exists) {
            return [];
        }

        foreach ($this->lineup->checks($shift) as $check) {
            $unit = $check['equipment_id'] !== null ? Equipment::withoutGlobalScopes()->find($check['equipment_id']) : null;
            $out[] = $this->finding($check['code'], 'Line-up', $check['severity'], $check['outcome'], $check['title'], $check['message'], $check['fix'], $unit, $shift, ['face_id' => $check['face_id']],
                $check['code'].'|'.$shift->id.'|'.($check['equipment_id'] ?? 'f'.$check['face_id']));
        }

        if ($started && $faces->isNotEmpty() && ! $this->lineup->isConfirmed($shift)) {
            $out[] = $this->finding('LU-13', 'Line-up', 'REVIEW', 'REQUIRES_EXPLANATION', 'Line-up not confirmed',
                'The line-up of '.$shift->label().' has faces but was never confirmed; the supervisor cannot see it was checked.',
                'Confirm the line-up on the Shift Line-up page.', null, $shift, [], 'LU-13|'.$shift->id);
        }

        if ($shift->ends_at->lessThan(now())) {
            $allocated = LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->pluck('equipment_id')->unique()->all();
            foreach ($this->derivation->unitSummaries($shift) as $u) {
                if ($u['run_hours'] !== null && $u['run_hours'] > 0 && ! in_array($u['equipment_id'], $allocated, true)) {
                    $out[] = $this->finding('LU-11', 'Line-up', 'REVIEW', 'SUSPICIOUS', $u['equipment']->name.' ran '.$u['run_hours'].' h but was never on the line-up',
                        'Engine hours grew during the shift although the machine was never allocated to a face or work area.',
                        'Explain where it worked, or correct the line-up before the shift is approved.', $u['equipment'], $shift);
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array<string, mixed>>
     */
    private function alertRules(ControlRoom $room, ?Shift $shift, array $s): array
    {
        $out = [];
        $cutoff = now()->subMinutes((int) $s['unack_minutes']);
        $alerts = Alert::withoutGlobalScopes()->where('control_room_id', $room->id)->where('severity', 'critical')->whereNull('cleared_at')->whereNull('acknowledged_at')->where('triggered_at', '<=', $cutoff)->get();

        foreach ($alerts as $alert) {
            $out[] = $this->finding('AL-01', 'Alerts', 'WARNING', 'SUSPICIOUS', 'Critical alert unacknowledged: '.$alert->title,
                'Raised '.$alert->triggered_at->diffForHumans().' and nobody has acknowledged it.',
                'Acknowledge the alert and assign it, or resolve it if it no longer applies.', null, $shift, ['alert_id' => $alert->id], 'AL-01|'.$alert->id);
        }

        return $out;
    }

    private function age(int $seconds): string
    {
        return $seconds < 3600 ? intdiv($seconds, 60).' min' : round($seconds / 3600, 1).' h';
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function finding(string $code, string $group, string $severity, string $outcome, string $title, string $message, string $fix, ?Equipment $unit, ?Shift $shift, array $detail = [], ?string $key = null, bool $satisfied = false): array
    {
        return [
            'key' => $key ?? ($code.'|'.($shift !== null ? $shift->id : 'room').'|'.($unit !== null ? $unit->id : '-')),
            'rule_code' => $code, 'group' => $group, 'severity' => $severity, 'outcome' => $outcome,
            'title' => $title, 'message' => $message, 'fix' => $fix,
            'equipment_id' => $unit?->id, 'shift_id' => $shift?->id, 'detail' => $detail, 'satisfied' => $satisfied,
        ];
    }
}
