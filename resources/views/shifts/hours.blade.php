@php use App\Support\Format; use App\Support\EquipmentState; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · {{ $shift->label() }} · Engine hours</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Engine Hours</h1>
                <p class="dot-page-head__lede">{{ $shift->label() }} · opening and closing readings are the Dot.Mines metrics at the shift edges; run time is their difference. Nothing is typed. Budget line {{ Format::number($budget) }} h of a {{ Format::number($shiftHours) }} h shift.</p>
            </div>
            <a href="{{ route('shifts.show', $shift) }}" class="dot-btn dot-btn-ghost">Shift operations</a>
        </div>
        @include('shifts._strip')
        <div class="dot-panel dot-panel--flush">
            <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead><tr><th>Unit</th><th>State</th><th class="num">Opening h</th><th>as of</th><th class="num">Closing h</th><th>as of</th><th class="num">Run h</th><th class="num">Variance</th><th class="num">Idle h</th><th class="num">Loads</th><th class="num">Stoppage</th><th>Flags</th></tr></thead>
                    <tbody>
                    @forelse($units as $u)
                        @php($state = EquipmentState::from($u['state']))
                        <tr @if($u['freshness'] !== 'fresh') style="color:var(--text-faint);" @endif>
                            <td class="dot-ledger__key"><a href="{{ route('control-rooms.equipment.show', [$controlRoom, $u['equipment']]) }}" style="color:inherit;text-decoration:none;">{{ $u['equipment']->name }}</a><div style="font-size:var(--t-xs);color:var(--text-faint);">{{ $u['equipment']->model ?? ($u['equipment']->machine_type ?? '—') }}</div></td>
                            <td><span class="dot-badge dot-badge--{{ $state->tone() === 'neutral' ? 'info' : $state->tone() }}">{{ $state->label() }}</span></td>
                            <td class="num">{{ Format::number($u['opening_hours']) }}</td>
                            <td class="dot-ledger__key">{{ Format::stamp($u['opening_at'], $timezone) }}</td>
                            <td class="num">{{ Format::number($u['closing_hours']) }}</td>
                            <td class="dot-ledger__key">{{ Format::stamp($u['closing_at'], $timezone) }}</td>
                            <td class="num" style="font-weight:600;">{{ Format::number($u['run_hours']) }}</td>
                            <td class="num">{{ $u['run_hours'] === null ? '—' : Format::number($budget - $u['run_hours']) }}</td>
                            <td class="num">{{ Format::number($u['idle_hours']) }}</td>
                            <td class="num">{{ Format::integer($u['loads']) }}</td>
                            <td class="num">{{ Format::integer($u['stoppage_minutes']) }} min</td>
                            <td>@foreach($u['flags'] as $flag)<span class="dot-badge dot-badge--warn">{{ str_replace('_', ' ', $flag) }}</span> @endforeach</td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="dot-ledger__empty">No machines mirrored for this room.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="dot-panel__body" style="font-size:var(--t-xs);color:var(--text-faint);border-top:1px solid var(--rule);">Source: Dot.Mines metrics (`operating_hours`, `idle_hours`, cumulative `load_count`), provider clock. A machine with no reading at the shift start takes its first reading inside the window and is flagged.</div>
        </div>
    </div>
</x-app-layout>
