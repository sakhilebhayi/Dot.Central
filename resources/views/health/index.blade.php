@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">Integration health</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Integration health</h1>
                <p class="dot-page-head__lede">Is the data link working? Each control room's Dot.Mines connection, its last runs, how fresh the mirrored fleet is, and what failed. An operational incident and a data failure must never look the same.</p>
            </div>
            <div class="dot-readout" style="text-align:right;">
                <span class="dot-readout__label">Failed queue jobs</span>
                <span class="dot-readout__value {{ $failedJobs > 0 ? 'dot-readout__value--bad' : '' }}">{{ Format::integer($failedJobs) }}</span>
            </div>
        </div>

        @if (session('status'))
            <div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>
        @endif
        @if (session('error'))
            <div class="dot-msg dot-msg--critical" role="alert"><div class="dot-msg__title">Dot.Mines refused the request</div><div>{{ session('error') }}</div></div>
        @endif

        <div class="dot-stack">
        @forelse($cards as $card)
            @php($connection = $card['connection'])
            @php($room = $card['room'])
            @php($tone = $connection === null ? 'neutral' : match($connection->status) { 'verified' => ($connection->consecutive_failures > 0 ? 'warn' : 'ok'), 'credential_invalid' => 'bad', 'disabled' => 'neutral', default => 'warn' })
            <section class="dot-panels dot-panels--1" aria-labelledby="room-{{ $room->id }}">
                <div class="dot-panel dot-panel--flush">
                    <div class="dot-panel__head">
                        <h2 class="dot-panel__title" id="room-{{ $room->id }}">
                            <span class="dot-status-lamp" style="background:var(--{{ $tone === 'neutral' ? 'text-faint' : $tone }});"></span>{{ $room->name }}
                        </h2>
                        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                            @if($connection)
                                <span class="dot-badge dot-badge--{{ $tone === 'neutral' ? 'info' : $tone }}">{{ str_replace('_', ' ', $connection->status) }}</span>
                                @can('act-on-health')
                                <form method="POST" action="{{ route('health.test', $connection) }}">@csrf<button type="submit" class="dot-btn dot-btn-ghost dot-btn-sm">Test credential</button></form>
                                <form method="POST" action="{{ route('health.sync', $connection) }}">@csrf<button type="submit" class="dot-btn dot-btn-primary dot-btn-sm" @disabled(! $connection->isUsable())>Run sync now</button></form>
                                @endcan
                                @can('manage', $room)
                                <a href="{{ route('control-rooms.connection.edit', $room) }}" class="dot-panel__action">Configure</a>
                                @endcan
                            @else
                                <span class="dot-badge">not connected</span>
                                @can('manage', $room)
                                <a href="{{ route('control-rooms.connection.edit', $room) }}" class="dot-panel__action">Connect</a>
                                @endcan
                            @endif
                        </div>
                    </div>

                    @if($connection)
                    <div class="dot-panels dot-panels--6" style="border:0;border-radius:0;border-bottom:1px solid var(--rule);">
                        <div class="dot-panel dot-readout"><span class="dot-readout__label">Last success</span><span class="dot-readout__value" style="font-size:1.1rem;">{{ Format::age($connection->last_success_at) }}</span><span class="dot-readout__context">{{ Format::stamp($connection->last_success_at, $connection->timezone) }} · every {{ $syncInterval }} min</span></div>
                        <div class="dot-panel dot-readout"><span class="dot-readout__label">Last failure</span><span class="dot-readout__value {{ $connection->consecutive_failures > 0 ? 'dot-readout__value--warn' : '' }}" style="font-size:1.1rem;">{{ Format::age($connection->last_failure_at) }}</span><span class="dot-readout__context">{{ $connection->consecutive_failures }} in a row</span></div>
                        <div class="dot-panel dot-readout"><span class="dot-readout__label">Fleet mirrored</span><span class="dot-readout__value">{{ Format::integer($card['equipment_total']) }}</span><span class="dot-readout__context">machines · cadence {{ $connection->provider_cadence_seconds }} s</span></div>
                        <div class="dot-panel dot-readout"><span class="dot-readout__label">Fresh</span><span class="dot-readout__value dot-readout__value--ok">{{ Format::integer($card['freshness']['fresh']) }}</span><span class="dot-readout__context">within 2× cadence</span></div>
                        <div class="dot-panel dot-readout"><span class="dot-readout__label">Stale</span><span class="dot-readout__value {{ $card['freshness']['stale'] ? 'dot-readout__value--warn' : '' }}">{{ Format::integer($card['freshness']['stale']) }}</span><span class="dot-readout__context">2–4× cadence</span></div>
                        <div class="dot-panel dot-readout"><span class="dot-readout__label">Missing</span><span class="dot-readout__value {{ $card['freshness']['missing'] ? 'dot-readout__value--bad' : '' }}">{{ Format::integer($card['freshness']['missing']) }}</span><span class="dot-readout__context">no usable telemetry</span></div>
                    </div>

                    @if($connection->last_error)
                        <div class="dot-msg dot-msg--warning" style="margin:0.75rem 1.25rem;"><div class="dot-msg__title">Last error</div><div>{{ $connection->last_error }}</div></div>
                    @endif

                    <div class="dot-table-wrap">
                        <table class="dot-ledger">
                            <thead><tr><th>Started</th><th>Trigger</th><th>Status</th><th class="num">Machines</th><th class="num">Upserted</th><th class="num">Retired</th><th class="num">Alerts</th><th>Took</th><th>Error</th></tr></thead>
                            <tbody>
                            @forelse($card['runs'] as $run)
                                <tr>
                                    <td class="dot-ledger__key">{{ Format::stamp($run->started_at, $connection->timezone) }}</td>
                                    <td>{{ $run->trigger }}</td>
                                    <td><span class="dot-badge dot-badge--{{ match($run->status) { 'succeeded' => 'ok', 'partial' => 'warn', 'failed' => 'bad', default => 'info' } }}">{{ $run->status }}</span></td>
                                    <td class="num">{{ Format::integer($run->fetched_machines) }}</td>
                                    <td class="num">{{ Format::integer($run->upserted) }}</td>
                                    <td class="num">{{ Format::integer($run->retired) }}</td>
                                    <td class="num">{{ Format::integer($run->alerts_upserted) }}</td>
                                    <td class="dot-ledger__key">{{ $run->finished_at ? Format::number($run->started_at->diffInSeconds($run->finished_at, true), 0).' s' : 'running' }}</td>
                                    <td style="max-width:28rem;">{{ $run->error_message ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="dot-ledger__empty">No synchronisation has run yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="dot-panel__body" style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:var(--t-sm);color:var(--text-quiet);border-top:1px solid var(--rule);">
                        <span>Webhooks: last delivery {{ $card['last_delivery'] ? Format::age($card['last_delivery']->created_at).' ('.$card['last_delivery']->event.', '.$card['last_delivery']->status.')' : 'none received' }}</span>
                        <span>{{ Format::integer($card['failed_deliveries']) }} failed deliveries</span>
                        <span>Endpoint: <code style="font-family:var(--font-mono);">{{ route('webhooks.mines', $connection) }}</code></span>
                    </div>
                    @else
                    <div class="dot-panel__body"><p class="dot-page-head__lede">No Dot.Mines connection. Nothing is mirrored for this room.</p></div>
                    @endif
                </div>
            </section>
        @empty
            <div class="dot-panel"><p class="dot-page-head__lede">No control rooms to report on.</p></div>
        @endforelse
        </div>
    </div>
</x-app-layout>
