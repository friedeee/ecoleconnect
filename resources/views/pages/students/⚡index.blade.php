<?php

use App\Enums\Gender;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Élèves')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'classe', except: '')]
    public string $classId = '';

    #[Url(as: 'sexe', except: '')]
    public string $gender = '';

    /**
     * Page sizes offered to the user.
     *
     * @var list<int>
     */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    #[Url(as: 'par_page', except: 25)]
    public int $perPage = 25;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'classId', 'gender', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Collection<int, SchoolClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        return SchoolClass::query()
            ->whereHas('academicYear', fn (Builder $query) => $query->where('is_current', true))
            ->orderBy('level')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Student>
     */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        return Student::query()
            ->search($this->search)
            ->when($this->classId !== '', fn (Builder $query) => $query->whereHas(
                'currentEnrollment',
                fn (Builder $query) => $query->where('school_class_id', (int) $this->classId),
            ))
            ->when(Gender::tryFrom($this->gender), fn (Builder $query, Gender $gender) => $query->where('gender', $gender))
            ->with(['currentEnrollment.schoolClass', 'guardians'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(in_array($this->perPage, self::PER_PAGE_OPTIONS, true) ? $this->perPage : self::PER_PAGE_OPTIONS[0]);
    }
};
?>

<div>
    <x-ui.page-header title="Élèves" :description="$this->students->total().' élève(s)'">
        @can('manage-students')
            <x-ui.button :href="route('students.create')">Inscrire un élève</x-ui.button>
        @endcan
    </x-ui.page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Rechercher par nom ou matricule…"
            aria-label="Rechercher un élève"
            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 sm:max-w-xs"
        >
        <select
            wire:model.live="classId"
            aria-label="Filtrer par classe"
            class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-48"
        >
            <option value="">Toutes les classes</option>
            @foreach ($this->classes as $class)
                <option value="{{ $class->id }}" wire:key="class-{{ $class->id }}">{{ $class->name }}</option>
            @endforeach
        </select>
        <select
            wire:model.live="gender"
            aria-label="Filtrer par sexe"
            class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-40"
        >
            <option value="">Tous les sexes</option>
            @foreach (\App\Enums\Gender::cases() as $option)
                <option value="{{ $option->value }}" wire:key="gender-{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
    </div>

    @if ($this->students->isEmpty())
        <x-ui.empty>Aucun élève ne correspond à votre recherche.</x-ui.empty>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Matricule</th>
                        <th class="px-4 py-3">Nom et prénoms</th>
                        <th class="px-4 py-3">Sexe</th>
                        <th class="px-4 py-3">Classe</th>
                        <th class="px-4 py-3">Contact parent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($this->students as $student)
                        <tr wire:key="student-{{ $student->id }}" class="hover:bg-zinc-50">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-zinc-500">{{ $student->registration_number }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('students.show', $student) }}" wire:navigate class="font-medium text-zinc-900 hover:text-brand-700">{{ $student->full_name }}</a>
                            </td>
                            <td class="px-4 py-3">{{ $student->gender->value }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $student->currentEnrollment?->schoolClass->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-600">{{ $student->guardians->firstWhere('pivot.is_primary_contact', true)?->phone ?? $student->guardians->first()?->phone ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-ui.pagination :paginator="$this->students" noun="élèves" :per-page-options="$this::PER_PAGE_OPTIONS" class="mt-4" />
    @endif
</div>
