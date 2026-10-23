<?php

use App\Enums\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $editing = false;

    public string $name = '';

    public string $phone = '';

    /** Abilities shown in "Ce que vous pouvez faire", in menu order. */
    public const ABILITIES = [
        'manage-platform' => 'Ouvrir, suspendre et réactiver les écoles clientes',
        'manage-whole-school' => 'Gérer les paramètres de l\'école et les années scolaires',
        'manage-school' => 'Gérer les classes, les matières et le personnel',
        'manage-students' => 'Inscrire les élèves et ouvrir les accès parents',
        'view-students' => 'Consulter les élèves et les classes',
        'manage-report-cards' => 'Éditer les bulletins',
        'view-grades' => 'Saisir des notes et donner des exercices',
        'view-attendance' => 'Faire l\'appel',
        'justify-absences' => 'Justifier les absences',
        'manage-timetable' => 'Construire les emplois du temps',
        'collect-payments' => 'Encaisser et annuler des paiements',
        'view-fees' => 'Suivre les frais de scolarité et les impayés',
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()->is_active, 403);

        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $this->name = auth()->user()->name;
        $this->phone = auth()->user()->phone ?? '';
    }

    /**
     * Parents sign in with their phone number, which the school keeps up to date: they cannot change it here.
     */
    #[Computed]
    public function canEdit(): bool
    {
        return ! auth()->user()->hasRole(Role::Parent);
    }

    /**
     * For a teacher: the classes they are in charge of and the subjects they teach in each.
     *
     * @return Collection<int, SchoolClass>
     */
    #[Computed]
    public function teaching(): Collection
    {
        $userId = auth()->id();

        return SchoolClass::whereHas('academicYear', fn ($query) => $query->where('is_current', true))
            ->where(fn ($query) => $query
                ->where('head_teacher_id', $userId)
                ->orWhereHas('subjects', fn ($query) => $query->where('class_subject.teacher_id', $userId)))
            ->with(['subjects' => fn ($query) => $query->where('class_subject.teacher_id', $userId)->orderBy('name')])
            ->get()
            ->sortBy(fn (SchoolClass $class) => [$class->level->rank(), $class->name])
            ->values();
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function children(): Collection
    {
        return auth()->user()->children();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function abilities(): array
    {
        return array_values(array_filter(self::ABILITIES, fn (string $label, string $ability) => Gate::allows($ability), ARRAY_FILTER_USE_BOTH));
    }

    public function edit(): void
    {
        abort_unless($this->canEdit, 403);

        $this->fillForm();
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(): void
    {
        abort_unless($this->canEdit, 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 .-]{8,20}$/'],
        ], attributes: ['name' => 'nom complet', 'phone' => 'téléphone']);

        auth()->user()->update(['name' => trim($validated['name']), 'phone' => trim($validated['phone']) ?: null]);

        $this->editing = false;
        session()->flash('profile-saved', 'Vos informations ont été enregistrées.');
    }

    public function render()
    {
        $view = $this->view()->title('Mon profil');

        return auth()->user()->hasRole(Role::Parent) ? $view->layout('layouts::parent') : $view;
    }
};
?>

@php($user = auth()->user())

