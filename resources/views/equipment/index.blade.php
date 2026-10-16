@php use App\Support\Format; use App\Support\EquipmentState; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · Equipment</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Equipment</h1>
                <p class="dot-page-head__lede">{{ $controlRoom->name }} · mirrored from Dot.Mines{{ $connection ? ' · last sync '.Format::age($connection->last_success_at) : ' · not connected' }}. Every state is derived from Dot.Mines' own status and how old our last contact is; stale or missing telemetry is shown as such, never as a machine state.</p>
            </div>
            <a href="{{ route('control-rooms.show', $controlRoom) }}" class="dot-btn dot-btn-ghost">Back to room</a>
        </div>

        <div class="dot-panels dot-panels--6" style="margin-bottom:1.25rem;">
            @foreach([EquipmentState::Operating, EquipmentState::Idle, EquipmentState::Offline, EquipmentState::Unavailable, EquipmentState::Stale, EquipmentState::Unknown] as $state)
                <div class="dot-panel dot-readout">
                    <span class="dot-readout__label">{{ $state->label() }}</span>
                    <span class="dot-readout__value {{ ($byState[$state->value] ?? 0) > 0 && $state->tone() !== 'neutral' && $state->tone() !== 'info' ? 'dot-readout__value--'.$state->tone() : '' }}">{{ Format::integer($byState[$state->value] ?? 0) }}</span>
                    <span class="dot-readout__context">{{ $state->description() }}</span>
                </div>
            @endforeach
        </div>

        <div class="dot-panel dot-panel--flush">
            <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead><tr><th>Unit</th><th>Type</th><th>State</th><th>Last heard</th><th class="num">Engine h</th><th>Hours as of</th><th class="num">Fuel</th><th>Area</th></tr></thead>
                    <tbody>
                    @forelse($rows as $row)
                        @php($e = $row['equipment'])
                        @php($tone = $row['state']->tone())
                        <tr @if($row['freshness'] !== 'fresh') style="color:var(--text-faint);" @endif>
                            <td class="dot-ledger__key"><a href="{{ route('control-rooms.equipment.show', [$controlRoom, $e]) }}" style="color:inherit;text-decoration:none;">{{ $e->name }}</a><div style="font-size:var(--t-xs);color:var(--text-faint);">{{ $e->model ?? '—' }}</div></td>
                            <td>{{ $e->machine_type ? str_replace('_', ' ', $e->machine_type) : '—' }}</td>
                            <td><span class="dot-badge dot-badge--{{ $tone === 'neutral' ? 'info' : $tone }}">{{ $row['state']->label() }}</span>@if($row['freshness'] !== 'fresh' && $e->mines_status) <span style="font-size:var(--t-xs);color:var(--text-faint);">last known {{ $e->mines_status }}</span>@endif</td>
                            <td class="dot-ledger__key">{{ Format::age($e->last_heard_at) }}</td>
                            <td class="num">{{ Format::number($e->engine_hours) }}</td>
                            <td class="dot-ledger__key">{{ Format::stamp($e->engine_hours_recorded_at, $timezone) }}</td>
                            <td class="num">{{ $e->fuel_level === null ? '—' : Format::number($e->fuel_level, 0).($e->fuel_level_unit === 'percent' ? ' %' : '') }}</td>
                            <td>{{ $e->mine_area_name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="dot-ledger__empty">{{ $connection ? 'Nothing has been mirrored yet. Run a sync from Integration health.' : 'Connect this room to Dot.Mines to mirror its fleet.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
