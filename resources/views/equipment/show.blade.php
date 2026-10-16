@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · {{ $equipment->name }}</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <a href="{{ route('control-rooms.equipment.index', $controlRoom) }}" class="dot-panel__action">← Equipment</a>
                <h1 class="dot-page-head__title" style="margin-top:0.35rem;">{{ $equipment->name }} <span style="color:var(--text-faint);font-weight:500;">· {{ $equipment->model ?? ($equipment->machine_type ? str_replace('_', ' ', $equipment->machine_type) : '—') }}</span></h1>
                <p class="dot-page-head__lede">{{ $evaluation['state']->description() }}</p>
            </div>
            @php($tone = $evaluation['state']->tone())
            <span class="dot-badge dot-badge--{{ $tone === 'neutral' ? 'info' : $tone }}" style="font-size:var(--t-sm);padding:0.4rem 0.7rem;">{{ $evaluation['state']->label() }}</span>
        </div>

        <div class="dot-panels dot-panels--4" style="margin-bottom:1.25rem;">
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Engine hours</span><span class="dot-readout__value">{{ Format::number($equipment->engine_hours) }}</span><span class="dot-readout__context">as of {{ Format::stamp($equipment->engine_hours_recorded_at, $timezone) }} · source Dot.Mines metrics</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Last heard</span><span class="dot-readout__value" style="font-size:1.2rem;">{{ Format::age($equipment->last_heard_at) }}</span><span class="dot-readout__context">provider clock {{ Format::stamp($equipment->last_heard_at, $timezone) }} · Dot.Mines ingest {{ Format::stamp($equipment->last_seen_at, $timezone) }}</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Fuel</span><span class="dot-readout__value">{{ $equipment->fuel_level === null ? '—' : Format::number($equipment->fuel_level, 0) }}<span class="dot-readout__unit">{{ $equipment->fuel_level_unit === 'percent' ? '%' : '' }}</span></span><span class="dot-readout__context">capacity {{ Format::number($equipment->fuel_capacity, 0) }} l</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Position</span><span class="dot-readout__value" style="font-size:1rem;">{{ $equipment->latitude === null ? '—' : Format::number($equipment->latitude, 5).', '.Format::number($equipment->longitude, 5) }}</span><span class="dot-readout__context">recorded {{ Format::stamp($equipment->location_recorded_at, $timezone) }} · {{ $equipment->mine_area_name ?? 'no area' }}</span></div>
        </div>

        <div class="dot-panels dot-panels--split">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Engine-hour readings</h2><span class="dot-panel__action" style="color:var(--text-faint);">{{ $readings->count() }} kept</span></div>
                <div class="dot-table-wrap">
                    <table class="dot-ledger">
                        <thead><tr><th>Recorded (provider)</th><th class="num">Hours</th><th class="num">Idle h</th><th class="num">Loads</th><th>Engine</th><th>Source</th></tr></thead>
                        <tbody>
                        @forelse($readings as $reading)
                            <tr><td class="dot-ledger__key">{{ Format::stamp($reading->recorded_at, $timezone) }}</td><td class="num">{{ Format::number($reading->operating_hours) }}</td><td class="num">{{ Format::number($reading->idle_hours) }}</td><td class="num">{{ Format::integer($reading->load_count) }}</td><td>{{ $reading->engine_running === null ? '—' : ($reading->engine_running ? 'running' : 'off') }}</td><td>dot_mines.metrics</td></tr>
                        @empty
                            <tr><td colspan="4" class="dot-ledger__empty">No readings fetched yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Master record</h2><span class="dot-panel__action" style="color:var(--text-faint);">Dot.Mines #{{ $equipment->mines_machine_id }}</span></div>
                <div class="dot-panel__body">
                    <dl style="display:grid;grid-template-columns:max-content 1fr;gap:0.35rem 1rem;margin:0;font-size:var(--t-sm);">
                        <dt style="color:var(--text-faint);">Manufacturer</dt><dd style="margin:0;">{{ $equipment->manufacturer ?? '—' }}</dd>
                        <dt style="color:var(--text-faint);">Model</dt><dd style="margin:0;">{{ $equipment->model ?? '—' }}</dd>
                        <dt style="color:var(--text-faint);">Serial</dt><dd style="margin:0;" class="dot-ledger__key">{{ $equipment->serial_number ?? '—' }}</dd>
                        <dt style="color:var(--text-faint);">Registration</dt><dd style="margin:0;">{{ $equipment->registration_number ?? '—' }}</dd>
                        <dt style="color:var(--text-faint);">Dot.Mines status</dt><dd style="margin:0;">{{ $equipment->mines_status }}</dd>
                        <dt style="color:var(--text-faint);">Allocation</dt><dd style="margin:0;">{{ $equipment->allocation_state ?? '—' }}</dd>
                        <dt style="color:var(--text-faint);">Capacity</dt><dd style="margin:0;">{{ Format::number($equipment->capacity, 0) }} t</dd>
                        <dt style="color:var(--text-faint);">First mirrored</dt><dd style="margin:0;" class="dot-ledger__key">{{ Format::stamp($equipment->first_synced_at, $timezone) }}</dd>
                        <dt style="color:var(--text-faint);">Last synced</dt><dd style="margin:0;" class="dot-ledger__key">{{ Format::stamp($equipment->last_synced_at, $timezone) }}</dd>
                        <dt style="color:var(--text-faint);">Retired</dt><dd style="margin:0;">{{ Format::stamp($equipment->retired_at, $timezone) }}</dd>
                    </dl>
                </div>
                <div class="dot-panel__head" style="border-top:1px solid var(--rule);"><h2 class="dot-panel__title">Alerts on this unit</h2></div>
                <div class="dot-panel__body">
                    @forelse($alerts as $alert)
                        <div style="display:flex;gap:0.6rem;align-items:baseline;padding:0.4rem 0;border-top:1px solid var(--rule);font-size:var(--t-sm);">
                            <span class="dot-badge dot-badge--{{ match($alert->severity) { 'critical' => 'bad', 'warning' => 'warn', default => 'info' } }}">{{ $alert->severity }}</span>
                            <span style="flex:1;">{{ $alert->title }} <span style="color:var(--text-faint);">· {{ $alert->source }}</span></span>
                            <span class="dot-ledger__key" style="color:var(--text-faint);">{{ Format::stamp($alert->source_triggered_at ?? $alert->triggered_at, $timezone) }}{{ $alert->cleared_at ? ' · cleared' : '' }}</span>
                        </div>
                    @empty
                        <p class="dot-page-head__lede">None.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
