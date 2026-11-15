<div class="hs">
    <x-dot.search name="q" id="dh-search-q" wire="q" :action="route('search')" hint="Ctrl K" label="Search Dot.Central"
        placeholder="Search Dot.Central" escape-clears :root-attributes="['wire:ignore.self' => '']">
        <x-slot:results>
            @if (! $typed)
                <div class="dsr-head">Go to</div>
                <div class="dsr-chips">
                    @foreach ($pages as $page)
                        <a class="dsr-chip" role="option" href="{{ $page->url }}">{{ $page->title }}</a>
                    @endforeach
                </div>
                <p class="dsr-foot">Search control rooms, equipment, alerts, exceptions, shifts and agents. Type at least {{ \App\Support\Search\CentralSearch::MIN_LENGTH }} characters.</p>
            @elseif ($groups === [])
                <p class="dsr-foot" role="status">Nothing matches &ldquo;{{ $q }}&rdquo;. Try fewer words, a name, a serial number or a rule code.</p>
            @else
                @foreach ($groups as $label => $hits)
                    <div class="dsr-head">{{ $label }}</div>
                    @foreach ($hits as $hit)
                        <a class="dsr-item" role="option" href="{{ $hit->url }}">
                            @if ($hit->ref)<span class="dsr-ref">{{ $hit->ref }}</span>@endif
                            <span class="dsr-label">{{ \App\Support\Search\Highlight::mark($hit->title, $words) }}</span>
                            @if ($hit->tag)<span class="dsr-tag">{{ $hit->tag }}</span>@endif
                        </a>
                    @endforeach
                @endforeach
                <div class="dsr-head">Not here?</div>
                <a class="dsr-item dsr-item--action" role="option" href="{{ route('search', ['q' => $q]) }}">
                    <svg class="dsr-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4"/></svg>
                    <span class="dsr-label">See every result for {{ $q }}</span>
                </a>
            @endif
        </x-slot:results>
    </x-dot.search>
</div>
