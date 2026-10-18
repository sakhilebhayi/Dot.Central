@php use App\Support\Format; use App\Support\RoleClass; use App\Models\LineupAllocation; @endphp
<div class="dot-page" x-data x-on:lineup-focus.window="setTimeout(() => document.getElementById($event.detail.id)?.focus(), 60)">
    <div class="dot-page-head">
        <div>
            <h1 class="dot-page-head__title">Shift Line-up</h1>
            <p class="dot-page-head__lede">{{ $shift->label() }} · <span class="dot-badge dot-badge--{{ $lineup->isConfirmed() ? 'ok' : 'warn' }}">{{ $lineup->isConfirmed() ? 'CONFIRMED v'.$lineup->version : 'DRAFT' }}</span> · which trucks serve which loading unit, who operates each machine, and why a machine is not working. Machines, their state and their stoppages come from Dot.Mines.</p>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            @if($editable && $proposal && $proposal['previous'] && $proposal['faces'] !== [])
                <button type="button" class="dot-btn dot-btn-ghost" wire:click="copyFromPrevious">Copy from {{ $proposal['previous']->label() }}</button>
            @endif
            @if($editable)
                <button type="button" class="dot-btn dot-btn-ghost" wire:click="openFaceForm">+ New face / work area</button>
                <button type="button" class="dot-btn dot-btn-ghost" wire:click="fillOperators">Fill operators</button>
            @endif
            <a href="{{ route('reports.index', ['r' => 'lineup', 'shift' => $shift->id]) }}" class="dot-btn dot-btn-ghost">Print line-up</a>
            @if($editable)
                <button type="button" class="dot-btn dot-btn-primary" wire:click="confirm">{{ $lineup->isConfirmed() ? 'Re-confirm' : 'Confirm line-up' }}</button>
            @endif
        </div>
    </div>

    @if($notice)<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ $notice }}</div></div>@endif
    @if($refusal && ! $correcting)
        <div class="dot-msg dot-msg--critical" role="alert">
            <div class="dot-msg__title">Refused</div>
            <div style="font-size:var(--t-sm);margin-top:0.3rem;">{{ $refusal }}</div>
            @if(($fix['move'] ?? null) !== null)
                <div style="margin-top:0.5rem;"><button type="button" class="dot-btn dot-btn-primary dot-btn-sm" wire:click="place({{ $fix['move'] }}, true, {{ $fix['face'] }})">Move here</button></div>
            @elseif(($fix['face_move'] ?? false) === true)
                <div style="margin-top:0.5rem;"><button type="button" id="face-move" class="dot-btn dot-btn-primary dot-btn-sm" wire:click="saveFace(true)" aria-label="{{ $fix['label'] ?? 'Move the loading unit here' }}">Move the loading unit here</button></div>
            @elseif(($fix['material_at'] ?? null) !== null)
                <div style="margin-top:0.5rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                    <button type="button" id="material-correct" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="saveFace(false, true)">The material was wrong — correct it</button>
                    <button type="button" id="material-split" class="dot-btn dot-btn-primary dot-btn-sm" wire:click="splitFromForm">It changed at {{ $fix['material_at'] }} — new face from then</button>
                </div>
            @elseif(($fix['operator'] ?? null) !== null)
                <div style="margin-top:0.5rem;"><button type="button" class="dot-btn dot-btn-primary dot-btn-sm" wire:click="assignOperator({{ $fix['unit'] }}, '{{ $fix['operator'] }}', true)">Move operator to this machine</button></div>
            @endif
        </div>
    @endif

    @if($editable && ! $planning)
        <div class="dot-panel" style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;padding:0.6rem 1rem;margin-bottom:1rem;{{ $changeAt ? 'border-color:var(--warn);' : '' }}">
            <label for="changeAt" class="dot-readout__label" style="margin:0;">Changes recorded at</label>
            <input id="changeAt" wire:model.blur="changeAt" class="dot-input" placeholder="HH:MM" style="width:6rem;" />
            <span style="font-size:var(--t-xs);color:var(--text-quiet);">{{ $changeAt ? 'moves, releases, closures and standby are recorded at '.$changeAt.' until you clear it' : 'now — set a time to record a move when it happened (e.g. a truck that moved at 10:20)' }}</span>
            @if($changeAt)<button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="useNow">Use now</button>@endif
        </div>
    @elseif($editable)
        <div class="dot-msg dot-msg--info"><div class="dot-msg__title">Draft line-up — planning</div><div style="font-size:var(--t-sm);">Until the shift starts ({{ Format::stamp($shift->starts_at, $timezone) }}) changes are planning and take effect from shift start. Confirm when the line-up is final.</div></div>
    @elseif(! $shift->isCurrent())
        <div class="dot-msg dot-msg--info"><div class="dot-msg__title">Read only</div><div style="font-size:var(--t-sm);">{{ $shift->label() }} is {{ $shift->status }}; the line-up can only change after a supervisor reopens the shift.</div></div>
    @endif

    @if($correcting)
        @php($arrival = $correctionEdge === 'ARRIVAL')
        @php($cName = $correcting->equipment?->name ?? 'The machine')
        <form wire:submit="saveCorrection" wire:keydown.escape="cancelCorrection" class="dot-panel" role="dialog" aria-labelledby="corr-title" aria-describedby="corr-help" style="margin-bottom:1.25rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;">
            <h2 id="corr-title" class="dot-panel__title" style="grid-column:1/-1;">{{ $arrival ? 'When did '.$cName.' arrive on '.$correcting->face?->label().'?' : 'When did '.$cName.' leave '.$correcting->face?->label().'?' }}</h2>
            <p id="corr-help" class="dot-readout__context" style="grid-column:1/-1;margin:0;">Recorded {{ Format::stamp($arrival ? $correcting->from_at : $correcting->to_at, $timezone) }}. A move is one moment: {{ count($movesWith['other']) + count($movesWith['same']) > 1 || ($movesWith['other'] === [] && $movesWith['same'] !== []) ? implode(' and ', array_merge($movesWith['other'], $movesWith['same'])).(count($movesWith['other']) + count($movesWith['same']) > 1 ? ' are' : ' is').' corrected with it.' : 'the face it '.($arrival ? 'came from' : 'went to').' is corrected with it.' }} Times are the control room's clock ({{ $timezone }}).</p>
            <div id="corr-alert" role="alert" style="grid-column:1/-1;">
                @if($refusal)
                    <div class="dot-msg dot-msg--critical"><div class="dot-msg__title">Refused</div><div style="font-size:var(--t-sm);margin-top:0.3rem;">{{ $refusal }}</div></div>
                @endif
            </div>
            <div wire:key="corr-time-field"><label class="jet-label" for="corr-time">Time (HH:MM)</label><input id="corr-time" wire:model="correction.time" class="dot-input" inputmode="numeric" placeholder="HH:MM" required x-init="setTimeout(() => $el.focus(), 60)" /></div>
            <div wire:key="corr-reason-field"><label class="jet-label" for="corr-reason">Reason</label><input id="corr-reason" wire:model="correction.reason" maxlength="255" class="dot-input" placeholder="e.g. the move was clicked late" required /></div>
            <div style="grid-column:1/-1;display:flex;gap:0.5rem;"><button type="submit" class="dot-btn dot-btn-primary">Correct the time</button><button type="button" class="dot-btn dot-btn-ghost" wire:click="cancelCorrection">Cancel</button></div>
        </form>
    @endif

    <div class="dot-panels dot-panels--6" style="margin-bottom:1.25rem;">
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Faces</span><span class="dot-readout__value">{{ $summary['faces'] }}</span><span class="dot-readout__context">{{ $summary['loading_faces'] }} loading · {{ $summary['faces'] - $summary['loading_faces'] }} work areas</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Trucks placed</span><span class="dot-readout__value {{ $summary['trucks_free'] ? 'dot-readout__value--warn' : '' }}">{{ $summary['trucks_placed'] }}<span class="dot-readout__unit">/ {{ $summary['trucks_total'] }}</span></span><span class="dot-readout__context">{{ $summary['trucks_free'] }} free</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Loading units placed</span><span class="dot-readout__value">{{ $summary['loaders_placed'] }}<span class="dot-readout__unit">/ {{ $summary['loaders_total'] }}</span></span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Operators</span><span class="dot-readout__value {{ $summary['placed_with_operator'] < $summary['placed'] ? 'dot-readout__value--warn' : '' }}">{{ $summary['placed_with_operator'] }}<span class="dot-readout__unit">/ {{ $summary['placed'] }}</span></span><span class="dot-readout__context">placed machines with an operator</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Down</span><span class="dot-readout__value {{ $summary['down'] ? 'dot-readout__value--bad' : '' }}">{{ $summary['down'] }}</span><span class="dot-readout__context">reported stopped by Dot.Mines</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Standby</span><span class="dot-readout__value">{{ $summary['standby'] }}</span><span class="dot-readout__context">standby, no operator, off site</span></div>
    </div>

    @if($faceFormOpen)
        <form wire:submit="saveFace" class="dot-panel" style="margin-bottom:1.25rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;" aria-label="Face form">
            <h2 class="dot-panel__title" style="grid-column:1/-1;">{{ $editingFaceId ? 'Edit face' : 'New face / work area' }}</h2>
            @if($locations['source'] !== 'geofence')<p class="dot-readout__context" style="grid-column:1/-1;margin:0;">{{ $locations['source'] === 'area' ? 'No geofences in Dot.Mines for this room yet, so faces are placed in its mine areas.' : 'No geofences or mine areas in Dot.Mines for this room yet, so faces use default area names; set up geofences in Dot.Mines and the list switches on the next sync.' }}</p>@endif
            <div><label class="jet-label" for="ff-kind">Type</label><select id="ff-kind" wire:model.live="faceForm.kind" class="dot-input"><option value="LOADING">Loading face — trucks are loaded here</option><option value="AREA">Work area — dozing, drilling, roads …</option></select></div>
            @if($faceForm['kind'] === 'LOADING')
                <div><label class="jet-label" for="ff-material">Material</label><input id="ff-material" wire:model="faceForm.material" class="dot-input" placeholder="e.g. Overburden" /></div>
            @else
                <div><label class="jet-label" for="ff-activity">Activity</label><input id="ff-activity" wire:model="faceForm.activity" class="dot-input" placeholder="e.g. Dozing" required /></div>
            @endif
            <div><label class="jet-label" for="ff-location">Where (from {{ match($locations['source']) { 'geofence' => 'Dot.Mines geofences', 'area' => 'Dot.Mines mine areas', default => 'default area names' } }})</label><select id="ff-location" wire:model="faceForm.location" class="dot-input" required><option value="">— pick a location —</option>@foreach(collect($locations['options'])->groupBy('group') as $group => $items)<optgroup label="{{ $group }}">@foreach($items as $o)<option value="{{ $o['key'] }}">{{ $o['name'] }}</option>@endforeach</optgroup>@endforeach</select></div>
            <div><label class="jet-label" for="ff-cut">Cut</label><input id="ff-cut" wire:model="faceForm.cut" class="dot-input" /></div>
            <div><label class="jet-label" for="ff-blocks">Blocks (space separated)</label><input id="ff-blocks" wire:model="faceForm.blocks" class="dot-input" /></div>
            <div><label class="jet-label" for="ff-notes">Notes</label><input id="ff-notes" wire:model="faceForm.notes" class="dot-input" /></div>
            @if($faceForm['kind'] === 'LOADING')
                <div style="grid-column:1/-1;">
                    <span class="jet-label">Loading unit(s)</span>
                    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                        @foreach($pool->where('role_class', RoleClass::LOADER) as $p)
                            <label class="dot-btn dot-btn-ghost dot-btn-sm" style="gap:0.3rem;"><input type="checkbox" wire:model="faceForm.loader_ids" value="{{ $p['equipment']->id }}" /> {{ $p['equipment']->name }} <small style="color:var(--text-faint);">{{ $p['state'] === 'PLACED' ? 'face '.$p['placement']->face->seq : strtolower($p['state']) }}</small></label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div style="grid-column:1/-1;display:flex;gap:0.5rem;"><button type="submit" class="dot-btn dot-btn-primary">Save</button><button type="button" class="dot-btn dot-btn-ghost" wire:click="cancelFaceForm">Cancel</button></div>
        </form>
    @endif

    <div class="dot-split">
        <div class="dot-stack">
            @forelse($faces as $face)
                @php($members = ($byFace[$face->id] ?? collect())->whereNull('to_at')->sortBy(fn ($a) => array_search($a->role, LineupAllocation::ROLES, true).'|'.$a->from_at->timestamp))
                @php($ended = ($byFace[$face->id] ?? collect())->whereNotNull('to_at'))
                @php($loaders = $members->where('role', 'LOADER'))
                @php($trucks = $members->where('role', 'HAUL'))
                <section class="dot-panel dot-panel--flush" style="{{ $target === $face->id ? 'border-color:var(--signal);box-shadow:inset 3px 0 0 var(--signal);' : '' }}" aria-label="{{ $face->label() }}">
                    <div class="dot-panel__head" style="cursor:pointer;flex-wrap:wrap;" wire:click="setTarget({{ $face->id }})" role="button" tabindex="0" title="Click to send machines to this face" aria-pressed="{{ $target === $face->id ? 'true' : 'false' }}">
                        <div style="flex:1 1 14rem;min-width:0;">
                            <h2 class="dot-panel__title">{{ $face->label() }}</h2>
                            <div style="font-size:var(--t-xs);color:var(--text-quiet);margin-top:0.2rem;">{{ $face->kind === 'AREA' ? 'Work area' : 'Loading face' }} · {{ $loaders->isNotEmpty() ? $loaders->map(fn ($a) => $poolByUnit[$a->equipment_id]['equipment']->name ?? '?')->implode(' + ') : ($face->kind === 'AREA' ? 'no loading unit needed' : 'no loading unit') }}@if($face->kind === 'LOADING') · {{ $trucks->count() }} truck(s)@endif@if(($linked[$face->id] ?? '') !== '') · same loading unit as {{ $linked[$face->id] }}@endif@if(isset($splitFrom[$face->id])) · carries on from {{ $splitFrom[$face->id] }}@endif{{ $target === $face->id ? ' · machines you click go here' : '' }}</div>
                        </div>
                        @if($editable)
                            <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;" x-on:click.stop>
                                <button type="button" id="face-{{ $face->id }}-edit" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="openFaceForm({{ $face->id }})" aria-label="Edit {{ $face->label() }}">Edit</button>
                                <input wire:model="closeReason.{{ $face->id }}" class="dot-input" placeholder="Why close?" style="width:9rem;" maxlength="255" aria-label="Reason for closing {{ $face->label() }}" />
                                <button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="closeFace({{ $face->id }})">Close face</button>
                            </div>
                        @endif
                    </div>
                    <div class="dot-table-wrap">
                        <table class="dot-ledger">
                            <thead><tr><th>Role</th><th>Machine</th><th>Operator</th><th>Since</th><th></th></tr></thead>
                            <tbody>
                            @forelse($members as $a)
                                @php($p = $poolByUnit[$a->equipment_id] ?? null)
                                <tr>
                                    <td><span class="dot-badge dot-badge--{{ $a->role === 'LOADER' ? 'warn' : ($a->role === 'HAUL' ? 'info' : 'neutral') }}">{{ LineupAllocation::ROLE_LABELS[$a->role] }}</span></td>
                                    <td class="dot-ledger__key">{{ $p['equipment']->name ?? '?' }}@if($p && $p['down'])<div style="font-size:var(--t-xs);color:var(--bad);">down: {{ $p['down']['cause'] }}</div>@endif</td>
                                    <td>
                                        @if($editable)
                                            <select class="dot-input" style="width:auto;{{ $p && $p['operator'] === null ? 'border-color:var(--warn);' : '' }}" wire:change="assignOperator({{ $a->equipment_id }}, $event.target.value)" aria-label="Operator of {{ $p['equipment']->name ?? '' }}">
                                                <option value="">— operator —</option>
                                                @foreach($operators as $o)<option value="{{ $o->id }}" @selected($p && $p['operator']?->id === $o->id)>{{ $o->label() }}</option>@endforeach
                                            </select>
                                            @if($p && $p['mines_operator'] !== null && $p['mines_operator']->id !== ($p['operator']?->id))<div style="font-size:var(--t-xs);color:var(--text-quiet);margin-top:0.2rem;">Dot.Mines has {{ $p['mines_operator']->name() }} on this machine</div>@endif
                                        @else
                                            {{ $p['operator']?->label() ?? '—' }}
                                        @endif
                                    </td>
                                    @php($name = $p['equipment']->name ?? '?')
                                    @php($since = $a->from_at->equalTo($shift->starts_at) ? 'shift start' : Format::stamp($a->from_at, $timezone))
                                    <td class="dot-ledger__key">
                                        @if($editable && ! $planning)
                                            <button type="button" id="corr-{{ $a->id }}-arrival" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="startCorrection({{ $a->id }}, 'ARRIVAL')" aria-label="Correct when {{ $name }} arrived on {{ $face->label() }} (recorded {{ Format::stamp($a->from_at, $timezone) }})" title="Correct when {{ $name }} arrived on this face ({{ Format::stamp($a->from_at, $timezone) }})">{{ $a->from_at->equalTo($shift->starts_at) ? 'shift start' : $a->from_at->copy()->setTimezone($timezone)->format('H:i') }}</button>
                                        @else
                                            {{ $since }}
                                        @endif
                                        @if(isset($corrected[$a->id.':ARRIVAL']))<div><span class="dot-badge dot-badge--info" title="{{ $corrected[$a->id.':ARRIVAL'] }}">corrected</span></div>@endif
                                    </td>
                                    <td>@if($editable)<span style="display:flex;gap:0.3rem;">@unless($planning)<input wire:model="releaseReason.{{ $a->id }}" class="dot-input" placeholder="why?" style="width:7rem;" maxlength="255" aria-label="Reason to take {{ $p['equipment']->name ?? '' }} off" />@endunless<button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="release({{ $a->id }})" title="Take {{ $p['equipment']->name ?? '' }} off this face">Remove</button></span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="dot-ledger__empty">No machines yet — click machines in the fleet panel.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($ended->isNotEmpty())
                        <div style="padding:0.5rem 1.25rem;font-size:var(--t-xs);color:var(--text-faint);">Moved off this face:
                            @foreach($ended as $e)
                                @php($eName = $poolByUnit[$e->equipment_id]['equipment']->name ?? '?')
                                <span style="margin-right:0.6rem;">{{ $eName }} {{ Format::stamp($e->from_at, $timezone) }}–@if($editable && ! $planning)<button type="button" id="corr-{{ $e->id }}-departure" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="startCorrection({{ $e->id }}, 'DEPARTURE')" aria-label="Correct when {{ $eName }} left {{ $face->label() }} (recorded {{ Format::stamp($e->to_at, $timezone) }})" title="Correct when {{ $eName }} left this face ({{ Format::stamp($e->to_at, $timezone) }})">{{ $e->to_at->copy()->setTimezone($timezone)->format('H:i') }}</button>@else{{ Format::stamp($e->to_at, $timezone) }}@endif @if(isset($corrected[$e->id.':DEPARTURE']))<span class="dot-badge dot-badge--info" title="{{ $corrected[$e->id.':DEPARTURE'] }}">corrected</span>@endif</span>
                            @endforeach
                        </div>
                    @endif
                    @if($face->notes)<div style="padding:0 1.25rem 0.6rem;font-size:var(--t-xs);color:var(--text-quiet);">{{ $face->notes }}</div>@endif
                </section>
            @empty
                <div class="dot-panel">
                    <h2 class="dot-panel__title">No faces yet</h2>
                    <p class="dot-page-head__lede">{{ $proposal && $proposal['previous'] && $proposal['faces'] !== [] ? 'Start from the previous shift ('.count($proposal['faces']).' faces) with "Copy from …", or add faces one by one.' : 'Add the first face: pick the loading unit, the material, the cut and blocks.' }}</p>
                </div>
            @endforelse
            @if($closedFaces->isNotEmpty())<p style="font-size:var(--t-xs);color:var(--text-faint);">Closed faces: {{ $closedFaces->map(fn ($f) => $f->label().' (closed '.Format::stamp($f->closed_at, $timezone).': '.$f->close_reason.')')->implode(' · ') }}</p>@endif

            <section class="dot-panel dot-panel--flush" aria-label="Not on the line-up">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Not on the line-up ({{ $summary['free'] }})</h2></div>
                <div class="dot-table-wrap">
                    <table class="dot-ledger">
                        <thead><tr><th>Machine</th><th>Role</th><th>Operator</th><th>Reason</th></tr></thead>
                        <tbody>
                        @forelse($pool->where('state', 'FREE') as $p)
                            <tr>
                                <td class="dot-ledger__key">{{ $p['equipment']->name }}</td>
                                <td>{{ RoleClass::label($p['role_class']) }}</td>
                                <td>{{ $p['operator']?->label() ?? '—' }}</td>
                                <td>@if($editable)<span style="display:flex;gap:0.4rem;"><select wire:model="standbyPick.{{ $p['equipment']->id }}" class="dot-input" style="width:auto;" aria-label="Reason for {{ $p['equipment']->name }}"><option value="">— reason —</option>@foreach($standbyReasons as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select><button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="setStandby({{ $p['equipment']->id }})">Record</button></span>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="dot-ledger__empty">Every machine has a place or a reason.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($pool->where('state', 'STANDBY')->isNotEmpty())
                    <div style="padding:0.6rem 1.25rem;font-size:var(--t-xs);color:var(--text-quiet);">On standby: @foreach($pool->where('state', 'STANDBY') as $p)<span style="margin-right:0.6rem;">{{ $p['equipment']->name }} ({{ $p['standby']->reason?->name }} since {{ Format::stamp($p['standby']->from_at, $timezone) }})@if($editable) <button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="endStandby({{ $p['standby']->id }})">end</button>@endif</span>@endforeach</div>
                @endif
            </section>

            <section class="dot-panel dot-panel--flush" aria-label="Checks before confirming">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Checks before confirming ({{ $checks->count() }})</h2></div>
                <div class="dot-panel__body">
                    @if($checks->isEmpty())
                        <div class="dot-msg dot-msg--ok"><div class="dot-msg__title">Ready to confirm</div><div style="font-size:var(--t-sm);">Every face has its loading unit and trucks, every placed machine has an operator, and no machine is unaccounted for.</div></div>
                    @else
                        @foreach($checks->groupBy('code') as $code => $group)
                            <div class="dot-msg dot-msg--{{ strtolower($group->first()['severity']) === 'warning' ? 'warning' : 'review' }}">
                                <div class="dot-msg__title"><span class="dot-msg__code">{{ $code }}</span> {{ $group->count() > 1 ? $group->count().' items' : $group->first()['title'] }}</div>
                                <div style="font-size:var(--t-sm);">{{ $group->count() > 1 ? $group->pluck('title')->implode(' · ') : $group->first()['message'] }}</div>
                                <div class="dot-msg__fix">→ {{ $group->first()['fix'] }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </section>
        </div>

        <section class="dot-panel dot-panel--flush" aria-label="Fleet" style="position:sticky;top:1rem;">
            <div class="dot-panel__head">
                <h2 class="dot-panel__title">Fleet</h2>
                <div class="dot-segmented" role="group" aria-label="Filter">
                    @foreach(['ALL' => 'All', 'FREE' => 'Free ('.$summary['free'].')', 'PLACED' => 'Placed', 'OUT' => 'Down / standby'] as $k => $l)
                        <button type="button" class="dot-btn dot-btn-sm {{ $poolFilter === $k ? 'dot-btn-primary' : 'dot-btn-ghost' }}" wire:click="$set('poolFilter', '{{ $k }}')">{{ $l }}</button>
                    @endforeach
                </div>
            </div>
            <div class="dot-panel__body">
                <p style="font-size:var(--t-xs);color:var(--text-quiet);margin:0 0 0.6rem;">{{ $target ? 'Click a machine to put it on the target face. Amber = placed, red = down, dim = standby.' : 'Add a face first.' }}</p>
                @foreach([RoleClass::LOADER, RoleClass::HAUL, RoleClass::SUPPORT] as $class)
                    @if(($byClass[$class] ?? collect())->isNotEmpty())
                        <h3 class="dot-readout__label" style="margin:0.5rem 0 0.3rem;">{{ RoleClass::label($class) }}s ({{ $byClass[$class]->count() }})</h3>
                        <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                            @foreach($byClass[$class] as $p)
                                <button type="button" class="dot-btn dot-btn-sm {{ $p['state'] === 'PLACED' ? 'dot-btn-primary' : 'dot-btn-ghost' }}" style="{{ $p['state'] === 'DOWN' ? 'border-color:var(--bad);color:var(--bad);' : ($p['state'] === 'STANDBY' ? 'opacity:.6;' : '') }}" @disabled(! $editable) wire:click="place({{ $p['equipment']->id }})" aria-label="{{ $p['equipment']->name }} ({{ $p['state'] === 'PLACED' ? 'placed on '.$p['placements']->map(fn ($a) => 'Face '.$a->face->seq)->implode(' and ') : strtolower($p['state']) }}) — put on the target face" title="{{ $p['down']['cause'] ?? ($p['placement']?->face?->label() ?? ($p['standby']?->reason?->name ?? 'free')) }}">
                                    {{ $p['equipment']->name }} <small style="font-weight:400;opacity:.8;">{{ $p['state'] === 'PLACED' ? 'face '.$p['placements']->map(fn ($a) => $a->face->seq)->implode('+') : strtolower($p['state']) }}</small>
                                </button>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    </div>
</div>
