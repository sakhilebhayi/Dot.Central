<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Dot.Central') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts: Roboto, self-hosted (dot-ui), as in the authenticated shell -->
        <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        <style>
            :root {
                --ink: #10141a;
                --ink-soft: #171d26;
                --panel: #1b222c;
                --gold: #e8bd3d;
                --gold-soft: #f3d878;
                --paper: #edeff2;
                --mist: #97a1ad;
                --line: rgba(237, 239, 242, 0.12);
                --rule: rgba(237, 239, 242, 0.12);
                --font-display: var(--dot-font-sans);
                --font-body: var(--dot-font-sans);
                --font-mono: var(--dot-font-mono);
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--ink); }
            body { font-family: var(--font-body); background: var(--ink); color: var(--paper); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            [x-cloak] { display: none; }
        </style>
    </head>
    <body class="antialiased">
        {{ $slot }}

        @livewireScripts
    </body>
</html>
