<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark dot-roboto">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Central</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    {{-- Theme: night is the default; a plain external script so the class is set before first paint and the page needs no inline script. --}}
    <script src="{{ Vite::asset('resources/js/theme-init.js') }}"></script>
    {{-- Shared Dot UI 0.6.1 (one stylesheet: typography, header, search, switcher; one script, the results controller loads lazily), served from this site's own public/dot-ui. Before the app CSS so the token mapping in app.css wins. --}}
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <x-banner />

    @php
        $navCounts = app(\App\View\Components\ShellStateBar::class);
        $shellUser = Auth::user();
        $shellTeam = $shellUser?->currentTeam;
        $shellAccount = $shellUser ? [
            'name' => $shellUser->name,
            'email' => $shellUser->email,
            'team' => $shellTeam ? $shellTeam->name.' · '.($shellUser->roleOn($shellTeam)?->label() ?? 'Member') : 'No team',
            'links' => array_values(array_filter([
                ['label' => 'Notifications', 'href' => route('notifications.index')],
                ['label' => 'Profile & settings', 'href' => route('profile.show'), 'current' => request()->routeIs('profile.show')],
                $shellTeam && Laravel\Jetstream\Jetstream::hasTeamFeatures() ? ['label' => 'Team & roles', 'href' => route('teams.show', $shellTeam), 'current' => request()->routeIs('teams.*')] : null,
            ])),
            'signOut' => ['action' => route('logout')],
        ] : null;
    @endphp

    {{-- One header: logo, the state of the room (lamp + word + counts), the search, the tools and the account. The sidebar below it is the
         module navigation; on a phone the header's menu button opens it (dot-ui adopt mode). --}}
    <x-dot.header id="dh" brand-href="{{ route('dashboard') }}" brand-label="Dot.Central, overview" :account="$shellAccount"
        drawer-target="dot-sidebar" menu-label="Open navigation" search-label="Search Dot.Central" skip="#main">
        <x-slot:brand>
            <img src="{{ asset('images/logo-light.png') }}" alt="" class="brand-mark brand-mark--night" width="45" height="34" />
            <img src="{{ asset('images/logo.png') }}" alt="" class="brand-mark brand-mark--day" width="45" height="34" />
            <span class="dh-brand__name">Dot.Central</span>
        </x-slot:brand>
        <x-slot:context>@include('components.shell-state-bar', ['bar' => $navCounts])</x-slot:context>
        @auth
        <x-slot:search><livewire:header-search /></x-slot:search>
        @endauth
        <x-slot:tools>
            @auth
            <livewire:dot-apps />
            @endauth
            <button type="button" class="dh-icon-btn dh-themebtn" data-theme-toggle title="Switch to day">
                <span class="material-symbols-rounded theme-icon-night" aria-hidden="true">light_mode</span>
                <span class="material-symbols-rounded theme-icon-day" aria-hidden="true">dark_mode</span>
                <span class="sr-only" data-theme-label>Switch to day</span>
            </button>
            @auth
            @livewire('notification-bell')
            @endauth
        </x-slot:tools>
    </x-dot.header>

    <aside class="sidebar" id="dot-sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/logo-light.png') }}" alt="" class="brand-mark brand-mark--night" width="40" height="30" />
            <img src="{{ asset('images/logo.png') }}" alt="" class="brand-mark brand-mark--day" width="40" height="30" />
            <div>
                <div class="brand-name">Dot.Central</div>
                <div class="brand-subtitle">Control room</div>
            </div>
            <button type="button" class="sidebar-close" data-dh-close aria-label="Close navigation"><span class="material-symbols-rounded" aria-hidden="true">close</span></button>
        </div>

        <nav class="sidebar-nav" aria-label="Main">
            <div class="nav-section-label">Operate</div>
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">space_dashboard</span>
                Overview
            </a>
            <a href="{{ route('control-rooms.index') }}" class="nav-item {{ request()->routeIs('control-rooms.*') && ! request()->routeIs('control-rooms.admin*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">settings_input_antenna</span>
                Control Rooms
            </a>
            <a href="{{ route('alerts.index') }}" class="nav-item {{ request()->routeIs('alerts.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">campaign</span>
                Alerts
            </a>
            <div class="nav-section-label">Control</div>
            <a href="{{ route('exceptions.index') }}" class="nav-item {{ request()->routeIs('exceptions.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">rule</span>
                Exceptions
                @if($navCounts->openExceptions > 0)<span class="nav-count nav-count--bad">{{ $navCounts->openExceptions }}</span>@endif
            </a>
            <a href="{{ route('approvals.index') }}" class="nav-item {{ request()->routeIs('approvals.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">fact_check</span>
                Approvals
                @if($navCounts->awaitingApproval > 0)<span class="nav-count nav-count--warn">{{ $navCounts->awaitingApproval }}</span>@endif
            </a>
            <a href="{{ route('quality.index') }}" class="nav-item {{ request()->routeIs('quality.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">verified</span>
                Data Quality
            </a>
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
            <div class="nav-section-label">Information</div>
            <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">summarize</span>
                Reports
            </a>
            <div class="nav-section-label">AI Agents</div>
            <a href="{{ route('agents.hub') }}" class="nav-item {{ request()->routeIs('agents.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">smart_toy</span>
                Agents
            </a>
            <div class="nav-account">
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
                <button type="button" class="nav-item nav-item--button nav-theme" data-theme-toggle title="Switch to day">
                    <span class="material-symbols-rounded nav-icon theme-icon-night">light_mode</span>
                    <span class="material-symbols-rounded nav-icon theme-icon-day">dark_mode</span>
                    <span data-theme-label>Switch to day</span>
                </button>
            </div>
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

    <div class="content-wrap">
        <main id="main">{{ $slot }}</main>
    </div>

    <x-shell-foot :bar="$navCounts" />

    @stack('modals')
    <script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" defer></script>
    @livewireScripts
</body>
</html>
