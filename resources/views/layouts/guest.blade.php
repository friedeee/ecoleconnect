<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }} · ÉcoleConnect</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-white font-sans text-zinc-900 antialiased">
    <div class="grid min-h-screen lg:grid-cols-2">
        <aside class="relative hidden overflow-hidden bg-brand-950 p-12 text-white lg:flex lg:flex-col">
            <div class="absolute inset-0 bg-[radial-gradient(80%_60%_at_100%_0%,var(--color-brand-600),transparent_70%)] opacity-60"></div>
            <div class="absolute -bottom-24 -left-24 size-96 rounded-full bg-gold-500/15 blur-3xl"></div>
            <svg class="absolute inset-0 size-full opacity-[0.07]" aria-hidden="true"><defs><pattern id="dots" width="24" height="24" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.5" fill="white" /></pattern></defs><rect width="100%" height="100%" fill="url(#dots)" /></svg>

            <div class="relative flex items-center gap-3">
                <div class="flex size-11 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 shadow-lg"><x-ui.icon name="school" class="size-6" /></div>
                <span class="text-lg font-semibold">ÉcoleConnect</span>
            </div>

            <div class="relative mt-auto max-w-md">
                <h2 class="text-4xl leading-tight font-semibold tracking-tight">La gestion de votre école, <span class="text-gold-400">enfin simple.</span></h2>
                <p class="mt-4 text-brand-200">De la maternelle au lycée : inscriptions, notes et bulletins, frais de scolarité et communication avec les parents, au même endroit.</p>

                <ul class="mt-10 space-y-4 text-sm">
                    @foreach ([
                        ['users', 'Dossiers élèves et parents toujours à jour'],
                        ['grades', 'Bulletins calculés automatiquement'],
                        ['banknotes', 'Suivi des paiements et des impayés'],
                        ['chat', 'Les parents informés par SMS et WhatsApp'],
                    ] as [$icon, $text])
                        <li class="flex items-center gap-3">
                            <span class="flex size-9 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15"><x-ui.icon :name="$icon" class="size-4.5 text-gold-400" /></span>
                            {{ $text }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="relative mt-12 text-xs text-brand-300">Conçu au Bénin, pour les écoles d'Afrique de l'Ouest.</p>
        </aside>

        <main class="flex items-center justify-center px-4 py-12 sm:px-8">
            <div class="w-full max-w-sm">
                <div class="mb-10 flex items-center gap-3 lg:hidden">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-brand-600 text-white"><x-ui.icon name="school" /></div>
                    <span class="text-lg font-semibold">ÉcoleConnect</span>
                </div>
                {{ $slot }}
            </div>
        </main>
    </div>
    @livewireScripts
</body>
</html>
