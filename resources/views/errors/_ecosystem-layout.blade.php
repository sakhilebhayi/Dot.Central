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
        @endphp
        @if ($faviconPath)
            <link rel="icon" href="{{ asset($faviconPath) }}">
        @endif

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        {{-- No Vite bundle on purpose: an error page must render even when the build is missing. --}}
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
                --font-display: 'IBM Plex Sans Condensed', 'IBM Plex Sans', system-ui, sans-serif;
                --font-body: 'IBM Plex Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, Menlo, monospace;
            }
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
            .card { border: 1px solid var(--rule); background: var(--panel); max-width: 640px; width: 100%; }
            .card__head { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-bottom: 1px solid var(--rule); }
            .card__head .icon { display: inline-flex; width: 36px; height: 36px; align-items: center; justify-content: center; }
            .card__head .icon svg { width: 28px; height: 28px; }
            .card__code { font-family: var(--font-mono); font-size: 13px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-quiet); }
            .card__body { padding: 22px 20px 24px; }
            .card__body h1 { font-family: var(--font-display); font-size: 28px; font-weight: 700; line-height: 1.15; margin: 0 0 10px; }
            .card__body p { margin: 0 0 24px; color: var(--text-muted); font-size: 16px; line-height: 1.55; }
            .card__actions { display: flex; flex-wrap: wrap; gap: 10px; }
            footer { border-top: 1px solid var(--rule); padding: 20px 0; }
            footer .wrap { display: flex; flex-wrap: wrap; gap: 16px; font-family: var(--font-mono); font-size: 12px; color: var(--text-quiet); }
        </style>
    </head>
    <body>
        <header>
            <div class="wrap">
                <a href="/" aria-label="Dot.Central home"><img src="{{ asset($logoPath) }}" alt="Dot.Central" style="height: 40px; width: auto; display: block;"></a>
                @auth
                    <a href="{{ route('dashboard') }}" class="btn">Open the control room</a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="btn">Sign in</a>
                    @endif
                @endauth
            </div>
        </header>

        <main>
            <div class="wrap">
                <div class="card">
                    <div class="card__head">
                        <span class="icon" aria-hidden="true">@yield('icon')</span>
                        <span class="card__code">Error {{ $code }}</span>
                    </div>
                    <div class="card__body">
                        <h1>@yield('title')</h1>
                        <p>@yield('message')</p>
                        <div class="card__actions">
                            @yield('actions')
                        </div>
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
