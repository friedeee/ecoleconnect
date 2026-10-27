<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }} · ÉcoleConnect</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-50 font-sans text-zinc-900 antialiased">
    <div class="grid min-h-screen lg:grid-cols-[1.1fr_1fr]">
        {{-- Presentation, on large screens. --}}
        <aside class="relative hidden overflow-hidden bg-brand-950 p-12 text-white lg:flex lg:flex-col xl:p-16">
            <div class="absolute inset-0 bg-[radial-gradient(80%_60%_at_100%_0%,var(--color-brand-600),transparent_70%)] opacity-60"></div>
            <div class="absolute -bottom-24 -left-24 size-96 rounded-full bg-gold-500/15 blur-3xl"></div>
            <svg class="absolute inset-0 size-full opacity-[0.07]" aria-hidden="true"><defs><pattern id="dots" width="24" height="24" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.5" fill="white" /></pattern></defs><rect width="100%" height="100%" fill="url(#dots)" /></svg>

            <div class="relative flex items-center gap-3">
                <div class="flex size-11 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 shadow-lg shadow-brand-950/50"><x-ui.icon name="school" class="size-6" /></div>
                <span class="text-lg font-semibold tracking-tight">ÉcoleConnect</span>
            </div>

            <div class="relative mt-16 max-w-lg">
                <h2 class="text-4xl leading-tight font-semibold tracking-tight xl:text-5xl">La gestion de votre école, <span class="text-gold-400">enfin simple.</span></h2>
                <p class="mt-5 text-lg text-brand-200">De la maternelle au lycée : inscriptions, notes et bulletins, absences, frais de scolarité et espace parents, au même endroit.</p>
            </div>

            {{-- A glimpse of the product: decorative only. --}}
            <div class="relative mt-12 max-w-md" aria-hidden="true">
                <div class="rounded-2xl bg-white/10 p-5 ring-1 ring-white/15 backdrop-blur-md">
                    <div class="flex items-center justify-between text-xs text-brand-200">
                        <span>Bulletin · 1er trimestre</span>
                        <span class="rounded-full bg-gold-400/20 px-2 py-0.5 font-medium text-gold-400">Bien</span>
                    </div>
                    <div class="mt-3 flex items-end justify-between">
                        <div>
                            <p class="text-3xl font-semibold tabular-nums">14,25<span class="text-base font-normal text-brand-300"> / 20</span></p>
                            <p class="mt-0.5 text-sm text-brand-200">3e sur 42 élèves</p>
                        </div>
                        <div class="flex h-12 items-end gap-1.5">
                            @foreach ([55, 70, 45, 85, 65, 90, 75] as $height)
                                <span class="w-2.5 rounded-t bg-gradient-to-t from-brand-500 to-brand-300" style="height: {{ $height }}%"></span>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="-mt-3 ml-10 flex items-center gap-3 rounded-2xl bg-white p-4 text-zinc-900 shadow-2xl shadow-brand-950/40">
                    <span class="flex size-10 items-center justify-center rounded-full bg-brand-50 text-brand-600"><x-ui.icon name="check" class="size-5" /></span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">Paiement reçu · 35 000 F CFA</p>
                        <p class="text-xs text-zinc-500">Reçu N° 2026-00128 envoyé au parent</p>
                    </div>
                </div>
            </div>

            <ul class="relative mt-auto grid max-w-lg grid-cols-2 gap-x-6 gap-y-4 pt-12 text-sm">
                @foreach ([
                    ['grades', 'Bulletins calculés automatiquement'],
                    ['banknotes', 'Paiements, reçus et impayés'],
                    ['clock', 'Appel et absences en un clic'],
                    ['users', 'Espace parents sur téléphone'],
                ] as [$icon, $text])
                    <li class="flex items-center gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15"><x-ui.icon :name="$icon" class="size-4.5 text-gold-400" /></span>
                        <span class="text-brand-100">{{ $text }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="relative mt-10 text-xs text-brand-300">Conçu au Bénin, pour les écoles d'Afrique de l'Ouest.</p>
        </aside>

        <main class="relative flex flex-col">
            {{-- Brand band on phones and tablets, where the presentation panel is hidden. --}}
            <div class="relative overflow-hidden bg-brand-950 px-6 pt-8 pb-20 text-white lg:hidden">
                <div class="absolute inset-0 bg-[radial-gradient(80%_80%_at_100%_0%,var(--color-brand-600),transparent_70%)] opacity-60"></div>
                <div class="relative mx-auto flex max-w-md items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600"><x-ui.icon name="school" class="size-5" /></div>
                    <div>
                        <p class="text-lg font-semibold tracking-tight">ÉcoleConnect</p>
                        <p class="text-xs text-brand-300">La gestion de votre école, <span class="text-gold-400">enfin simple.</span></p>
                    </div>
                </div>
            </div>

            <div class="pointer-events-none absolute inset-0 hidden bg-[radial-gradient(60%_50%_at_50%_0%,var(--color-brand-100),transparent_70%)] opacity-60 lg:block"></div>

            <div class="relative -mt-14 flex flex-1 items-start justify-center px-4 pb-10 sm:px-8 lg:mt-0 lg:items-center lg:py-12">
                <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-xl shadow-zinc-900/5 ring-1 ring-zinc-900/5 sm:p-10">
                    {{ $slot }}
                </div>
            </div>

            <footer class="relative px-4 pb-6 text-center text-xs text-zinc-400">
                © {{ now()->year }} ÉcoleConnect · Vos données restent privées et propres à votre école.
            </footer>
        </main>
    </div>
    <x-ui.confirm-dialog />
    @livewireScripts
</body>
</html>
