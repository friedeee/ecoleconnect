<?php

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\GradingPeriod;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Notes')] class extends Component
{
    #[Url(as: 'periode', except: '')]
    public string $periodId = '';

    #[Computed]
    public function currentYear(): ?AcademicYear
    {
        return auth()->user()->school->currentAcademicYear;
    }

    /**
     * @return Collection<int, GradingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return $this->currentYear ? GradingPeriod::ensureFor($this->currentYear) : new Collection;
    }

    #[Computed]
    public function period(): ?GradingPeriod
    {
        return $this->periods->firstWhere('id', (int) $this->periodId) ?? GradingPeriod::covering($this->periods);
    }

    /**
     * Classes with the subjects the user may grade in each.
     *
     * @return list<array{class: SchoolClass, subjects: \Illuminate\Support\Collection<int, \App\Models\Subject>}>
     */
    #[Computed]
    public function assignments(): array
    {
        $classes = SchoolClass::whereBelongsTo($this->currentYear)
            ->with(['subjects' => fn ($query) => $query->orderBy('name')])
            ->withCount('enrollments')
            ->get()
            ->sortBy(fn (SchoolClass $class) => [$class->level->rank(), $class->name]);

        $isTeacher = auth()->user()->hasRole(Role::Teacher);

        return $classes
            ->map(fn (SchoolClass $class) => [
                'class' => $class,
                'subjects' => $isTeacher
                    ? $class->subjects->filter(fn ($subject) => Gate::allows('grade', [$class, $subject]))->values()
                    : $class->subjects,
            ])
            ->filter(fn (array $assignment) => $assignment['subjects']->isNotEmpty())
            ->values()
            ->all();
    }

    /**
     * Number of assessments per class and subject in the period: "classId-subjectId" => count.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function assessmentCounts(): array
    {
        if ($this->period === null) {
            return [];
        }

        return Assessment::whereBelongsTo($this->period)
            ->selectRaw('school_class_id, subject_id, count(*) as total')
            ->groupBy('school_class_id', 'subject_id')
            ->get()
            ->mapWithKeys(fn (Assessment $row) => [$row->school_class_id.'-'.$row->subject_id => (int) $row->total])
            ->all();
    }
};
?>

<div>
    <x-ui.page-header title="Notes" :description="$this->period ? $this->period->name.' · '.$this->currentYear->name : null">
        @can('manage-report-cards')
            <x-ui.button variant="secondary" :href="route('grades.reports', ['periode' => $this->period?->id])">Bulletins</x-ui.button>
        @endcan
    </x-ui.page-header>

    @if (! $this->currentYear)
        <x-ui.empty>Aucune année scolaire active.</x-ui.empty>
    @else
        <div class="mb-6 flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg bg-zinc-200/60 p-1 text-sm font-medium" role="tablist" aria-label="Période">
                @foreach ($this->periods as $option)
                    <button
                        type="button"
                        role="tab"
                        wire:key="period-{{ $option->id }}"
                        wire:click="$set('periodId', '{{ $option->id }}')"
                        aria-selected="{{ $option->is($this->period) ? 'true' : 'false' }}"
                        @class(['rounded-md px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-xs' => $option->is($this->period), 'text-zinc-600 hover:text-zinc-900' => ! $option->is($this->period)])
                    >{{ $option->name }}</button>
                @endforeach
            </div>
            @if ($this->period?->isLocked())
                <x-ui.badge tone="amber">Période verrouillée : notes en lecture seule</x-ui.badge>
            @endif
        </div>

        @if ($this->assignments === [])
            <x-ui.empty icon="grades" title="Aucune matière à noter">
                @if (auth()->user()->hasRole(\App\Enums\Role::Teacher))
                    Aucune matière ne vous est attribuée. L'administration vous assigne vos matières depuis la fiche de chaque classe.
                @else
                    Ajoutez d'abord des matières à vos classes depuis la page Classes.
                @endif
            </x-ui.empty>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->assignments as $assignment)
                    <x-ui.card wire:key="class-{{ $assignment['class']->id }}" :padded="false">
                        <x-slot:actions>
                            <span class="text-xs text-zinc-500">{{ $assignment['class']->enrollments_count }} élèves</span>
                        </x-slot:actions>
                        <x-slot:title>{{ $assignment['class']->name }}</x-slot:title>
                        <ul class="divide-y divide-zinc-100 text-sm">
                            @foreach ($assignment['subjects'] as $subject)
                                @php($count = $this->assessmentCounts[$assignment['class']->id.'-'.$subject->id] ?? 0)
                                <li wire:key="subject-{{ $assignment['class']->id }}-{{ $subject->id }}">
                                    <a href="{{ route('grades.sheet', [$assignment['class'], $subject, 'periode' => $this->period->id]) }}" wire:navigate class="flex items-center gap-3 px-5 py-2.5 hover:bg-zinc-50">
                                        <span class="flex-1 font-medium text-zinc-800">{{ $subject->name }}</span>
                                        <span @class(['text-xs', 'text-zinc-400' => $count === 0, 'text-brand-700' => $count > 0])>{{ $count }} évaluation(s)</span>
                                        <x-ui.icon name="chevron-right" class="size-4 text-zinc-400" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    @endif
</div>
