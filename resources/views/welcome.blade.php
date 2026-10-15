<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Central · Mining control room</title>
        <meta name="description" content="Dot.Central is the control-room operating system for a mining operation. It reads the fleet from Dot.Mines and turns it into shifts, line-ups, handovers, alerts and reports that operators act on.">

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

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
            body { margin: 0; font-family: var(--font-body); background: var(--ground); color: var(--text); line-height: 1.5; -webkit-font-smoothing: antialiased; }
            a { color: inherit; }
            .wrap { max-width: 1120px; margin: 0 auto; padding: 0 20px; }
            .topbar { border-bottom: 1px solid var(--rule); }
            .topbar .wrap { display: flex; align-items: center; justify-content: space-between; height: 64px; }
            .topbar nav { display: flex; align-items: center; gap: 22px; font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted); }
            .topbar nav a { text-decoration: none; }
            .topbar nav a:hover { color: var(--text); }
            .btn { display: inline-block; padding: 10px 18px; font-family: var(--font-display); font-weight: 600; font-size: 14px; text-decoration: none; border: 1px solid var(--rule-strong); color: var(--text); }
            .btn:hover { border-color: var(--text-muted); }
            .btn--signal { background: var(--signal); border-color: var(--signal); color: #15120a; }
            .btn--signal:hover { background: #f6b836; border-color: #f6b836; }
            .hero { padding: 72px 0 56px; border-bottom: 1px solid var(--rule); }
            .hero h1 { font-family: var(--font-display); font-weight: 700; font-size: clamp(34px, 5vw, 56px); line-height: 1.05; letter-spacing: -0.01em; margin: 0 0 20px; max-width: 14ch; }
            .hero p { font-size: 18px; color: var(--text-muted); max-width: 56ch; margin: 0 0 32px; }
            .hero .actions { display: flex; flex-wrap: wrap; gap: 12px; }
            .strip { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); border: 1px solid var(--rule); }
            @media (min-width: 760px) { .strip { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
            .strip > div { padding: 16px 18px; border-right: 1px solid var(--rule); border-bottom: 1px solid var(--rule); }
            .strip > div:nth-child(2n) { border-right: 0; }
            @media (min-width: 760px) { .strip > div { border-bottom: 0; } .strip > div:nth-child(2n) { border-right: 1px solid var(--rule); } .strip > div:last-child { border-right: 0; } }
            .strip .n { font-family: var(--font-display); font-size: 22px; font-weight: 700; line-height: 1.1; }
            .strip .l { font-family: var(--font-mono); font-size: 12px; color: var(--text-quiet); margin-top: 4px; }
            section { padding: 56px 0; border-bottom: 1px solid var(--rule); }
            h2 { font-family: var(--font-display); font-weight: 600; font-size: 28px; line-height: 1.15; margin: 0 0 8px; }
            .lede { color: var(--text-muted); max-width: 60ch; margin: 0 0 28px; }
            .steps { counter-reset: step; border-top: 1px solid var(--rule); }
            .steps > div { display: grid; grid-template-columns: 56px minmax(0, 1fr); gap: 16px; padding: 18px 0; border-bottom: 1px solid var(--rule); }
            .steps > div::before { counter-increment: step; content: counter(step, decimal-leading-zero); font-family: var(--font-mono); font-size: 14px; color: var(--signal); padding-top: 3px; }
            .steps h3 { font-family: var(--font-display); font-size: 18px; font-weight: 600; margin: 0 0 4px; }
            .steps p { margin: 0; color: var(--text-muted); max-width: 64ch; }
            .panels { display: grid; grid-template-columns: minmax(0, 1fr); border: 1px solid var(--rule); }
            @media (min-width: 760px) { .panels { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
            .panels > div { padding: 20px 18px; border-bottom: 1px solid var(--rule); }
            @media (min-width: 760px) { .panels > div { border-bottom: 0; border-right: 1px solid var(--rule); } .panels > div:last-child { border-right: 0; } }
            .panels h3 { font-family: var(--font-display); font-size: 17px; font-weight: 600; margin: 0 0 6px; }
            .panels p { margin: 0; color: var(--text-muted); font-size: 15px; }
            .ledger { width: 100%; border-collapse: collapse; font-size: 15px; }
            .ledger th { text-align: left; font-family: var(--font-mono); font-weight: 500; font-size: 12px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--text-quiet); padding: 10px 12px 10px 0; border-bottom: 1px solid var(--rule-strong); }
            .ledger td { padding: 12px 12px 12px 0; border-bottom: 1px solid var(--rule); vertical-align: top; color: var(--text-muted); }
            .ledger td:first-child { color: var(--text); font-family: var(--font-display); font-weight: 600; white-space: nowrap; }
            footer { padding: 28px 0 40px; }
            footer .wrap { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; font-family: var(--font-mono); font-size: 12px; color: var(--text-quiet); }
            footer nav { display: flex; gap: 18px; }
            footer a { text-decoration: none; }
            footer a:hover { color: var(--text); }
            .skip { position: absolute; left: -9999px; }
            .skip:focus { left: 16px; top: 16px; background: var(--signal); color: #15120a; padding: 8px 12px; z-index: 10; }
        </style>
    </head>
    <body>
        <a href="#main" class="skip">Skip to content</a>

        <header class="topbar">
            <div class="wrap">
                <a href="/" aria-label="Dot.Central home"><img src="{{ asset('images/logo-light.png') }}" alt="Dot.Central" style="height:44px;width:auto;display:block;"></a>
                <nav aria-label="Sections">
                    <a href="#shift">The shift</a>
                    <a href="#derived">What it reads</a>
                    <a href="#roles">Who does what</a>
                </nav>
                <div style="display:flex;align-items:center;gap:10px;">
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn btn--signal">Open the control room</a>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="btn">Sign in</a>
                        @endif
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn--signal">Create an account</a>
                        @endif
                    @endauth
                </div>
            </div>
        </header>

        <main id="main">
            <section class="hero">
                <div class="wrap">
                    <h1>The control room for a mining shift.</h1>
                    <p>Dot.Central reads the fleet from Dot.Mines and turns it into the work of a shift: who is on which machine, which machines can run, what stopped and why, what the next shift must know. Operators act on it. Nothing here edits a Dot.Mines fact.</p>
                    <div class="actions">
                        @auth
                            <a href="{{ route('dashboard') }}" class="btn btn--signal">Open the control room</a>
                        @else
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}" class="btn btn--signal">Sign in</a>
                            @endif
                            <a href="#shift" class="btn">How a shift runs</a>
                        @endauth
                    </div>
                </div>
            </section>

            <section id="shift">
                <div class="wrap">
                    <h2>One shift, in order</h2>
                    <p class="lede">The system follows the order a control room already works in. Each step only needs what the previous one produced.</p>
                    <div class="steps">
                        <div>
                            <div>
                                <h3>Start the shift</h3>
                                <p>Pick the shift type and the supervisor. Open issues from the previous shift are listed before anything else: machines down, units on standby, exceptions still unexplained.</p>
                            </div>
                        </div>
                        <div>
                            <div>
                                <h3>Build the line-up</h3>
                                <p>Faces come from the geofences or mine areas already configured. Loading units are placed, haul trucks are counted against the configured ratio, and the rest go to standby with a reason.</p>
                            </div>
                        </div>
                        <div>
                            <div>
                                <h3>Assign operators</h3>
                                <p>Every placed machine gets an operator. Assignments are pushed to Dot.Mines, which checks licences, medicals and inductions and refuses what it cannot accept. A refusal is shown, never hidden.</p>
                            </div>
                        </div>
                        <div>
                            <div>
                                <h3>Run and hand over</h3>
                                <p>The overview, availability board, stoppages, exceptions and alerts follow from the line-up. At the end, the handover carries the line-up, the open issues and the supervisor's notes to the next shift.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="derived">
                <div class="wrap">
                    <h2>Read from the fleet, not typed in</h2>
                    <p class="lede">Operators record decisions. Facts come from Dot.Mines on a schedule and through signed webhooks, and the room shows how fresh each one is.</p>
                    <div class="panels">
                        <div>
                            <h3>Availability</h3>
                            <p>A machine is down when Dot.Mines reports it offline or in maintenance, or when a stoppage has been classified as counting against availability. Missing telemetry is shown as unknown, not as stopped.</p>
                        </div>
                        <div>
                            <h3>Production and hours</h3>
                            <p>Loads, tonnes, cycle times and engine hours are read as the provider reports them, with the provider's timestamp. Thresholds for exceptions are set per control room.</p>
                        </div>
                        <div>
                            <h3>Alerts</h3>
                            <p>Alerts arrive from Dot.Mines. Acknowledging or resolving one here is recorded locally and pushed back, and the outcome of that push is part of the record.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="roles">
                <div class="wrap">
                    <h2>Who does what</h2>
                    <p class="lede">Four roles, each scoped to a control room. Every action lands in an append-only audit log with the actor, the shift and the reason.</p>
                    <table class="ledger">
                        <thead>
                            <tr><th scope="col">Role</th><th scope="col">Can</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Operator</td><td>Start and close shifts, build the line-up, classify stoppages, explain exceptions, acknowledge and resolve alerts, write handover notes.</td></tr>
                            <tr><td>Supervisor</td><td>Everything an operator can, plus approving exceptions and confirming handovers.</td></tr>
                            <tr><td>Manager</td><td>Reports and exports, shift types, downtime reasons and thresholds for the room.</td></tr>
                            <tr><td>Administrator</td><td>Rooms, members, the Dot.Mines connection and its health. Administrators configure; they do not operate.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <footer>
            <div class="wrap">
                <span>&copy; {{ date('Y') }} Dot.Central. Part of the Dot ecosystem.</span>
                <nav aria-label="Legal">
                    <a href="{{ route('policy.show') }}">Privacy</a>
                    <a href="{{ route('cookies') }}">Cookies</a>
                    <a href="{{ route('terms.show') }}">Terms</a>
                </nav>
            </div>
        </footer>
    </body>
</html>
