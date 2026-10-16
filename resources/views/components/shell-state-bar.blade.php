<header class="statebar statebar--{{ $level }}" role="banner">
    <div class="statebar__bay">
        <button type="button" class="sidebar-toggle" aria-label="Open navigation" aria-controls="dot-sidebar" aria-expanded="false"
            onclick="(function(b){var s=document.getElementById('dot-sidebar');var open=s.classList.toggle('is-open');b.setAttribute('aria-expanded',String(open));})(this)">
            <span class="material-symbols-rounded" style="font-size:18px;">menu</span>
        </button>
        <span class="statebar__lamp" aria-hidden="true"></span>
        <span class="statebar__state">{{ $state }}</span>
    </div>
    <div class="statebar__bay statebar__bay--grow">
        <span class="statebar__detail">
            {{ $detail }}
            @if($detailUrl && $level !== 'ok')
                <a href="{{ $detailUrl }}">Open control rooms</a>
            @endif
        </span>
    </div>
    <div class="statebar__bay">
        @if($currentShift)
        <a href="{{ route('shifts.show', $currentShift) }}" class="shiftpill shiftpill--open" title="Open shift">
            <span class="dot-status-lamp" style="background:var(--signal);margin:0;"></span>
            {{ $currentShift->label() }} · {{ $currentShift->status }}
        </a>
        @else
        <a href="{{ $startUrl ?? route('control-rooms.index') }}" class="shiftpill" title="No shift is open">
            <span class="dot-status-lamp" style="background:var(--text-faint);margin:0;"></span>
            No open shift — start one
        </a>
        @endif
    </div>
    <div class="statebar__bay">
        <span class="shiftpill" title="Dot.Mines data link">
            <span class="dot-status-lamp" style="margin:0;background:var(--{{ $dataLinkLevel === 'idle' ? 'text-faint' : $dataLinkLevel }});"></span>
            {{ $dataLink }}
        </span>
    </div>
    <div class="statebar__bay statebar__bay--user">
        <span class="statebar__clock" data-shell-clock title="Server time, UTC">{{ now('UTC')->format('Y-m-d H:i') }} UTC</span>
    </div>
    <div class="statebar__bay statebar__bay--tools">
        <button type="button" class="topbar-btn" title="Switch to day" aria-label="Switch between night and day display" onclick="window.dotToggleTheme(this)">
            <span class="material-symbols-rounded theme-icon-night">light_mode</span>
            <span class="material-symbols-rounded theme-icon-day">dark_mode</span>
            <span class="sr-only" data-theme-label>Switch to day</span>
        </button>
        <a href="{{ route('profile.show') }}" class="topbar-btn" title="Profile" aria-label="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
        @auth
        @livewire('notification-bell')
        @endauth
    </div>
</header>
