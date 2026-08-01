<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.625rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Control Rooms</h1>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Mining-dispatch domain — one control room per Dot.Mines site.</p>
            </div>
            <a href="{{ route('control-rooms.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;">
                New Control Room
            </a>
        </div>

        @if (session('status'))
            <div style="margin-bottom:1rem;color:#22c55e;font-size:0.8rem;">{{ session('status') }}</div>
        @endif

        {{-- Control-room summary --}}
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
            @php
            $kpis = [
                ['label'=>'Active Control Rooms', 'value'=>$summary['activeControlRooms'], 'icon'=>'satellite_alt', 'accent'=>'#7dd3fc'],
                ['label'=>'Dispatch Decisions',    'value'=>$summary['totalDecisions'],     'icon'=>'alt_route',    'accent'=>'#a855f7'],
                ['label'=>'Open Alerts',           'value'=>$summary['openAlerts'],         'icon'=>'warning',      'accent'=>'#f59e0b'],
                ['label'=>'Active Operator Sessions', 'value'=>$summary['activeSessions'],  'icon'=>'badge',        'accent'=>'#22c55e'],
            ];
            @endphp
            @foreach($kpis as $kpi)
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.1rem 1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                    <span style="font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">{{ $kpi['label'] }}</span>
                    <div style="width:26px;height:26px;border-radius:7px;background:{{ $kpi['accent'] }}1a;display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:14px;color:{{ $kpi['accent'] }};">{{ $kpi['icon'] }}</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--text-primary);line-height:1;">{{ $kpi['value'] }}</div>
            </div>
            @endforeach
        </div>

        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
            @if($controlRooms->isEmpty())
                <div style="padding:3.5rem 1.5rem;text-align:center;">
                    <div style="width:56px;height:56px;border-radius:14px;background:rgba(125,211,252,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                        <span class="material-symbols-rounded" style="font-size:28px;color:#7dd3fc;">satellite_alt</span>
                    </div>
                    <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">No control rooms yet</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">Create one to start tracking dispatch decisions, alerts, and operator sessions for a Dot.Mines site.</p>
                    <a href="{{ route('control-rooms.create') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#fff;text-decoration:none;">
                        <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                        Create your first control room
                    </a>
                </div>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Name</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Mines Site</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Status</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Decisions</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Alerts</th>
                            <th style="padding:0.75rem 1.5rem;text-align:right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($controlRooms as $room)
                        <tr style="border-bottom:1px solid var(--divider);">
                            <td style="padding:1rem 1.5rem;font-family:'Syne',sans-serif;font-weight:700;color:var(--text-primary);">{{ $room->name }}</td>
                            <td style="padding:1rem;color:var(--text-secondary);font-size:0.8rem;">{{ $room->mines_site_ref ?? '—' }}</td>
                            <td style="padding:1rem;">
                                @if($room->is_active)
                                    <span style="color:#22c55e;font-size:0.75rem;font-weight:700;">Active</span>
                                @else
                                    <span style="color:var(--text-muted);font-size:0.75rem;font-weight:700;">Inactive</span>
                                @endif
                            </td>
                            <td style="padding:1rem;color:var(--text-secondary);font-size:0.8rem;">{{ $room->dispatch_decisions_count }}</td>
                            <td style="padding:1rem;color:var(--text-secondary);font-size:0.8rem;">{{ $room->alerts_count }}</td>
                            <td style="padding:1rem 1.5rem;text-align:right;">
                                <a href="{{ route('control-rooms.show', $room) }}" style="color:#fda4af;font-size:0.75rem;font-weight:700;text-decoration:none;">Open →</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
