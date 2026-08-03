<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Central — AI Command Centre for the Dot Ecosystem</title>
        <meta name="description" content="Build, configure, and converse with specialised Claude-powered agents, then route work from other Dot platforms through them.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-slate-950 text-gray-100 antialiased">

        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" x-data="{ scrolled: false, mobileMenuOpen: false }"
                @scroll.window="scrolled = window.pageYOffset > 50"
                :class="scrolled ? 'bg-slate-950/95 backdrop-blur-xl shadow-lg border-b border-slate-800' : 'bg-transparent'">
            <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <a href="/" class="flex items-center gap-3 group">
                        <img src="{{ asset('images/logo.png') }}" alt="Dot.Central" class="h-14 w-auto transform group-hover:scale-105 transition-transform duration-300">
                        <p class="hidden sm:block text-xs text-violet-400 font-medium border-l border-slate-700 pl-3">AI Command Centre</p>
                    </a>

                    <div class="hidden md:flex items-center gap-8">
                        <a href="#features" class="text-gray-300 hover:text-violet-400 transition-colors font-medium">Features</a>
                        <a href="#dispatch" class="text-gray-300 hover:text-violet-400 transition-colors font-medium">Mining Dispatch</a>
                        <a href="#platform" class="text-gray-300 hover:text-violet-400 transition-colors font-medium">Platform</a>
                    </div>

                    @if (Route::has('login'))
                        <div class="flex items-center gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="hidden sm:flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-violet-500 to-violet-600 hover:from-violet-600 hover:to-violet-700 text-white font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-violet-500/30 transform hover:scale-105">
                                    <span>Dashboard</span>
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="hidden sm:block px-4 py-2 text-gray-300 hover:text-white transition-colors font-medium">Sign In</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-violet-500 to-violet-600 hover:from-violet-600 hover:to-violet-700 text-white font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-violet-500/30 transform hover:scale-105">
                                        <span>Get Started</span>
                                    </a>
                                @endif
                            @endauth
                            <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 text-gray-400 hover:text-white">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                                    <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>

                <div x-show="mobileMenuOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform scale-95"
                     x-transition:enter-end="opacity-100 transform scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 transform scale-100"
                     x-transition:leave-end="opacity-0 transform scale-95"
                     class="md:hidden mt-4 py-4 border-t border-slate-800"
                     style="display: none;">
                    <div class="flex flex-col gap-2">
                        <a href="#features" class="px-4 py-2 text-gray-300 hover:text-violet-400 hover:bg-slate-800 rounded-lg transition-colors">Features</a>
                        <a href="#dispatch" class="px-4 py-2 text-gray-300 hover:text-violet-400 hover:bg-slate-800 rounded-lg transition-colors">Mining Dispatch</a>
                        @guest
                            <a href="{{ route('login') }}" class="px-4 py-2 text-gray-300 hover:text-violet-400 hover:bg-slate-800 rounded-lg transition-colors">Sign In</a>
                        @endguest
                    </div>
                </div>
            </nav>
        </header>

        <!-- Hero -->
        <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">
            <!-- Photographic Background: real control-room-displays photo by Noah Gremmert (@noahgremmert1), unsplash.com/photos/control-room-displays-ready-for-use-Sp3YykrgnWs -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1753153481105-7a979eabe5a9?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/85 to-slate-950/60"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-slate-950 via-slate-950/40 to-transparent"></div>

            <div class="relative z-10 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-violet-500/10 border border-violet-500/20 rounded-full text-violet-400 text-sm font-medium mb-8">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-violet-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-violet-500"></span>
                    </span>
                    <span>Powered by Anthropic Claude</span>
                </div>

                <h1 class="text-5xl lg:text-7xl font-bold leading-tight mb-6">
                    <span class="bg-gradient-to-r from-white via-gray-100 to-gray-300 bg-clip-text text-transparent">Your AI</span><br>
                    <span class="bg-gradient-to-r from-violet-400 via-violet-500 to-indigo-400 bg-clip-text text-transparent">Command Centre</span>
                </h1>

                <p class="text-xl text-gray-400 leading-relaxed max-w-2xl mx-auto mb-10">
                    Dot.Central is where teams build, configure, and converse with specialised Claude-powered agents, then route work from other Dot platforms through them — a home base for the ecosystem's AI capability.
                </p>

                @guest
                    <div class="flex flex-wrap items-center justify-center gap-4">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-violet-500 to-violet-600 hover:from-violet-600 hover:to-violet-700 text-white font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-violet-500/30 transform hover:scale-105">
                            <span>Get Started</span>
                        </a>
                        <a href="#features" class="flex items-center gap-2 px-8 py-4 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl transition-all duration-300 border border-slate-700 hover:border-slate-600">
                            <span>See What's Inside</span>
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-slate-900/50 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-violet-500/5 to-transparent"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-violet-500/10 border border-violet-500/20 rounded-full text-violet-400 text-sm font-medium mb-6">
                        <span>Core Features</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Configure Agents.<br>
                        <span class="bg-gradient-to-r from-violet-400 to-indigo-400 bg-clip-text text-transparent">Converse With Them.</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">The AI command-centre scaffolding — agents, skills, conversations, messages, usage tracking, and ecosystem SSO — is implemented and running today.</p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    @foreach([
                        ['title' => 'Configurable Agents', 'desc' => 'Each agent is a Claude-backed persona with its own name, system prompt, model, and an open capabilities list you shape to the job.', 'color' => 'from-violet-500 to-violet-600'],
                        ['title' => 'Agent Skills', 'desc' => 'Tag agents with skills — a many-to-many taxonomy that lets you group and discover agents by what they are actually good at.', 'color' => 'from-indigo-500 to-indigo-600'],
                        ['title' => 'Conversations', 'desc' => 'Every chat is a persisted thread between a user and an agent, with full message history replayed on each turn.', 'color' => 'from-blue-500 to-blue-600'],
                        ['title' => 'Usage Tracking', 'desc' => 'Every assistant turn logs input and output tokens per user and per agent — the foundation for cost visibility as usage grows.', 'color' => 'from-emerald-500 to-emerald-600'],
                        ['title' => 'Team-Scoped Access', 'desc' => 'Agents, conversations, and usage all live inside Jetstream teams, sharing the same tenancy model as the rest of the ecosystem.', 'color' => 'from-amber-500 to-amber-600'],
                        ['title' => 'Ecosystem SSO', 'desc' => 'Sign in once through the InfoDot hub — Dot.Central authenticates via the same Sanctum-backed ecosystem handoff as every other Dot platform.', 'color' => 'from-pink-500 to-pink-600'],
                    ] as $f)
                        <div class="group bg-gradient-to-br from-slate-800 to-slate-900 p-8 rounded-2xl border border-slate-700 hover:border-violet-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-violet-500/10 transform hover:-translate-y-2">
                            <div class="w-14 h-14 bg-gradient-to-br {{ $f['color'] }} rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-white mb-3 group-hover:text-violet-400 transition-colors">{{ $f['title'] }}</h3>
                            <p class="text-gray-400 leading-relaxed">{{ $f['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Mining Dispatch (MVP scaffold) -->
        <section id="dispatch" class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950"></div>

            <div class="relative z-10 max-w-5xl mx-auto">
                <div class="text-center mb-14">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-indigo-400 text-sm font-medium mb-6">
                        <span>Early-Stage MVP</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">A Second Domain: <span class="bg-gradient-to-r from-indigo-400 to-violet-400 bg-clip-text text-transparent">Mining Dispatch</span></h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">Alongside the AI command centre, Dot.Central hosts an operational-intelligence data model for mining dispatch — control rooms, dispatch decisions, alerts, and operator sessions. This is a data-model-and-CRUD scaffold today, not a finished operations product.</p>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach([
                        ['label' => 'Control Rooms', 'desc' => 'Team-scoped tenant root, pointing at a Dot.Mines site'],
                        ['label' => 'Dispatch Decisions', 'desc' => 'Assign, reroute, hold, or stagger — logged in sequence'],
                        ['label' => 'Alerts', 'desc' => 'Severity-ranked threshold and sentinel trips'],
                        ['label' => 'Operator Sessions', 'desc' => 'Shift-level staffing context per control room'],
                    ] as $item)
                        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 text-center">
                            <p class="font-bold text-sm text-white mb-1">{{ $item['label'] }}</p>
                            <p class="text-xs text-gray-400">{{ $item['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Platform -->
        <section id="platform" class="py-24 px-4 sm:px-6 lg:px-8 bg-slate-900/50">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-14">
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">Built on the <span class="bg-gradient-to-r from-violet-400 to-indigo-400 bg-clip-text text-transparent">InfoDot Stack</span></h2>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach([
                        ['label' => 'Laravel 12 / PHP 8.4', 'desc' => 'Jetstream teams + Sanctum'],
                        ['label' => 'Livewire 3 + Alpine.js', 'desc' => 'Chat and dashboard UI'],
                        ['label' => 'PostgreSQL 16', 'desc' => 'Shared ecosystem instance'],
                        ['label' => 'Anthropic Claude', 'desc' => 'Direct Messages API integration'],
                    ] as $item)
                        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 text-center">
                            <p class="font-bold text-sm text-white mb-1">{{ $item['label'] }}</p>
                            <p class="text-xs text-gray-400">{{ $item['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-slate-950 via-violet-950/30 to-slate-950"></div>
            <div class="relative z-10 max-w-4xl mx-auto text-center">
                <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                    Ready to Build Your <span class="bg-gradient-to-r from-violet-400 to-indigo-400 bg-clip-text text-transparent">First Agent?</span>
                </h2>
                <p class="text-xl text-gray-400 mb-10 max-w-2xl mx-auto">Get started with Dot.Central.</p>
                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-10 py-4 bg-gradient-to-r from-violet-500 to-violet-600 hover:from-violet-600 hover:to-violet-700 text-white font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-violet-500/30 transform hover:scale-105">
                            <span>Get Started</span>
                        </a>
                        <a href="{{ route('login') }}" class="flex items-center gap-2 px-10 py-4 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl transition-all duration-300 border border-slate-700 hover:border-slate-600">
                            <span>Sign In</span>
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-slate-800 bg-slate-900/50">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Central" class="h-12 w-auto">
                    <p class="text-gray-400 text-sm">&copy; {{ date('Y') }} Dot.Central. Part of the Dot Ecosystem.</p>
                </div>
            </div>
        </footer>

    </body>
</html>
