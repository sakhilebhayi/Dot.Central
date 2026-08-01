<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.625rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Control Rooms</h1>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">Mining-dispatch domain — one control room per Dot.Mines site.</p>
            </div>
            <a href="{{ route('control-rooms.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;">
                New Control Room
            </a>
        </div>

        @if (session('status'))
            <div style="margin-bottom:1rem;color:#22c55e;font-size:0.8rem;">{{ session('status') }}</div>
        @endif

        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;overflow:hidden;">
            @if($controlRooms->isEmpty())
                <div style="padding:3rem 1.5rem;text-align:center;color:#71717a;font-size:0.85rem;">
                    No control rooms yet. Create one to start tracking dispatch decisions, alerts, and operator sessions.
                </div>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Name</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Mines Site</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Status</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Decisions</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Alerts</th>
                            <th style="padding:0.75rem 1.5rem;text-align:right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($controlRooms as $room)
                        <tr style="border-bottom:1px solid rgba(67,70,86,0.12);">
                            <td style="padding:1rem 1.5rem;font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;">{{ $room->name }}</td>
                            <td style="padding:1rem;color:#a1a1aa;font-size:0.8rem;">{{ $room->mines_site_ref ?? '—' }}</td>
                            <td style="padding:1rem;">
                                @if($room->is_active)
                                    <span style="color:#22c55e;font-size:0.75rem;font-weight:700;">Active</span>
                                @else
                                    <span style="color:#71717a;font-size:0.75rem;font-weight:700;">Inactive</span>
                                @endif
                            </td>
                            <td style="padding:1rem;color:#a1a1aa;font-size:0.8rem;">{{ $room->dispatch_decisions_count }}</td>
                            <td style="padding:1rem;color:#a1a1aa;font-size:0.8rem;">{{ $room->alerts_count }}</td>
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
