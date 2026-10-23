@php($school = auth()->user()->school)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $school->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-100/70 font-sans text-zinc-900 antialiased">
    <header class="sticky top-0 z-20 bg-brand-950 text-white print:hidden">
        <div class="mx-auto flex h-16 max-w-3xl items-center gap-3 px-4">
            <a href="{{ route('parent.home') }}" wire:navigate class="flex min-w-0 items-center gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600">
                    <x-ui.icon name="school" class="size-5" />
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold">{{ $school->name }}</span>
                    <span class="block truncate text-xs text-brand-300">Espace parents</span>
                </span>
            </a>
            <div class="ml-auto flex items-center gap-1">
                <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-2 rounded-lg p-1 pr-2 text-xs text-brand-100 hover:bg-white/10" title="Mon profil">
                    <x-ui.avatar :name="auth()->user()->name" size="sm" class="!bg-gold-400 !text-brand-950" />
                    <span class="hidden sm:inline">Mon profil</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg p-2 text-brand-200 hover:bg-white/10 hover:text-white" title="Se déconnecter" aria-label="Se déconnecter">
                        <x-ui.icon name="logout" class="size-5" />
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-6">
        @if (session('status'))
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">
                <x-ui.icon name="check" class="size-5 text-brand-600" />
                {{ session('status') }}
            </div>
        @endif

        {{ $slot }}
    </main>

    <x-ui.confirm-dialog />
    @livewireScripts
</body>
</html>
