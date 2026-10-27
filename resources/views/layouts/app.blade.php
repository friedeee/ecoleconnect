@php
    $user = auth()->user();
    $school = $user->school;
    $navigation = $user->hasRole(\App\Enums\Role::SuperAdmin) ? [
        'Plateforme' => [
            ['label' => 'Écoles', 'icon' => 'school', 'route' => 'platform.schools.index', 'active' => 'platform.schools.*', 'ability' => null],
        ],
    ] : [
        null => [
            ['label' => 'Tableau de bord', 'icon' => 'home', 'route' => 'dashboard', 'active' => 'dashboard', 'ability' => null],
        ],
        'Scolarité' => [
            ['label' => 'Élèves', 'icon' => 'users', 'route' => 'students.index', 'active' => 'students.*', 'ability' => 'view-students'],
            ['label' => 'Classes', 'icon' => 'academic-cap', 'route' => 'classes.index', 'active' => 'classes.*', 'ability' => 'view-students'],
            ['label' => 'Notes et bulletins', 'icon' => 'grades', 'route' => 'grades.index', 'active' => 'grades.*', 'ability' => 'view-grades'],
            ['label' => 'Exercices', 'icon' => 'book-open', 'route' => 'homework', 'active' => 'homework', 'ability' => 'view-grades'],
            ['label' => 'Absences', 'icon' => 'clock', 'route' => 'attendance.index', 'active' => 'attendance.*', 'ability' => 'view-attendance'],
            ['label' => 'Emploi du temps', 'icon' => 'calendar', 'route' => 'timetable', 'active' => 'timetable', 'ability' => 'view-timetable'],
        ],
        'Finances' => [
            ['label' => 'Frais de scolarité', 'icon' => 'banknotes', 'route' => 'fees.index', 'active' => 'fees.*', 'ability' => 'view-fees'],
        ],
        'Communication' => [
            ['label' => 'Portail parents', 'icon' => 'chat', 'route' => null],
        ],
        'Administration' => [
            ['label' => 'Matières', 'icon' => 'book-open', 'route' => 'subjects.index', 'active' => 'subjects.*', 'ability' => 'manage-school'],
            ['label' => 'Personnel', 'icon' => 'briefcase', 'route' => 'staff.index', 'active' => 'staff.*', 'ability' => 'manage-school'],
            ['label' => 'Journal d\'activité', 'icon' => 'clock', 'route' => 'activity', 'active' => 'activity', 'ability' => 'manage-whole-school'],
            ['label' => 'Paramètres', 'icon' => 'settings', 'route' => 'settings', 'active' => 'settings', 'ability' => 'manage-whole-school'],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $school?->name ?? 'ÉcoleConnect' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-100/70 font-sans text-zinc-900 antialiased" x-data="{ sidebarOpen: false }">
    <div x-cloak x-show="sidebarOpen" x-transition.opacity x-on:click="sidebarOpen = false" class="fixed inset-0 z-30 bg-zinc-950/50 lg:hidden"></div>

    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-68 -translate-x-full flex-col bg-brand-950 text-brand-100 transition-transform duration-200 lg:translate-x-0 print:hidden"
        x-bind:class="{ 'translate-x-0': sidebarOpen }"
    >
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(120%_60%_at_0%_0%,rgb(255_255_255/0.08),transparent_60%)]"></div>

        <div class="relative flex h-18 items-center gap-3 px-5">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white shadow-lg shadow-brand-950/50">
                <x-ui.icon name="school" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ $school?->name ?? 'ÉcoleConnect' }}</p>
                <p class="truncate text-xs text-brand-300">{{ $school === null ? 'Plateforme' : ($school->city ?? 'ÉcoleConnect') }}</p>
            </div>
            <button type="button" x-on:click="sidebarOpen = false" class="ml-auto rounded-lg p-1.5 text-brand-300 hover:bg-white/10 lg:hidden" aria-label="Fermer le menu">
                <x-ui.icon name="x" />
            </button>
        </div>

        <nav class="relative flex-1 space-y-6 overflow-y-auto px-3 py-4">
            @foreach ($navigation as $section => $items)
                <div wire:key="nav-{{ $loop->index }}">
                    @if ($section)
                        <p class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-brand-400/80">{{ $section }}</p>
                    @endif
                    <ul class="space-y-0.5">
                        @foreach ($items as $item)
                            @if ($item['route'] === null)
                                <li class="flex cursor-default items-center gap-3 rounded-lg px-3 py-2 text-sm text-brand-200/45">
                                    <x-ui.icon :name="$item['icon']" />
                                    <span class="flex-1">{{ $item['label'] }}</span>
                                    <span class="rounded-full bg-white/5 px-1.5 py-0.5 text-[10px] font-medium text-brand-300/70">Bientôt</span>
                                </li>
                            @elseif ($item['ability'] === null || $user->can($item['ability']))
                                @php($active = request()->routeIs($item['active']))
                                <li>
                                    <a
                                        href="{{ route($item['route']) }}"
                                        wire:navigate
                                        @class([
                                            'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                                            'bg-white/12 text-white shadow-inner shadow-white/5' => $active,
                                            'text-brand-100/80 hover:bg-white/6 hover:text-white' => ! $active,
                                        ])
                                    >
                                        <x-ui.icon :name="$item['icon']" @class(['text-gold-400' => $active, 'text-brand-300 group-hover:text-brand-100' => ! $active]) />
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="relative m-3 rounded-xl bg-white/6 p-3">
            <div class="flex items-center gap-1">
                <a
                    href="{{ route('profile') }}"
                    wire:navigate
                    title="Mon profil"
                    @class([
                        '-m-1.5 flex min-w-0 flex-1 items-center gap-3 rounded-lg p-1.5 transition hover:bg-white/10',
                        'bg-white/10' => request()->routeIs('profile'),
                    ])
                >
                    <x-ui.avatar :name="$user->name" size="sm" class="!bg-gold-400 !text-brand-950" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-white">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-brand-300">{{ $user->role->label() }} · Mon profil</span>
                    </span>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg p-1.5 text-brand-300 hover:bg-white/10 hover:text-white" title="Se déconnecter" aria-label="Se déconnecter">
                        <x-ui.icon name="logout" class="size-4.5" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="lg:pl-68 print:pl-0">
        <header class="sticky top-0 z-20 border-b border-zinc-200/70 bg-white/80 backdrop-blur-md print:hidden">
            <div class="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
                <button type="button" x-on:click="sidebarOpen = true" class="-ml-1 rounded-lg p-2 text-zinc-600 hover:bg-zinc-100 lg:hidden" aria-label="Ouvrir le menu">
                    <x-ui.icon name="menu" />
                </button>
                @if ($school !== null)
                    <livewire:global-search />
                @else
                    <p class="truncate text-sm font-semibold lg:hidden">ÉcoleConnect</p>
                @endif

                <div class="ml-auto flex items-center gap-3">
                    @if ($school !== null && ! $user->worksForWholeSchool())
                        <span class="hidden md:block"><x-ui.badge :tone="$user->section->cycles()[0]->tone()">{{ $user->section->label() }}</x-ui.badge></span>
                    @elseif ($school !== null && count($school->sections()) > 1)
                        @php($activeSection = $user->activeSection())
                        <form method="POST" action="{{ route('section.switch') }}" class="hidden rounded-lg md:flex bg-zinc-100 p-0.5 text-xs font-medium" aria-label="Section affichée">
                            @csrf
                            @foreach ([null, ...$school->sections()] as $option)
                                <button
                                    type="submit"
                                    name="section"
                                    value="{{ $option?->value }}"
                                    @class([
                                        'rounded-md px-2.5 py-1 transition',
                                        'bg-white text-zinc-900 shadow-xs' => $activeSection === $option,
                                        'text-zinc-500 hover:text-zinc-900' => $activeSection !== $option,
                                    ])
                                    @if ($activeSection === $option) aria-pressed="true" @endif
                                >{{ $option?->shortLabel() ?? 'Toute l\'école' }}</button>
                            @endforeach
                        </form>
                    @endif
                    <span class="hidden text-sm text-zinc-500 capitalize xl:inline">{{ now()->translatedFormat('l j F Y') }}</span>
                    @if ($school?->currentAcademicYear)
                        <x-ui.badge tone="brand">
                            <x-ui.icon name="calendar" class="size-3.5" />
                            {{ $school->currentAcademicYear->name }}
                        </x-ui.badge>
                    @endif
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            @if (session('status'))
                <div class="mb-6 flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">
                    <span class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-white"><x-ui.icon name="check" class="size-4" /></span>
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
    <x-ui.confirm-dialog />
    @livewireScripts
</body>
</html>
