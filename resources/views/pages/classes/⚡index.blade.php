<?php

use App\Enums\ClassLevel;
use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Classes')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $level = '';

    public string $capacity = '';

    public string $head_teacher_id = '';

    #[Computed]
    public function currentYear(): ?AcademicYear
    {
        return auth()->user()->school->currentAcademicYear;
    }

    /**
     * @return Collection<int, SchoolClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        return SchoolClass::whereBelongsTo($this->currentYear)
            ->with('headTeacher')
            ->withCount(['enrollments', 'subjects'])
            ->get()
            ->sortBy([
                fn (SchoolClass $a, SchoolClass $b) => $a->level->rank() <=> $b->level->rank(),
                fn (SchoolClass $a, SchoolClass $b) => strnatcasecmp($a->name, $b->name),
            ])
            ->values();
    }

    /**
     * Levels of the cycles the school teaches, within the section the user works in.
     *
     * @return list<ClassLevel>
     */
    #[Computed]
    public function levels(): array
    {
        $user = auth()->user();
        $section = $user->activeSection();

        return array_values(array_filter(
            ClassLevel::cases(),
            fn (ClassLevel $level) => $user->school->teaches($level->cycle()) && ($section === null || $level->cycle()->section() === $section),
        ));
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function teachers(): Collection
    {
        return User::where('school_id', auth()->user()->school_id)
            ->availableIn(auth()->user()->activeSection())
            ->where('role', Role::Teacher)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        Gate::authorize('manage-school');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('school_classes')->where('academic_year_id', $this->currentYear?->id)],
            'level' => ['required', Rule::enum(ClassLevel::class)->only($this->levels)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'head_teacher_id' => ['nullable', Rule::in($this->teachers->modelKeys())],
        ], attributes: ['name' => 'nom', 'level' => 'niveau', 'capacity' => 'effectif maximum', 'head_teacher_id' => 'titulaire']);

        SchoolClass::create([
            'academic_year_id' => $this->currentYear->id,
            'name' => trim($validated['name']),
            'level' => $validated['level'],
            'capacity' => $validated['capacity'] ?: null,
            'head_teacher_id' => $validated['head_teacher_id'] ?: null,
        ]);

        $this->reset(['name', 'level', 'capacity', 'head_teacher_id', 'showForm']);
        unset($this->classes);
    }
};
?>

<div>
    <x-ui.page-header title="Classes" :description="$this->currentYear ? 'Année scolaire '.$this->currentYear->name : null">
        @can('manage-school')
            @if ($this->currentYear)
                <x-ui.button type="button" wire:click="$toggle('showForm')">Nouvelle classe</x-ui.button>
            @endif
        @endcan
    </x-ui.page-header>

    @if (! $this->currentYear)
        <x-ui.empty>Aucune année scolaire active.</x-ui.empty>
    @else
        @if ($showForm)
            <x-ui.card title="Nouvelle classe" class="mb-6">
                <form wire:submit="create" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.select label="Niveau" name="level" placeholder="Choisir…">
                        @foreach ($this->levels as $option)
                            <option value="{{ $option->value }}" wire:key="level-{{ $option->value }}">{{ $option->value }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Nom de la classe" name="name" placeholder="ex. 6e A" />
                    <x-ui.input label="Effectif maximum" name="capacity" type="number" min="1" />
                    <x-ui.select label="Titulaire / prof. principal" name="head_teacher_id" placeholder="Aucun">
                        @foreach ($this->teachers as $teacher)
                            <option value="{{ $teacher->id }}" wire:key="teacher-{{ $teacher->id }}">{{ $teacher->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <div class="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)">Annuler</x-ui.button>
                        <x-ui.button>Créer la classe</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        @if ($this->classes->isEmpty())
            <x-ui.empty>Aucune classe pour cette année. Commencez par en créer une.</x-ui.empty>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->classes as $class)
                    <a
                        href="{{ route('classes.show', $class) }}"
                        wire:navigate
                        wire:key="class-{{ $class->id }}"
                        class="rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-brand-500"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold">{{ $class->name }}</p>
                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600">{{ $class->level->value }}</span>
                        </div>
                        <p class="mt-3 text-2xl font-semibold tabular-nums">
                            {{ $class->enrollments_count }}<span class="text-sm font-normal text-zinc-500">{{ $class->capacity ? ' / '.$class->capacity : '' }} élèves</span>
                        </p>
                        <p class="mt-2 text-sm text-zinc-500">{{ $class->subjects_count }} matière(s) · {{ $class->headTeacher?->name ?? 'Pas de '.mb_strtolower($class->cycle->headTeacherTitle()) }}</p>
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</div>
