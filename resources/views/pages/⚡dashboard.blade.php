<?php

use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tableau de bord')] class extends Component
{
    #[Computed]
    public function currentYear(): ?AcademicYear
    {
        return auth()->user()->school->currentAcademicYear;
    }

    /**
     * @return array{students: int, girls: int, boys: int, classes: int, staff: int}
     */
    #[Computed]
    public function stats(): array
    {
        $enrollments = Enrollment::query()
            ->whereBelongsTo($this->currentYear)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->selectRaw('students.gender, count(*) as total')
            ->groupBy('students.gender')
            ->pluck('total', 'gender');

        return [
            'students' => (int) $enrollments->sum(),
            'girls' => (int) ($enrollments[Gender::Female->value] ?? 0),
            'boys' => (int) ($enrollments[Gender::Male->value] ?? 0),
            'classes' => SchoolClass::whereBelongsTo($this->currentYear)->count(),
            'staff' => User::where('school_id', auth()->user()->school_id)
                ->whereIn('role', array_map(fn (Role $role) => $role->value, Role::staffRoles()))
                ->when(auth()->user()->activeSection(), fn ($query, Section $section) => $query->where('section', $section))
                ->where('is_active', true)
                ->count(),
        ];
    }
};
?>

<div>
    <x-ui.page-header
        :title="'Bonjour, '.auth()->user()->name"
        :description="$this->currentYear ? 'Année scolaire '.$this->currentYear->name : null"
    />

    @if (! $this->currentYear)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Aucune année scolaire n'est active.
            @can('manage-whole-school')
                <a href="{{ route('settings') }}" wire:navigate class="font-medium underline">Créez-en une dans les paramètres</a> pour commencer.
            @else
                Demandez à l'administrateur de l'école d'en créer une.
            @endcan
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['label' => 'Élèves inscrits', 'value' => $this->stats['students']],
                ['label' => 'Filles / Garçons', 'value' => $this->stats['girls'].' / '.$this->stats['boys']],
                ['label' => 'Classes', 'value' => $this->stats['classes']],
                ['label' => 'Personnel actif', 'value' => $this->stats['staff']],
            ] as $stat)
                <div class="rounded-xl border border-zinc-200 bg-white p-5" wire:key="stat-{{ $loop->index }}">
                    <p class="text-sm text-zinc-500">{{ $stat['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>

        @can('manage-students')
            <div class="mt-6 flex flex-wrap gap-2">
                <x-ui.button :href="route('students.create')">Inscrire un élève</x-ui.button>
                <x-ui.button variant="secondary" :href="route('classes.index')">Gérer les classes</x-ui.button>
            </div>
        @endcan
    @endif
</div>
