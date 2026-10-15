<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Central</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script>
        // `.dark` on <html> means NIGHT, and night is the default: a control
        // room is read in a dim room far more often than a bright one. A
        // stored preference wins. Applied before first paint.
        (function () {
            var stored = null;
            try { stored = localStorage.getItem('dot-central-theme'); } catch (e) {}
            if (stored === 'light') { document.documentElement.classList.remove('dark'); }
        })();
        window.dotToggleTheme = function (button) {
            var night = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('dot-central-theme', night ? 'dark' : 'light'); } catch (e) {}
            var label = night ? 'Switch to day' : 'Switch to night';
            var text = button.querySelector('[data-theme-label]'); if (text) { text.textContent = label; }
            button.title = label;
        };
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <a href="#main" class="skip-link">Skip to content</a>
    <x-banner />

    <aside class="sidebar" id="dot-sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/logo-light.png') }}" alt="" class="brand-mark brand-mark--night" />
            <img src="{{ asset('images/logo.png') }}" alt="" class="brand-mark brand-mark--day" />
            <div>
                <div class="brand-name">Dot.Central</div>
                <div class="brand-subtitle">Control room</div>
            </div>
        </div>

        <nav class="sidebar-nav" aria-label="Main">
            <div class="nav-section-label">Operate</div>
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">space_dashboard</span>
                Overview
            </a>
            <a href="{{ route('control-rooms.index') }}" class="nav-item {{ request()->routeIs('control-rooms.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">settings_input_antenna</span>
                Control Rooms
            </a>
            <div class="nav-section-label">Control</div>
            @can('viewAny', \App\Models\AuditEntry::class)
            <a href="{{ route('audit.index') }}" class="nav-item {{ request()->routeIs('audit.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">history</span>
                Audit Log
            </a>
            @else
            <span class="nav-item nav-item--locked" title="Supervisors, managers and administrators">
                <span class="material-symbols-rounded nav-icon">history</span>
                Audit Log
            </span>
            @endcan
            @can('view-health')
            <a href="{{ route('health.index') }}" class="nav-item {{ request()->routeIs('health.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">monitor_heart</span>
                Health
            </a>
            @endcan
            <div class="nav-section-label">AI Agents</div>
            <a href="{{ route('agents.index') }}" class="nav-item {{ request()->routeIs('agents.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">smart_toy</span>
                Agents
            </a>
            <div class="sidebar-divider"></div>
            <a href="{{ route('notifications.index') }}" class="nav-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">notifications</span>
                Notifications
            </a>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile &amp; Settings
            </a>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
            <a href="{{ route('teams.show', Auth::user()->currentTeam) }}" class="nav-item {{ request()->routeIs('teams.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">groups</span>
                Team &amp; Roles
            </a>
            @endif
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-avatar" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
            <div class="user-row__text">
                <div class="user-name">{{ Auth::user()->name }}</div>
                <div class="user-team">{{ Auth::user()->currentTeam?->name ?? 'No team' }}{{ Auth::user()->currentTeam ? ' · '.(Auth::user()->roleOn(Auth::user()->currentTeam)?->label() ?? 'Member') : '' }}</div>
            </div>
            <form id="logout-form" method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="user-signout" title="Log out" aria-label="Log out">
                    <span class="material-symbols-rounded">logout</span>
                </button>
            </form>
        </div>
        @endauth
    </aside>

    <x-shell-state-bar />

    <div class="content-wrap">
        <main id="main">{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
