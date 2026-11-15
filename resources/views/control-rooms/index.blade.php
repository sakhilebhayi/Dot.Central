<x-app-layout>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Control Rooms</h1>
                <p class="dot-page-head__lede">Mining-dispatch domain — one control room per Dot.Mines site.</p>
            </div>
            <a href="{{ route('control-rooms.create') }}" class="dot-btn dot-btn-primary">
                New Control Room
            </a>
        </div>

        @if (session('status'))
            <div style="margin-bottom:1rem;color:var(--ok);font-size:0.8rem;">{{ session('status') }}</div>
        @endif

        {{-- Control-room summary --}}
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
            @php
            $kpis = [
                ['label'=>'Active Control Rooms', 'value'=>$summary['activeControlRooms'], 'icon'=>'satellite_alt', 'accent'=>'var(--info)'],
                ['label'=>'Dispatch Decisions',    'value'=>$summary['totalDecisions'],     'icon'=>'alt_route',    'accent'=>'var(--signal)'],
                ['label'=>'Open Alerts',           'value'=>$summary['openAlerts'],         'icon'=>'warning',      'accent'=>'var(--warn)'],
                ['label'=>'Active Operator Sessions', 'value'=>$summary['activeSessions'],  'icon'=>'badge',        'accent'=>'var(--ok)'],
            ];
            @endphp
            @foreach($kpis as $kpi)
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.1rem 1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                    <span style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">{{ $kpi['label'] }}</span>
                    <div style="width:26px;height:26px;border-radius:7px;background:color-mix(in srgb, {{ $kpi['accent'] }} 14%, transparent);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:14px;color:{{ $kpi['accent'] }};">{{ $kpi['icon'] }}</span>
                    </div>
                </div>
                <div style="font-family:var(--font-display);font-size:1.5rem;font-weight:800;color:var(--text-primary);line-height:1;">{{ $kpi['value'] }}</div>
            </div>
            @endforeach
        </div>

        <div class="dot-panel dot-panel--flush">
            @if($controlRooms->isEmpty())
                <div style="padding:3.5rem 1.5rem;text-align:center;">
                    <div style="width:56px;height:56px;border-radius:14px;background:rgba(125,211,252,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                        <span class="material-symbols-rounded" style="font-size:28px;color:var(--info);">satellite_alt</span>
                    </div>
                    <div style="font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">No control rooms yet</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">Create one to start tracking dispatch decisions, alerts, and operator sessions for a Dot.Mines site.</p>
                    <a href="{{ route('control-rooms.create') }}" class="dot-btn dot-btn-primary">
                        <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                        Create your first control room
                    </a>
                </div>
            @else
                <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead>
                        <tr><th>Name</th><th>Dot.Mines</th><th>Status</th><th class="th-num">Decisions</th><th class="th-num">Alerts</th><th aria-label="Open"></th></tr>
                    </thead>
                    <tbody>
                        @foreach($controlRooms as $room)
                        <tr>
                            <td class="cr-name">{{ $room->name }}</td>
                            <td>@if($room->minesConnection) team #{{ $room->minesConnection->mines_team_id ?? '—' }} · {{ $room->minesConnection->status }} @else not connected @endif</td>
                            <td>
                                @if($room->is_active)
                                    <span class="dot-badge dot-badge--ok">Active</span>
                                @else
                                    <span class="dot-badge">Inactive</span>
                                @endif
                            </td>
                            <td class="num">{{ $room->dispatch_decisions_count }}</td>
                            <td class="num">{{ $room->alerts_count }}</td>
                            <td class="cr-open"><a href="{{ route('control-rooms.show', $room) }}" class="dot-link-hit" aria-label="Open {{ $room->name }}">Open →</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
