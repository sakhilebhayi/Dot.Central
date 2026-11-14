<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $code }} · @yield('title') · Dot.Central</title>
        <meta name="robots" content="noindex">

        @php
            $faviconPath = null;
            foreach (['favicon.ico', 'favicon-32x32.png', 'favicon-16x16.png'] as $faviconCandidate) {
                if (file_exists(public_path($faviconCandidate))) {
                    $faviconPath = $faviconCandidate;
                    break;
                }
            }
            $logoPath = file_exists(public_path('images/logo-light.png')) ? 'images/logo-light.png' : 'images/logo.png';
            $logoDayPath = 'images/logo.png';
        @endphp
        @if ($faviconPath)
            <link rel="icon" href="{{ asset($faviconPath) }}">
        @endif

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        {{-- No Vite bundle on purpose: an error page must render even when the build is missing. --}}
        <script>try { if (localStorage.getItem('dot-central-theme') === 'light') { document.documentElement.setAttribute('data-theme', 'light'); } } catch (e) {}</script>
        <style>
            :root {
                --ground: #0f141b;
                --panel: #151b24;
                --rule: rgba(237, 239, 242, 0.12);
                --rule-strong: rgba(237, 239, 242, 0.22);
                --text: #e9ecf0;
                --text-muted: #a6afba;
                --text-quiet: #7f8995;
                --signal: #f2a70b;
                --on-signal: #15120a;
                --font-display: 'IBM Plex Sans Condensed', 'IBM Plex Sans', system-ui, sans-serif;
                --font-body: 'IBM Plex Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, Menlo, monospace;
            }
            /* Day, the same values as the app shell (app.css). The app stores the choice under dot-central-theme. */
            :root[data-theme='light'] {
                --ground: #f4f6f8; --panel: #ffffff;
                --rule: rgba(15, 20, 28, 0.12); --rule-strong: rgba(15, 20, 28, 0.22);
                --text: #10151c; --text-muted: #46505c; --text-quiet: #525c68;
                --signal: #8a5c07; --on-signal: #ffffff;
            }
            :root[data-theme='light'] .logo-night { display: none !important; }
            :root[data-theme='light'] .logo-day { display: block !important; }
            .card__head .icon { color: var(--signal); }
            * { box-sizing: border-box; }
            html { background: var(--ground); }
            body { margin: 0; font-family: var(--font-body); background: var(--ground); color: var(--text); min-height: 100dvh; display: flex; flex-direction: column; -webkit-font-smoothing: antialiased; }
            a { color: inherit; }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }
            .wrap { max-width: 1120px; margin: 0 auto; padding: 0 20px; width: 100%; }
            header { border-bottom: 1px solid var(--rule); }
            header .wrap { display: flex; align-items: center; justify-content: space-between; height: 64px; }
            .btn { display: inline-block; padding: 10px 18px; font-family: var(--font-display); font-weight: 600; font-size: 14px; text-decoration: none; border: 1px solid var(--rule-strong); color: var(--text); }
            .btn:hover { border-color: var(--text-muted); }
            .press { padding: 10px 18px; font-family: var(--font-display); font-weight: 600; font-size: 14px; text-decoration: none; border: 1px solid var(--rule-strong); color: var(--text); display: inline-block; }
            .press[style*="var(--accent)"] { border-color: var(--signal); }
            .link-underline { text-decoration: none; }
            .link-underline:hover { text-decoration: underline; }
            main { flex: 1; display: flex; align-items: center; padding: 56px 0; }
            .card { border: 1px solid var(--rule); background: var(--panel); max-width: 640px; width: 100%; min-width: 0; }
            .wrap { min-width: 0; }
            .card__head { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-bottom: 1px solid var(--rule); }
            .card__head .icon { display: inline-flex; width: 36px; height: 36px; align-items: center; justify-content: center; }
            .card__head .icon svg { width: 28px; height: 28px; }
            .card__code { font-family: var(--font-mono); font-size: 13px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-quiet); }
            .card__body { padding: 22px 20px 24px; }
            .card__body h1 { font-family: var(--font-display); font-size: 28px; font-weight: 700; line-height: 1.15; margin: 0 0 10px; text-wrap: balance; overflow-wrap: anywhere; }
            .card__body p { max-width: 62ch; margin: 0 0 24px; color: var(--text-muted); font-size: 16px; line-height: 1.55; }
            .card__actions { display: flex; flex-wrap: wrap; gap: 10px; }
            .others { margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--rule); }
            .others h2 { font-family: var(--font-mono); font-size: 12px; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-quiet); margin: 0 0 10px; }
            .others ul { list-style: none; margin: 0; padding: 0; }
            .others li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--rule); font-size: 15px; }
            .others li:last-child { border-bottom: 0; }
            .others button, .signout { background: none; cursor: pointer; font: inherit; }
            .others button { padding: 7px 14px; border: 1px solid var(--rule-strong); color: var(--text); font-family: var(--font-display); font-weight: 600; font-size: 14px; }
            .others button:hover { border-color: var(--text-muted); }
            .signout { border: 0; color: var(--text-muted); font-size: 14px; padding: 8px 4px; text-decoration: underline; text-underline-offset: 3px; }
            .press:focus-visible, .btn:focus-visible, .others button:focus-visible, .signout:focus-visible { outline: 2px solid var(--signal); outline-offset: 2px; }
            footer { border-top: 1px solid var(--rule); padding: 20px 0; }
            footer .wrap { display: flex; flex-wrap: wrap; gap: 16px; font-family: var(--font-mono); font-size: 12px; color: var(--text-quiet); }
        </style>
    </head>
    <body>
        <header>
            <div class="wrap">
                <a href="/" aria-label="Dot.Central home"><img class="logo-night" src="{{ asset($logoPath) }}" alt="Dot.Central" style="height: 40px; width: auto; display: block;"><img class="logo-day" src="{{ asset($logoDayPath) }}" alt="Dot.Central" style="height: 40px; width: auto; display: none;"></a>
                @hasSection('header_action')
                    @yield('header_action')
                @else
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn">Open the control room</a>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="btn">Sign in</a>
                        @endif
                    @endauth
                @endif
            </div>
        </header>

        <main>
            <div class="wrap">
                <div class="card">
                    <div class="card__head">
                        <span class="icon" aria-hidden="true">@yield('icon')</span>
                        <span class="card__code">@hasSection('code_label')@yield('code_label')@else Error {{ $code }}@endif</span>
                    </div>
                    <div class="card__body">
                        <h1>@yield('title')</h1>
                        <p>@yield('message')</p>
                        <div class="card__actions">
                            @yield('actions')
                        </div>
                        @yield('after')
                    </div>
                </div>
            </div>
        </main>

        <footer>
            <div class="wrap">
                <span>&copy; {{ date('Y') }} Dot.Central.</span>
                @if (Route::has('contact'))
                    <a href="{{ route('contact') }}" class="link-underline">Contact support</a>
                @endif
            </div>
        </footer>
    </body>
</html>