<div>
    @unless ($user->hasRole(\App\Enums\Role::Parent))
        <x-ui.page-header title="Mon profil" />
    @endunless

    <section class="card overflow-hidden">
        <div class="h-20 bg-gradient-to-r from-brand-700 to-brand-500"></div>
        <div class="flex flex-col gap-4 px-5 pb-5 sm:flex-row sm:items-end">
            <x-ui.avatar :name="$user->name" size="lg" class="-mt-8 ring-4 ring-white" />
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-xl font-semibold text-zinc-950">{{ $user->name }}</h1>
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <x-ui.badge tone="brand">{{ $user->role->label() }}</x-ui.badge>
                    @if ($user->section)
                        <x-ui.badge :tone="$user->section->cycles()[0]->tone()">{{ $user->section->label() }}</x-ui.badge>
                    @elseif ($user->school && ! $user->hasRole(\App\Enums\Role::Parent))
                        <x-ui.badge>Toute l'école</x-ui.badge>
                    @endif
                    <span class="text-sm text-zinc-500">{{ $user->school?->name ?? 'Plateforme ÉcoleConnect' }}</span>
                </div>
            </div>
        </div>
    </section>

    @if (session('profile-saved'))
        <div class="mt-4 flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm text-brand-900" role="status">
            <x-ui.icon name="check" class="size-4.5 text-brand-600" /> {{ session('profile-saved') }}
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Mes informations" icon="users">
                @if ($this->canEdit && ! $editing)
                    <x-slot:actions>
                        <button type="button" wire:click="edit" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:underline">
                            <x-ui.icon name="pencil" class="size-4" /> Modifier
                        </button>
                    </x-slot:actions>
                @endif

                @if ($editing)
                    <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Nom complet" name="name" autocomplete="name" />
                        <x-ui.input label="Téléphone" name="phone" type="tel" autocomplete="tel" />
                        <div class="flex justify-end gap-2 sm:col-span-2">
                            <x-ui.button type="button" variant="secondary" wire:click="$set('editing', false)">Annuler</x-ui.button>
                            <x-ui.button>Enregistrer</x-ui.button>
                        </div>
                    </form>
                @else
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        @foreach (array_filter([
                            'Nom complet' => $user->name,
                            ($user->hasRole(\App\Enums\Role::Parent) ? 'Téléphone de connexion' : 'Téléphone') => $user->phone,
                            'Adresse e-mail' => $user->email,
                            'Rôle' => $user->role->label(),
                            'École' => $user->school?->name,
                            'Compte créé le' => $user->created_at?->translatedFormat('j F Y'),
                        ], fn ($value, $label) => $value !== null || in_array($label, ['Téléphone', 'Adresse e-mail'], true), ARRAY_FILTER_USE_BOTH) as $label => $value)
                            <div wire:key="info-{{ $loop->index }}">
                                <dt class="text-zinc-500">{{ $label }}</dt>
                                <dd class="mt-0.5 font-medium text-zinc-900">{{ $value ?: '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($user->hasRole(\App\Enums\Role::Parent))
                        <p class="mt-4 text-xs text-zinc-500">Pour changer votre nom ou votre numéro de téléphone, adressez-vous à l'école.</p>
                    @elseif ($user->email)
                        <p class="mt-4 text-xs text-zinc-500">L'adresse e-mail sert à vous connecter : seule l'administration peut la modifier.</p>
                    @endif
                @endif
            </x-ui.card>

            @if ($user->hasRole(\App\Enums\Role::Teacher))
                <x-ui.card title="Mes classes et matières" icon="academic-cap">
                    @if ($this->teaching->isEmpty())
                        <p class="text-sm text-zinc-500">Aucune classe ne vous est attribuée cette année.</p>
                    @else
                        <ul class="divide-y divide-zinc-100 text-sm">
                            @foreach ($this->teaching as $class)
                                <li wire:key="class-{{ $class->id }}" class="flex flex-wrap items-center gap-2 py-2.5">
                                    <a href="{{ route('classes.show', $class) }}" wire:navigate class="font-semibold text-zinc-900 hover:text-brand-700">{{ $class->name }}</a>
                                    @if ($class->head_teacher_id === $user->id)
                                        <x-ui.badge tone="amber">{{ $class->cycle->headTeacherTitle() }}</x-ui.badge>
                                    @endif
                                    <span class="text-zinc-600">{{ $class->subjects->pluck('name')->implode(', ') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif

            @if ($user->hasRole(\App\Enums\Role::Parent))
                <x-ui.card title="Mes enfants" icon="users">
                    <ul class="divide-y divide-zinc-100 text-sm">
                        @forelse ($this->children as $child)
                            <li wire:key="child-{{ $child->id }}">
                                <a href="{{ route('parent.child', $child) }}" wire:navigate class="flex items-center gap-3 py-2.5 hover:text-brand-700">
                                    <x-ui.avatar :name="$child->full_name" size="sm" />
                                    <span class="flex-1 font-medium">{{ $child->first_name }} {{ mb_strtoupper($child->last_name) }}</span>
                                    <span class="text-zinc-500">{{ $child->currentEnrollment?->schoolClass->name }}</span>
                                </a>
                            </li>
                        @empty
                            <li class="py-2.5 text-zinc-500">Aucun enfant rattaché.</li>
                        @endforelse
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card title="Sécurité" icon="settings">
                <p class="text-sm text-zinc-600">Votre mot de passe est personnel : ne le communiquez à personne, même à l'école.</p>
                <a href="{{ route('password.edit') }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 shadow-xs ring-1 ring-zinc-300 ring-inset transition hover:bg-zinc-50">
                    Changer mon mot de passe
                </a>
                <form method="POST" action="{{ route('logout') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-red-600 transition hover:bg-red-50">
                        <x-ui.icon name="logout" class="size-4" /> Se déconnecter
                    </button>
                </form>
            </x-ui.card>

            @if ($this->abilities !== [])
                <x-ui.card title="Ce que vous pouvez faire" icon="check">
                    <ul class="space-y-2 text-sm text-zinc-700">
                        @foreach ($this->abilities as $ability)
                            <li wire:key="ability-{{ $loop->index }}" class="flex gap-2">
                                <x-ui.icon name="check" class="mt-0.5 size-4 text-brand-600" />
                                <span>{{ $ability }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
