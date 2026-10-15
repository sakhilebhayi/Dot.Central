<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Central</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script>
        // Applied before paint to avoid a flash of the wrong theme.
        (function () {
            var stored = localStorage.getItem('dot-central-theme');
            var theme = stored === 'light' || stored === 'dark' ? stored : 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <x-banner />

    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <img src="{{ asset('images/logo.png') }}" alt="Dot.Central" />
            </div>
            <div>
                <div class="brand-name">Dot.Central</div>
                <div class="brand-status">
                    <div class="live-dot"></div>
                    <span class="brand-subtitle">Control Room</span>
                </div>
            </div>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Operate</div>
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>
            <a href="{{ route('control-rooms.index') }}" class="nav-item {{ request()->routeIs('control-rooms.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">satellite_alt</span>
                Control Rooms
            </a>
            <div class="nav-section-label">Control</div>
            @can('viewAny', \App\Models\AuditEntry::class)
            <a href="{{ route('audit.index') }}" class="nav-item {{ request()->routeIs('audit.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">history</span>
                Audit Log
            </a>
            @else
            <span class="nav-item" style="color:var(--text-faint);cursor:default;" title="Supervisors, managers and administrators">
                <span class="material-symbols-rounded nav-icon">history</span>
                Audit Log
            </span>
            @endcan
            <div class="nav-section-label">AI Agents</div>
            <a href="{{ route('agents.index') }}" class="nav-item {{ request()->routeIs('agents.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">smart_toy</span>
                Agents
            </a>
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('notifications.index') }}" class="nav-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">notifications</span>
                Notifications
            </a>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
            <a href="{{ route('teams.show', Auth::user()->currentTeam) }}" class="nav-item {{ request()->routeIs('teams.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">groups</span>
                Team & Roles
            </a>
            @endif
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
            </div>
        </div>
        @endauth
    </aside>

    <header class="topbar">
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Central
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        @livewire('notification-bell')
        @endauth
        <button type="button" class="topbar-btn" title="Toggle dark / light mode" aria-label="Toggle dark or light mode"
            onclick="(function(){
                var html = document.documentElement;
                var next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                html.setAttribute('data-theme', next);
                localStorage.setItem('dot-central-theme', next);
            })()">
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        <a href="{{ route('profile.show') }}" class="topbar-btn" title="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
        <button type="submit" form="logout-form" class="topbar-btn" title="Log out" aria-label="Log out">
            <span class="material-symbols-rounded">logout</span>
        </button>
    </header>

    <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none;">@csrf</form>

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
