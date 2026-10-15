<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · Administration</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Administration</h1>
                <p class="dot-page-head__lede">{{ $controlRoom->name }} · shift pattern, stoppage reasons and rule thresholds. Every change is audited; thresholds need a reason.</p>
            </div>
            <div style="display:flex;gap:0.5rem;"><a href="{{ route('control-rooms.connection.edit', $controlRoom) }}" class="dot-btn dot-btn-ghost">Dot.Mines connection</a><a href="{{ route('control-rooms.show', $controlRoom) }}" class="dot-btn dot-btn-ghost">Room &amp; members</a></div>
        </div>
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif
        <x-validation-errors style="margin-bottom:1rem;" />
        <div class="dot-stack">
        <div class="dot-panels dot-panels--split" id="shifts">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Shift types</h2></div>
                <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Code</th><th>Name</th><th>Start</th><th>End</th><th>Crosses midnight</th><th>Active</th><th></th></tr></thead><tbody>
                    @foreach($shiftTypes as $t)<tr><td class="dot-ledger__key">{{ $t->code }}</td><td>{{ $t->name }}</td><td class="dot-ledger__key">{{ $t->starts_at }}</td><td class="dot-ledger__key">{{ $t->ends_at }}</td><td>{{ $t->crosses_midnight ? 'yes' : 'no' }}</td><td>{{ $t->is_active ? 'yes' : 'no' }}</td><td><form method="POST" action="{{ route('control-rooms.admin.shift-types.toggle', [$controlRoom, $t]) }}">@csrf<button class="dot-btn dot-btn-ghost dot-btn-sm">{{ $t->is_active ? 'Deactivate' : 'Activate' }}</button></form></td></tr>@endforeach
                </tbody></table></div>
            </div>
            <form method="POST" action="{{ route('control-rooms.admin.shift-types', $controlRoom) }}" class="dot-panel" style="display:flex;flex-direction:column;gap:0.75rem;">
                @csrf
                <h2 class="dot-panel__title">Add shift type</h2>
                <div><x-label for="code" value="Code" /><x-input id="code" name="code" placeholder="e.g. MID" required /></div>
                <div><x-label for="name" value="Name" /><x-input id="name" name="name" placeholder="Mid shift" required /></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;"><div><x-label for="starts_at" value="Starts (HH:MM)" /><x-input id="starts_at" name="starts_at" placeholder="12:00" required /></div><div><x-label for="ends_at" value="Ends (HH:MM)" /><x-input id="ends_at" name="ends_at" placeholder="20:00" required /></div></div>
                <div><x-button>Add</x-button></div>
            </form>
        </div>
        <div class="dot-panels dot-panels--split" id="reasons">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Stoppage reasons</h2></div>
                <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Code</th><th>Reason</th><th>Category</th><th>Availability</th><th>Description</th><th>Active</th><th></th></tr></thead><tbody>
                    @foreach($reasons as $r)<tr><td class="dot-ledger__key">{{ $r->code }}</td><td>{{ $r->name }}</td><td>{{ $r->category }}</td><td>{{ $r->counts_against_availability ? 'counts' : 'operational' }}</td><td>{{ $r->requires_description ? 'required' : '—' }}</td><td>{{ $r->is_active ? 'yes' : 'no' }}</td><td><form method="POST" action="{{ route('control-rooms.admin.reasons.toggle', [$controlRoom, $r]) }}">@csrf<button class="dot-btn dot-btn-ghost dot-btn-sm">{{ $r->is_active ? 'Deactivate' : 'Activate' }}</button></form></td></tr>@endforeach
                </tbody></table></div>
            </div>
            <form method="POST" action="{{ route('control-rooms.admin.reasons', $controlRoom) }}" class="dot-panel" style="display:flex;flex-direction:column;gap:0.75rem;">
                @csrf
                <h2 class="dot-panel__title">Add reason</h2>
                <div><x-label for="rcode" value="Code" /><x-input id="rcode" name="code" placeholder="e.g. CRUSHER" required /></div>
                <div><x-label for="rname" value="Reason" /><x-input id="rname" name="name" required /></div>
                <div><x-label for="category" value="Category" /><select id="category" name="category" class="dot-input" required>@foreach($categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
                <label style="font-size:var(--t-sm);display:flex;gap:0.4rem;align-items:center;"><input type="checkbox" name="counts_against_availability" value="1" /> Counts against availability</label>
                <label style="font-size:var(--t-sm);display:flex;gap:0.4rem;align-items:center;"><input type="checkbox" name="requires_description" value="1" /> Requires a description</label>
                <div><x-button>Add</x-button></div>
            </form>
        </div>
        <form method="POST" action="{{ route('control-rooms.admin.settings', $controlRoom) }}" class="dot-panel" id="thresholds" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
            @csrf
            <h2 class="dot-panel__title" style="grid-column:1/-1;">Rule thresholds</h2>
            @foreach($settingKeys as $key)
                <div><x-label :for="$key" :value="$key" /><x-input :id="$key" :name="$key" type="number" step="0.5" :value="old($key, $settings[$key] ?? 0)" required /></div>
            @endforeach
            <div style="grid-column:1/-1;"><x-label for="reason" value="Reason for the change (audited)" /><x-input id="reason" name="reason" required /></div>
            <div style="grid-column:1/-1;"><x-button>Save thresholds</x-button></div>
            <p class="dot-readout__context" style="grid-column:1/-1;">offline_minutes → EQ-01 · stale_minutes → TEL-02 · idle_minutes_threshold → EQ-04 · zero_loads_hours → EQ-03 · classify_after_minutes → DT-01 · unack_minutes → AL-01 · engine_hours_budget → the variance column on Engine Hours.</p>
        </form>
        </div>
    </div>
</x-app-layout>
