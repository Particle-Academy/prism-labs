<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" style="color-scheme: dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'Fancy') }}</title>

    {{-- Dark is not a preference here, it is the design. Every page renders on
         the kinetic surface, whose palette is a fixed dark one (--k-bg: #07070b),
         so `.dark` is set statically rather than read from localStorage or the OS.

         This is not cosmetic: react-fancy scopes its own component surfaces to
         `.dark`, so a light-mode visitor previously got white Fancy panels and
         inputs sitting inside a hard-dark page — the XP drawer and the Lab's
         form controls both. Asserting the class here keeps the component theme
         and the page theme from disagreeing. --}}

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
    @inertia

    {{-- Fancy Pixel: production-only visitor heuristics badge (Particle Academy). --}}
    @production
        @verbatim
        <script src="https://unpkg.com/@particle-academy/fancy-pixel/dist/fancy-pixel.global.min.js" data-site="gegxb2ehq3vl" data-style="badge" data-mode="floating" data-endpoint="https://ui.particle.academy/heuristics"></script>
        @endverbatim
    @endproduction
</body>
</html>
