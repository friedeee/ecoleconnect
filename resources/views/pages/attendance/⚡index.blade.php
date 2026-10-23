<?php

use App\Enums\DaySession;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\AcademicYear;
use App\Models\GradingPeriod;
use App\Models\RollCall;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Absences')] class extends Component
{
    use WithPagination;

    /** Unjustified absences (half-days) from which a student is flagged. */
    public const ALERT_THRESHOLD = 4;

    #[Url(as: 'vue', except: 'appel')]
    public string $tab = 'appel';

    #[Url(as: 'date', except: '')]
    public string $date = '';

    #[Url(as: 'seance', except: '')]
    public string $session = '';

    #[Url(as: 'periode', except: '')]
    public string $periodId = '';

    #[Url(as: 'classe', except: '')]
    public string $classId = '';

    public function mount(): void
    {
        if ($this->tab === 'recap' && Gate::denies('justify-absences')) {
            $this->tab = 'appel';
        }

        $this->date = $this->day->format('Y-m-d');
        $this->session = $this->daySession->value;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['periodId', 'classId'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function day(): Carbon
    {
        $day = rescue(fn () => Carbon::parse($this->date), today(), report: false);

        return $day->isAfter(today()) ? today() : $day->startOfDay();
    }

    #[Computed]
    public function daySession(): DaySession
    {
        return DaySession::tryFrom($this->session) ?? DaySession::now();
    }

    #[Computed]
    public function currentYear(): ?AcademicYear
    {
        return auth()->user()->school->currentAcademicYear;
    }

    /**
     * Classes the user calls the roll of.
     *
     * @return Collection<int, SchoolClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        $classes = SchoolClass::whereBelongsTo($this->currentYear)
            ->with('headTeacher')
            ->withCount('enrollments')
            ->get()
            ->sortBy(fn (SchoolClass $class) => [$class->level->rank(), $class->name])
            ->values();

        return auth()->user()->hasRole(Role::Teacher)
            ? $classes->filter(fn (SchoolClass $class) => Gate::allows('take-attendance', $class))->values()
            : $classes;
    }

    /**
     * @return \Illuminate\Support\Collection<int, RollCall>
     */
    #[Computed]
    public function rollCalls(): \Illuminate\Support\Collection
    {
        return RollCall::with('taker')
            ->whereDate('date', $this->day)
            ->where('session', $this->daySession)
            ->get()
            ->keyBy('school_class_id');
    }

    /**
     * Absent and late students per class for the chosen half-day.
     *
     * @return array<int, array{absent: int, late: int}>
     */
    #[Computed]
    public function dayCounts(): array
    {
        return Absence::whereDate('date', $this->day)
            ->where('session', $this->daySession)
            ->selectRaw('school_class_id')
            ->selectRaw("count(*) filter (where status = 'absent') as absent")
            ->selectRaw("count(*) filter (where status = 'late') as late")
            ->groupBy('school_class_id')
            ->get()
            ->mapWithKeys(fn (Absence $row) => [$row->school_class_id => ['absent' => (int) $row->absent, 'late' => (int) $row->late]])
            ->all();
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
     * Students with absences or lateness over the period, the most unjustified absences first.
     *
     * @return LengthAwarePaginator<int, Absence>
     */
    #[Computed]
    public function summary(): LengthAwarePaginator
    {
        $page = Absence::query()
            ->whereBetween('date', [$this->period->starts_on->toDateString(), $this->period->ends_on->toDateString()])
            ->when($this->classId !== '', fn ($query) => $query->where('school_class_id', (int) $this->classId))
            ->selectRaw('student_id')
            ->selectRaw("count(*) filter (where status = 'absent') as absent")
            ->selectRaw("count(*) filter (where status = 'absent' and not is_justified) as unjustified")
            ->selectRaw("count(*) filter (where status = 'late') as late")
            ->groupBy('student_id')
            ->orderByRaw('unjustified desc, absent desc, late desc')
            ->paginate(25);

        $students = Student::with('currentEnrollment.schoolClass')->whereIn('id', $page->pluck('student_id'))->get()->keyBy('id');
        $page->getCollection()->each(fn (Absence $row) => $row->setRelation('student', $students->get($row->student_id)));

        return $page;
    }
};
?>

<div>
    <x-ui.page-header title="Absences" :description="$tab === 'appel' ? 'Appel du '.$this->day->translatedFormat('l j F').' · '.mb_strtolower($this->daySession->label()) : ($this->period ? 'Récapitulatif · '.$this->period->name : null)" />

    @if (! $this->currentYear)
        <x-ui.empty>Aucune année scolaire active.</x-ui.empty>
    @else
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center">
            @can('justify-absences')
                <div class="flex rounded-lg bg-zinc-200/60 p-1 text-sm font-medium" role="tablist">
                    @foreach (['appel' => 'Appel du jour', 'recap' => 'Récapitulatif'] as $value => $label)
                        <button
                            type="button"
                            role="tab"
                            wire:key="tab-{{ $value }}"
                            wire:click="$set('tab', '{{ $value }}')"
                            aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-xs' => $tab === $value, 'text-zinc-600 hover:text-zinc-900' => $tab !== $value])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            @endcan

            @if ($tab === 'appel')
                <input type="date" wire:model.live="date" max="{{ today()->format('Y-m-d') }}" aria-label="Jour" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                <div class="flex rounded-lg bg-zinc-200/60 p-1 text-sm font-medium" role="tablist" aria-label="Demi-journée">
                    @foreach (\App\Enums\DaySession::cases() as $option)
                        <button
                            type="button"
                            role="tab"
                            wire:key="session-{{ $option->value }}"
                            wire:click="$set('session', '{{ $option->value }}')"
                            aria-selected="{{ $option === $this->daySession ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-xs' => $option === $this->daySession, 'text-zinc-600 hover:text-zinc-900' => $option !== $this->daySession])
                        >{{ $option->label() }}</button>
                    @endforeach
                </div>
            @else
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
                <select wire:model.live="classId" aria-label="Filtrer par classe" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none lg:w-48">
                    <option value="">Toutes les classes</option>
                    @foreach ($this->classes as $class)
                        <option value="{{ $class->id }}" wire:key="filter-{{ $class->id }}">{{ $class->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        @if ($tab === 'appel')
            @if ($this->classes->isEmpty())
                <x-ui.empty icon="clock" title="Aucune classe">Aucune classe ne vous est attribuée pour l'appel.</x-ui.empty>
            @else
                @php($done = $this->classes->filter(fn ($class) => $this->rollCalls->has($class->id))->count())
                <p class="mb-3 text-sm text-zinc-600">Appel fait dans <strong>{{ $done }}</strong> classe(s) sur {{ $this->classes->count() }}.</p>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->classes as $class)
                        @php($rollCall = $this->rollCalls->get($class->id))
                        @php($counts = $this->dayCounts[$class->id] ?? ['absent' => 0, 'late' => 0])
                        <a
                            href="{{ route('attendance.roll-call', [$class, 'date' => $this->day->format('Y-m-d'), 'seance' => $this->daySession->value]) }}"
                            wire:navigate
                            wire:key="class-{{ $class->id }}"
                            @class(['rounded-xl border bg-white p-5 transition hover:border-brand-500', 'border-zinc-200' => $rollCall, 'border-dashed border-amber-300' => ! $rollCall])
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-semibold">{{ $class->name }}</p>
                                @if ($rollCall)
                                    <x-ui.badge tone="brand">Appel fait</x-ui.badge>
                                @else
                                    <x-ui.badge tone="amber">À faire</x-ui.badge>
                                @endif
                            </div>
                            @if ($rollCall)
                                <p class="mt-3 text-sm">
                                    <span @class(['font-semibold', 'text-red-600' => $counts['absent'] > 0])>{{ $counts['absent'] }} absent(s)</span>
                                    · <span @class(['font-semibold', 'text-amber-600' => $counts['late'] > 0])>{{ $counts['late'] }} retard(s)</span>
                                    sur {{ $class->enrollments_count }}
                                </p>
                                <p class="mt-1 text-xs text-zinc-500">Par {{ $rollCall->taker?->name ?? '—' }} à {{ $rollCall->updated_at->format('H:i') }}</p>
                            @else
                                <p class="mt-3 text-sm text-zinc-500">{{ $class->enrollments_count }} élèves · faire l'appel</p>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        @else
            @if ($this->summary->isEmpty())
                <x-ui.empty icon="check" title="Aucune absence">Aucune absence ni retard sur cette période{{ $classId !== '' ? ' pour cette classe' : '' }}.</x-ui.empty>
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Élève</th>
                                <th class="px-4 py-3">Classe</th>
                                <th class="px-4 py-3 text-right">Absences</th>
                                <th class="px-4 py-3 text-right">Non justifiées</th>
                                <th class="px-4 py-3 text-right">Retards</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($this->summary as $row)
                                <tr wire:key="summary-{{ $row->student_id }}" class="hover:bg-zinc-50">
                                    <td class="px-4 py-3">
                                        @if ($row->student)
                                            <a href="{{ route('students.show', $row->student) }}" wire:navigate class="font-medium text-zinc-900 hover:text-brand-700">{{ $row->student->full_name }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $row->student?->currentEnrollment?->schoolClass->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $row->absent }}</td>
                                    <td @class(['px-4 py-3 text-right font-semibold tabular-nums', 'text-red-600' => $row->unjustified >= $this::ALERT_THRESHOLD])>{{ $row->unjustified }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $row->late }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($row->unjustified >= $this::ALERT_THRESHOLD)
                                            <x-ui.badge tone="red">À signaler aux parents</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-zinc-500">Une absence = une demi-journée. Élèves signalés à partir de {{ $this::ALERT_THRESHOLD }} demi-journées non justifiées.</p>

                <x-ui.pagination :paginator="$this->summary" noun="élèves" class="mt-4" />
            @endif
        @endif
    @endif
</div>
