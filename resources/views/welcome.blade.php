<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Central — AI command centre for the Dot Ecosystem</title>
        <meta name="description" content="Build, configure, and converse with Claude-powered agents, then route work from other Dot platforms through them. One command centre for the ecosystem's AI capability.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        <!-- Alpine.js is not bundled into resources/js/app.js on this platform (unlike Mines);
             loaded the same way resources/views/layouts/app.blade.php already does it, so the
             mobile nav toggle (x-data) actually works on this standalone guest page. -->
        <script defer src="https://unpkg.com/alpinejs@3.10.2/dist/cdn.min.js"></script>

        <style>
            :root {
                --ink: #10141a;
                --ink-soft: #171d26;
                --panel: #1b222c;
                --gold: #e8bd3d;
                --gold-soft: #f3d878;
                --cyan: #22d3e0;
                --cyan-soft: #7be8f0;
                --paper: #edeff2;
                --mist: #97a1ad;
                --line: rgba(237, 239, 242, 0.10);
                --font-display: 'Sora', system-ui, sans-serif;
                --font-body: 'IBM Plex Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--ink); }
            body { font-family: var(--font-body); background: var(--ink); color: var(--paper); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(237, 239, 242, 0.03); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#10141a]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center gap-2.5 press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Central" class="h-16 sm:h-20 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--mist)]">
                    <a href="#agents" class="link-underline hover:text-[var(--paper)] pb-0.5">Agents</a>
                    <a href="#dispatch" class="link-underline hover:text-[var(--paper)] pb-0.5">Dispatch</a>
                    <a href="#platform" class="link-underline hover:text-[var(--paper)] pb-0.5">Platform</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#10141a] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--mist)] hover:text-[var(--paper)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#10141a] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Get started
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--paper)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[#10141a]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#agents" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Agents</a>
                    <a href="#dispatch" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Dispatch</a>
                    <a href="#platform" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Platform</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-center overflow-hidden">
            <div class="absolute inset-0" style="background: radial-gradient(ellipse 80% 60% at 8% 0%, rgba(232,189,61,0.10) 0%, transparent 55%), radial-gradient(ellipse 70% 50% at 100% 100%, rgba(34,211,224,0.08) 0%, transparent 55%);"></div>

            <!-- Signature: a routing diagram — other Dot platforms feeding into a central hub with the mark's own chevron,
                 echoing the real logo's toggle-switch icon and outward-pointing chevron. -->
            <svg class="hidden lg:block absolute right-[4%] top-1/2 -translate-y-1/2 h-[64%] w-auto opacity-[0.55] pointer-events-none" viewBox="0 0 360 420" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <!-- five source nodes, one per sibling platform -->
                <circle cx="30" cy="60" r="7" stroke="#97a1ad" stroke-width="2"/>
                <circle cx="30" cy="150" r="7" stroke="#97a1ad" stroke-width="2"/>
                <circle cx="30" cy="240" r="7" stroke="#97a1ad" stroke-width="2"/>
                <circle cx="30" cy="330" r="7" stroke="#97a1ad" stroke-width="2"/>
                <circle cx="30" cy="20" r="4" stroke="#97a1ad" stroke-width="1.5" opacity="0.5"/>
                <!-- converging routes -->
                <path d="M37 60 C 140 60, 140 195, 190 195" stroke="#97a1ad" stroke-width="1.5" opacity="0.55"/>
                <path d="M37 150 C 120 150, 140 195, 190 195" stroke="#97a1ad" stroke-width="1.5" opacity="0.55"/>
                <path d="M37 240 C 120 240, 140 205, 190 205" stroke="#97a1ad" stroke-width="1.5" opacity="0.55"/>
                <path d="M37 330 C 140 330, 140 205, 190 205" stroke="#97a1ad" stroke-width="1.5" opacity="0.55"/>
                <!-- the hub: switch-pair, echoing the logo icon -->
                <circle cx="220" cy="200" r="58" stroke="#e8bd3d" stroke-width="3"/>
                <rect x="188" y="172" width="64" height="24" rx="12" stroke="#edeff2" stroke-width="3"/>
                <circle cx="204" cy="184" r="7" fill="#edeff2"/>
                <rect x="188" y="204" width="64" height="24" rx="12" stroke="#edeff2" stroke-width="3"/>
                <circle cx="236" cy="216" r="7" fill="#edeff2"/>
                <!-- outbound chevron -->
                <path d="M300 150 L 350 200 L 300 250" stroke="#22d3e0" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-28 pb-16 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                        AI command centre — Dot Ecosystem
                    </p>

                    <h1 class="font-display font-bold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--paper)] mb-6">
                        Where the ecosystem's<br>work gets routed.
                    </h1>

                    <p class="text-lg text-[var(--mist)] leading-relaxed max-w-xl mb-10">
                        Dot.Central is where teams build, configure, and converse with specialised Claude-powered agents — then route work from other Dot platforms through them. A home base for the ecosystem's AI capability, not another silo.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#10141a] font-display font-semibold rounded-lg transition-colors">
                                Get started
                            </a>
                            <a href="#agents" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--mist)] transition-colors">
                                See what's inside
                            </a>
                        </div>
                    @endguest
                </div>
            </div>
        </section>

        <!-- Agents / AI command centre -->
        <section id="agents" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">Domain one — running today</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight">
                        Configure agents. Converse with them.
                    </h2>
                    <p class="text-[var(--mist)] leading-relaxed mt-4">
                        The AI command-centre scaffolding — agents, skills, conversations, usage tracking, and ecosystem SSO — is implemented and running.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $features = [
                            ['tag' => 'Agent', 'title' => 'Configurable agents', 'body' => 'Each agent is a Claude-backed persona with its own name, system prompt, model, and an open capabilities list you shape to the job.'],
                            ['tag' => 'Skill', 'title' => 'Agent skills', 'body' => 'Tag agents with skills — a many-to-many taxonomy that lets you group and discover agents by what they are actually good at.'],
                            ['tag' => 'Thread', 'title' => 'Conversations', 'body' => 'Every chat is a persisted thread between a user and an agent, with full message history replayed to Claude on each turn.'],
                            ['tag' => 'Log', 'title' => 'Usage tracking', 'body' => 'Every assistant turn logs input and output tokens per user and per agent — the foundation for cost visibility as usage grows.'],
                            ['tag' => 'Team', 'title' => 'Team-scoped access', 'body' => 'Conversations and usage logs are scoped per user; the mining-dispatch domain below is scoped per Jetstream team.'],
                            ['tag' => 'SSO', 'title' => 'Ecosystem SSO', 'body' => 'Sign in once through the InfoDot hub — Dot.Central authenticates via the same Sanctum-backed ecosystem handoff as every other Dot platform.'],
                        ];
                    @endphp
                    @foreach ($features as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--cyan)] mb-3">{{ $f['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--paper)] mb-2.5">{{ $f['title'] }}</h3>
                            <p class="text-[var(--mist)] leading-relaxed max-w-md">{{ $f['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Mining Dispatch (MVP scaffold) -->
        <!-- Deliberately no photo in the hero above: that section (and its copy) is domain one, the AI-agent command
             centre, which is abstract with no honest physical referent (wiki §1). This section is domain two, the
             mining-dispatch scaffold, which *does* have a genuine real-world equivalent per wiki §3a — an operations/
             dispatch control room with monitors and an operator, mirroring the actual Control Room / Alert / Operator
             Session entities described below. Putting the photo here, not the hero, is the point: it depicts the thing
             the copy right next to it is actually describing, rather than being generic tech-vibe decoration bolted
             onto unrelated copy — the mistake the 0.7.0 pass explicitly removed a control-room photo for repeating. -->
        <section id="dispatch" class="relative py-24 sm:py-28 px-5 sm:px-8 border-y border-[var(--line)] overflow-hidden">
            <!-- Photo: a man sitting in front of multiple monitors in a control room, by Tasha Kostyuk, unsplash.com/photos/a-man-sitting-in-front-of-multiple-monitors-TtMKq3lJm-U -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1708807472445-d33589e6b090?q=80&w=2400&auto=format&fit=crop');"></div>
            <!-- Overlay kept >=90% opaque ink everywhere text sits (computed against sRGB luminance: mist-on-ink is
                 ~7.05:1 at rest; even the worst case — a pure-white monitor pixel directly behind text at exactly 90%
                 ink opacity — computes to ~5.39:1, still clear of WCAG AA's 4.5:1 for body copy). Uniform top-to-bottom
                 wash rather than Mines' one-sided hero vignette, because both grid columns here carry real copy, not
                 just one side. -->
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(16,20,26,0.94) 0%, rgba(16,20,26,0.90) 45%, rgba(16,20,26,0.96) 100%);"></div>

            <div class="relative z-10 max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">Domain two — early-stage MVP</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                            A second domain: mining dispatch
                        </h2>
                        <p class="text-[var(--mist)] leading-relaxed max-w-sm">
                            Alongside the agent hub, Dot.Central hosts a data-model-and-CRUD scaffold for mining-dispatch operations: control rooms, dispatch decisions, alerts, and operator sessions. Honestly labelled — no event emission or Dot.Mines integration yet.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $dispatch = [
                                ['title' => 'Control rooms', 'body' => 'Team-scoped tenant root. Carries a mines_site_ref pointer to a Dot.Mines site — a plain string today, not a live integration.'],
                                ['title' => 'Dispatch decisions', 'body' => 'Assign, reroute, hold, or stagger — the four workflow types, recorded in sequence per control room.'],
                                ['title' => 'Alerts', 'body' => 'Severity-ranked threshold and sentinel trips, with a trigger and clear timestamp.'],
                                ['title' => 'Operator sessions', 'body' => 'Shift-level staffing context per control room — deliberately no individual performance fields.'],
                            ];
                        @endphp
                        @foreach ($dispatch as $c)
                            <div class="py-6 border-t border-[var(--line)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-[var(--paper)] mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[var(--mist)] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- Platform -->
        <section id="platform" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] gap-12 lg:gap-20 items-start">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">Under the hood</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                            Built on the InfoDot stack
                        </h2>
                        <p class="text-[var(--mist)] leading-relaxed max-w-sm">
                            Laravel 12, Jetstream teams, and a shared PostgreSQL instance across the ecosystem — with a direct line to Anthropic's Messages API for every agent turn.
                        </p>
                    </div>

                    <div class="rounded-xl border border-[var(--line)] bg-[var(--panel)] overflow-hidden reveal" data-reveal>
                        <div class="flex items-center gap-2 px-5 py-3 border-b border-[var(--line)]">
                            <span class="w-2.5 h-2.5 rounded-full bg-[var(--gold)]"></span>
                            <span class="font-mono text-[11px] tracking-wide text-[var(--mist)]">AgentChatService.php</span>
                        </div>
                        <pre class="font-mono text-[13px] leading-relaxed px-5 py-6 overflow-x-auto text-[var(--mist)]"><code><span class="text-[var(--cyan)]">POST</span> https://api.anthropic.com/v1/messages
  model:  claude-sonnet-4-6
  system: agent.system_prompt
  history: conversation.messages

<span class="text-[var(--gold)]"># no ANTHROPIC_API_KEY?</span>
<span class="text-[var(--mist)]"># falls back to a mock echo reply —</span>
<span class="text-[var(--mist)]"># dev-mode fallback, not a bug</span></code></pre>
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-px bg-[var(--line)] mt-14 rounded-xl overflow-hidden">
                    @foreach([
                        ['label' => 'Laravel 12 / PHP 8.4', 'desc' => 'Jetstream teams + Sanctum'],
                        ['label' => 'Livewire 3 + Alpine.js', 'desc' => 'Chat and dashboard UI'],
                        ['label' => 'PostgreSQL 16', 'desc' => 'Shared ecosystem instance'],
                        ['label' => 'Anthropic Claude', 'desc' => 'Direct Messages API integration'],
                    ] as $item)
                        <div class="bg-[var(--ink)] p-5 reveal" data-reveal>
                            <p class="font-display font-medium text-sm text-[var(--paper)] mb-1">{{ $item['label'] }}</p>
                            <p class="text-xs text-[var(--mist)]">{{ $item['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-32 px-5 sm:px-8 overflow-hidden border-t border-[var(--line)]">
            <div class="absolute inset-0" style="background: radial-gradient(ellipse 60% 80% at 50% 100%, rgba(232,189,61,0.08) 0%, transparent 60%);"></div>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                    Build your first agent
                </h2>
                <p class="text-[var(--mist)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Sign in once through the ecosystem hub, then configure an agent, tag it with skills, and start a conversation.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#10141a] font-display font-semibold rounded-lg transition-colors">
                            Get started
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--mist)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Central" class="h-11 w-auto opacity-90">
                </a>
                <p class="font-mono text-xs tracking-wide text-[var(--mist)]">
                    &copy; {{ date('Y') }} Dot.Central. AI command centre for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
