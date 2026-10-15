<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · Dot.Mines connection</x-slot>
    <div style="padding:2rem 2.5rem;max-width:640px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Dot.Mines connection</h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">
            Maps this control room to one Dot.Mines team. The token is minted by a member of that team in Dot.Mines
            (Profile → API Tokens) with the <em>read</em> and <em>update</em> abilities. It is stored encrypted and never shown again.
        </p>

        <x-validation-errors class="mb-4" />

        @if($connection)
        <div class="dot-card" style="padding:0.9rem 1rem;margin-bottom:1.25rem;display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;font-size:0.75rem;">
            <div><div style="color:var(--text-muted);font-size:0.62rem;text-transform:uppercase;letter-spacing:0.08em;">Status</div><span class="dot-badge dot-badge-accent">{{ $connection->status }}</span></div>
            <div><div style="color:var(--text-muted);font-size:0.62rem;text-transform:uppercase;letter-spacing:0.08em;">Last success</div><span class="metric-val">{{ $connection->last_success_at?->format('Y-m-d H:i') ?? '—' }}</span></div>
            <div><div style="color:var(--text-muted);font-size:0.62rem;text-transform:uppercase;letter-spacing:0.08em;">Failures in a row</div><span class="metric-val">{{ $connection->consecutive_failures }}</span></div>
            @if($connection->last_error)
            <div style="grid-column:1/-1;color:#f59e0b;">{{ $connection->last_error }}</div>
            @endif
        </div>
        @endif

        <form method="POST" action="{{ route('control-rooms.connection.update', $controlRoom) }}" style="display:flex;flex-direction:column;gap:1rem;">
            @csrf
            @method('PUT')

            <div>
                <x-label for="base_url" value="Dot.Mines base URL" />
                <x-input id="base_url" name="base_url" type="url" class="mt-1 block w-full" value="{{ old('base_url', $connection->base_url ?? config('ecosystem.platforms.mines.url')) }}" required />
            </div>

            <div>
                <x-label for="mines_team_id" value="Dot.Mines team ID (the mine this room operates)" />
                <x-input id="mines_team_id" name="mines_team_id" type="number" min="1" class="mt-1 block w-full" value="{{ old('mines_team_id', $connection->mines_team_id ?? '') }}" />
            </div>

            <div>
                <x-label for="api_token" value="{{ $connection && $connection->api_token ? 'API token (leave blank to keep the stored token)' : 'API token' }}" />
                <x-input id="api_token" name="api_token" type="password" autocomplete="off" class="mt-1 block w-full" value="" {{ $connection && $connection->api_token ? '' : 'required' }} />
            </div>

            <div>
                <x-label for="webhook_secret" value="Webhook signing secret (leave blank to keep)" />
                <x-input id="webhook_secret" name="webhook_secret" type="password" autocomplete="off" class="mt-1 block w-full" value="" />
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <x-label for="provider_cadence_seconds" value="Provider cadence (seconds)" />
                    <x-input id="provider_cadence_seconds" name="provider_cadence_seconds" type="number" min="60" max="86400" class="mt-1 block w-full" value="{{ old('provider_cadence_seconds', $connection->provider_cadence_seconds ?? 900) }}" required />
                    <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">How often the telemetry provider reports. Data older than twice this is shown as stale, four times as unknown.</p>
                </div>
                <div>
                    <x-label for="timezone" value="Control-room timezone" />
                    <select id="timezone" name="timezone" class="dot-input mt-1" required>
                        @foreach($timezones as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $connection->timezone ?? 'UTC') === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if($connection)
            <div>
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:var(--text-secondary);">
                    <input type="checkbox" name="status" value="disabled" @checked(old('status', $connection->status) === 'disabled') />
                    Disable synchronisation for this room
                </label>
            </div>
            @endif

            <div style="display:flex;gap:0.75rem;align-items:center;">
                <x-button>Save connection</x-button>
                <a href="{{ route('control-rooms.show', $controlRoom) }}" style="font-size:0.8rem;color:var(--text-muted);text-decoration:none;">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
