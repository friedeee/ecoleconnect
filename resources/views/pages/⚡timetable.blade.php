<?php

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Emploi du temps')] class extends Component
{
    #[Url(as: 'vue', except: 'classe')]
    public string $view = 'classe';

    #[Url(as: 'classe', except: '')]
    public string $classId = '';

    #[Url(as: 'enseignant', except: '')]
    public string $teacherId = '';

    public string $day = '1';

    public string $starts_at = '07:00';

    public string $ends_at = '09:00';

    public string $subject_id = '';

    public string $room = '';

    public function mount(): void
    {
        // A teacher sees their own week.
        if (auth()->user()->hasRole(Role::Teacher)) {
            $this->view = 'enseignant';
            $this->teacherId = (string) auth()->id();
        }
    }

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
            ->with(['subjects' => fn ($query) => $query->orderBy('name')])
            ->get()
            ->sortBy(fn (SchoolClass $class) => [$class->level->rank(), $class->name])
            ->values();
    }

    #[Computed]
    public function schoolClass(): ?SchoolClass
    {
        return $this->classes->firstWhere('id', (int) $this->classId) ?? $this->classes->first();
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
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function teacher(): ?User
    {
        if (auth()->user()->hasRole(Role::Teacher)) {
            return auth()->user();
        }

        return $this->teachers->firstWhere('id', (int) $this->teacherId) ?? $this->teachers->first();
    }

    /**
     * The week's lessons of the chosen class or teacher, grouped by day.
     *
     * @return \Illuminate\Support\Collection<int, Collection<int, TimetableEntry>>
     */
    #[Computed]
    public function week(): \Illuminate\Support\Collection
    {
        $query = TimetableEntry::with(['subject', 'teacher', 'schoolClass']);

        if ($this->view === 'enseignant') {
            if ($this->teacher === null) {
                return collect();
            }

            $query->where('teacher_id', $this->teacher->id)
                ->whereHas('schoolClass', fn ($query) => $query->whereBelongsTo($this->currentYear));
        } else {
            if ($this->schoolClass === null) {
                return collect();
            }

            $query->whereBelongsTo($this->schoolClass);
        }

        return $query->orderBy('starts_at')->get()->groupBy('day');
    }

    /**
     * Weekly hours per subject of the chosen class.
     *
     * @return \Illuminate\Support\Collection<string, float>
     */
    #[Computed]
    public function hoursPerSubject(): \Illuminate\Support\Collection
    {
        return $this->week->flatten()
            ->groupBy(fn (TimetableEntry $entry) => $entry->subject->name)
            ->map(fn ($entries) => $entries->sum(fn (TimetableEntry $entry) => $entry->durationInMinutes()) / 60)
            ->sortKeys();
    }

    public function add(): void
    {
        Gate::authorize('manage-timetable');

        $class = $this->schoolClass ?? abort(404);

        $validated = $this->validate([
            'day' => ['required', Rule::in(array_map('strval', array_keys(TimetableEntry::DAYS)))],
            'starts_at' => ['required', 'date_format:H:i', 'after_or_equal:06:00'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at', 'before_or_equal:20:00'],
            'subject_id' => ['required', Rule::in($class->subjects->modelKeys())],
            'room' => ['nullable', 'string', 'max:30'],
        ], attributes: ['day' => 'jour', 'starts_at' => 'début', 'ends_at' => 'fin', 'subject_id' => 'matière', 'room' => 'salle']);

        $entry = new TimetableEntry([
            'school_class_id' => $class->id,
            'subject_id' => (int) $validated['subject_id'],
            'teacher_id' => $class->subjects->firstWhere('id', (int) $validated['subject_id'])->pivot->teacher_id,
            'day' => (int) $validated['day'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'room' => trim($validated['room']) ?: null,
        ]);
        $entry->school_id = auth()->user()->school_id;

        $conflicts = $entry->conflicts();

        if ($conflicts !== []) {
            $this->addError('conflicts', implode(' ', $conflicts));

            return;
        }

        $entry->save();

        // Ready for the next lesson of the same day.
        $this->starts_at = $validated['ends_at'];
        $this->ends_at = date('H:i', strtotime($validated['ends_at']) + 3600);
        $this->reset('subject_id');
        unset($this->week, $this->hoursPerSubject);
    }

    public function delete(int $entryId): void
    {
        Gate::authorize('manage-timetable');

        ($this->week->flatten()->firstWhere('id', $entryId) ?? abort(404))->delete();
        unset($this->week, $this->hoursPerSubject);
    }
};
?>

<div>
    <x-ui.page-header
        title="Emploi du temps"
        :description="$view === 'enseignant' ? ($this->teacher ? 'Semaine de '.$this->teacher->name : null) : ($this->schoolClass ? 'Classe '.$this->schoolClass->name : null)"
    >
        <x-ui.button type="button" variant="secondary" onclick="window.print()" class="print:hidden">Imprimer</x-ui.button>
    </x-ui.page-header>

    @if (! $this->currentYear || $this->classes->isEmpty())
        <x-ui.empty>Aucune classe pour l'année en cours.</x-ui.empty>
    @else
        @unless (auth()->user()->hasRole(\App\Enums\Role::Teacher))
            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center print:hidden">
                <div class="flex rounded-lg bg-zinc-200/60 p-1 text-sm font-medium" role="tablist">
                    @foreach (['classe' => 'Par classe', 'enseignant' => 'Par enseignant'] as $value => $label)
                        <button
                            type="button"
                            role="tab"
                            wire:key="view-{{ $value }}"
                            wire:click="$set('view', '{{ $value }}')"
                            aria-selected="{{ $view === $value ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-xs' => $view === $value, 'text-zinc-600 hover:text-zinc-900' => $view !== $value])
                        >{{ $label }}</button>
                    @endforeach
                </div>
                @if ($view === 'classe')
                    <select wire:model.live="classId" aria-label="Classe" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-48">
                        @foreach ($this->classes as $class)
                            <option value="{{ $class->id }}" wire:key="class-{{ $class->id }}" @selected($class->is($this->schoolClass))>{{ $class->name }}</option>
                        @endforeach
                    </select>
                @else
                    <select wire:model.live="teacherId" aria-label="Enseignant" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-64">
                        @foreach ($this->teachers as $option)
                            <option value="{{ $option->id }}" wire:key="teacher-{{ $option->id }}" @selected($option->is($this->teacher))>{{ $option->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endunless

        @if ($view === 'classe' && auth()->user()->can('manage-timetable') && $this->schoolClass)
            <x-ui.card title="Ajouter un cours" class="mb-6 print:hidden">
                <form wire:submit="add" class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <x-ui.select label="Jour" name="day">
                        @foreach (\App\Models\TimetableEntry::DAYS as $number => $label)
                            <option value="{{ $number }}" wire:key="day-{{ $number }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Début" name="starts_at" type="time" step="300" />
                    <x-ui.input label="Fin" name="ends_at" type="time" step="300" />
                    <x-ui.select label="Matière" name="subject_id" placeholder="Choisir…">
                        @foreach ($this->schoolClass->subjects as $subject)
                            <option value="{{ $subject->id }}" wire:key="subject-{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Salle (facultatif)" name="room" placeholder="ex. Salle 3" />
                    <div class="flex items-end"><x-ui.button class="w-full" icon="plus">Ajouter</x-ui.button></div>
                </form>
                @error('conflicts')
                    <p class="mt-3 flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                        <x-ui.icon name="warning" class="mt-0.5 size-4" /> {{ $message }}
                    </p>
                @enderror
                @if ($this->schoolClass->subjects->isEmpty())
                    <p class="mt-3 text-sm text-zinc-500">Cette classe n'a pas encore de matières : ajoutez-les depuis la fiche de la classe.</p>
                @endif
            </x-ui.card>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 print:grid-cols-6">
            @foreach (\App\Models\TimetableEntry::DAYS as $number => $label)
                <section wire:key="column-{{ $number }}" class="rounded-xl border border-zinc-200 bg-white">
                    <h2 class="border-b border-zinc-100 px-3 py-2 text-sm font-semibold">{{ $label }}</h2>
                    <ul class="space-y-2 p-2">
                        @forelse ($this->week->get($number, collect()) as $entry)
                            <li wire:key="entry-{{ $entry->id }}" class="group rounded-lg bg-brand-50 px-3 py-2 text-sm ring-1 ring-brand-100">
                                <p class="text-xs font-semibold tabular-nums text-brand-700">{{ substr($entry->starts_at, 0, 5) }} – {{ substr($entry->ends_at, 0, 5) }}</p>
                                <p class="font-medium text-zinc-900">{{ $entry->subject->name }}</p>
                                <p class="text-xs text-zinc-600">
                                    {{ $view === 'enseignant' ? $entry->schoolClass->name : ($entry->teacher?->name ?? 'Enseignant non attribué') }}{{ $entry->room ? ' · '.$entry->room : '' }}
                                </p>
                                @if ($view === 'classe' && auth()->user()->can('manage-timetable'))
                                    <button type="button" wire:click="delete({{ $entry->id }})" wire:confirm="Retirer ce cours de {{ $entry->subject->name }} du {{ mb_strtolower($label) }} ?" class="mt-1 text-xs font-medium text-red-600 hover:underline print:hidden">Retirer</button>
                                @endif
                            </li>
                        @empty
                            <li class="px-1 py-2 text-xs text-zinc-400">Aucun cours</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>

        @if ($this->hoursPerSubject->isNotEmpty())
            <p class="mt-4 text-sm text-zinc-600">
                <strong>{{ rtrim(rtrim(number_format($this->hoursPerSubject->sum(), 1, ',', ''), '0'), ',') }} h</strong> par semaine :
                @foreach ($this->hoursPerSubject as $subject => $hours)
                    <span wire:key="hours-{{ $loop->index }}">{{ $subject }} {{ rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ',') }} h{{ $loop->last ? '' : ' ·' }}</span>
                @endforeach
            </p>
        @endif
    @endif
</div>
