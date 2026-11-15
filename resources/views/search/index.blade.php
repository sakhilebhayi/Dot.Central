<x-app-layout>
    <x-slot name="header">Search</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Search</h1>
                <p class="dot-page-head__lede">
                    @if ($typed)
                        Results for &ldquo;{{ $q }}&rdquo; in the control rooms you can see, and in this team's agents.
                    @else
                        Search control rooms, equipment, alerts, exceptions, shifts and agents. Type at least {{ \App\Support\Search\CentralSearch::MIN_LENGTH }} characters in the search above.
                    @endif
                </p>
            </div>
        </div>

        @if (! $typed)
            <div class="dot-panel">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Go to</h2></div>
                <div class="dot-panel__body sr-chips">
                    @foreach ($pages as $page)
                        <a class="dot-btn dot-btn-ghost" href="{{ $page->url }}">{{ $page->title }}</a>
                    @endforeach
                </div>
            </div>
        @elseif ($groups === [])
            <div class="dot-msg dot-msg--info" role="status">
                <div class="dot-msg__title">Nothing matches &ldquo;{{ $q }}&rdquo;.</div>
                <div class="dot-msg__fix">Try fewer words, a machine name, a serial number, a rule code or a shift date such as 2026-11.</div>
            </div>
        @else
            <div class="dot-stack">
                @foreach ($groups as $label => $hits)
                    <section class="dot-panel dot-panel--flush" aria-labelledby="sr-{{ \Illuminate\Support\Str::slug($label) }}">
                        <div class="dot-panel__head">
                            <h2 class="dot-panel__title" id="sr-{{ \Illuminate\Support\Str::slug($label) }}">{{ $label }}</h2>
                            <span class="dot-badge">{{ count($hits) }}{{ count($hits) >= 25 ? '+' : '' }}</span>
                        </div>
                        <ul class="sr-list">
                            @foreach ($hits as $hit)
                                <li>
                                    <a class="sr-row" href="{{ $hit->url }}">
                                        @if ($hit->ref)<span class="sr-row__ref">{{ $hit->ref }}</span>@endif
                                        <span class="sr-row__title">{{ \App\Support\Search\Highlight::mark($hit->title, $words) }}</span>
                                        @if ($hit->sub)<span class="sr-row__sub">{{ $hit->sub }}</span>@endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
