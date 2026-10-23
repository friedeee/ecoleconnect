<?php

use App\Enums\AttendanceStatus;
use App\Models\Absence;
use App\Models\GradingPeriod;
use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::parent')] #[Title('Mes enfants')] class extends Component
{
    /**
     * Each child with what a parent checks first.
     *
     * @return list<array{student: Student, balance: ?int, overdue: ?int, unjustified: int, report: ?array{period: string, general: ?float, rank: ?int, scale: int}}>
     */
    #[Computed]
    public function children(): array
    {
        return auth()->user()->children()->map(function (Student $student) {
            $enrollment = $student->currentEnrollment;
            $statement = $enrollment?->feeStatement();
            $year = $enrollment?->academicYear;

            return [
                'student' => $student,
                'balance' => $statement ? max($statement['balance'], 0) : null,
                'overdue' => $statement['overdue'] ?? null,
                'unjustified' => $year ? Absence::where('student_id', $student->id)
                    ->where('status', AttendanceStatus::Absent)
                    ->where('is_justified', false)
                    ->whereBetween('date', [$year->starts_on->toDateString(), $year->ends_on->toDateString()])
                    ->count() : 0,
                'report' => $enrollment ? $this->latestReport($student) : null,
            ];
        })->all();
    }

    /**
     * Result of the latest period whose report cards the school has released (locked).
     *
     * @return array{period: string, general: ?float, rank: ?int, scale: int}|null
     */
    protected function latestReport(Student $student): ?array
    {
        $enrollment = $student->currentEnrollment;
        $period = GradingPeriod::ensureFor($enrollment->academicYear)->filter->isLocked()->last();

        if ($period === null) {
            return null;
        }

        $row = collect($period->classResults($enrollment->schoolClass)['students'])->first(fn (array $row) => $row['student']->is($student));

        return [
            'period' => $period->name,
            'general' => $row['general'] ?? null,
            'rank' => $row['rank'] ?? null,
            'scale' => auth()->user()->school->scaleFor($enrollment->schoolClass->cycle),
        ];
    }
};
?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">Bonjour {{ auth()->user()->guardian->first_name }}</h1>
    <p class="mt-1 text-sm text-zinc-500">Suivez la scolarité de vos enfants.</p>

    @if ($this->children === [])
        <div class="mt-6">
            <x-ui.empty title="Aucun enfant">Aucun élève n'est encore rattaché à votre compte. Contactez l'école.</x-ui.empty>
        </div>
    @else
        <div class="mt-6 space-y-4">
            @foreach ($this->children as $child)
                @php($student = $child['student'])
                <a href="{{ route('parent.child', $student) }}" wire:navigate wire:key="child-{{ $student->id }}" class="card block p-5 transition hover:ring-2 hover:ring-brand-500">
                    <div class="flex items-center gap-4">
                        <x-ui.avatar :name="$student->full_name" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-lg font-semibold">{{ $student->first_name }} {{ mb_strtoupper($student->last_name) }}</p>
                            <p class="text-sm text-zinc-500">{{ $student->currentEnrollment?->schoolClass->name ?? 'Non inscrit cette année' }} · matricule {{ $student->registration_number }}</p>
                        </div>
                        <x-ui.icon name="chevron-right" class="size-5 text-zinc-400" />
                    </div>

                    <dl class="mt-4 grid grid-cols-3 gap-3 border-t border-zinc-100 pt-4 text-sm">
                        <div>
                            <dt class="text-xs text-zinc-500">{{ $child['report']['period'] ?? 'Bulletin' }}</dt>
                            <dd class="mt-0.5 font-semibold">
                                @if ($child['report'] && $child['report']['general'] !== null)
                                    {{ number_format($child['report']['general'] * $child['report']['scale'] / 20, 2, ',', ' ') }}/{{ $child['report']['scale'] }}
                                    <span class="font-normal text-zinc-500">· {{ $child['report']['rank'] === 1 ? '1er' : $child['report']['rank'].'e' }}</span>
                                @else
                                    <span class="font-normal text-zinc-400">Pas encore</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">Absences non justifiées</dt>
                            <dd @class(['mt-0.5 font-semibold', 'text-red-600' => $child['unjustified'] > 0])>{{ $child['unjustified'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ ($child['overdue'] ?? 0) > 0 ? 'En retard' : 'Reste à payer' }}</dt>
                            <dd @class(['mt-0.5 font-semibold', 'text-red-600' => ($child['overdue'] ?? 0) > 0])>
                                @if ($child['balance'] === null)
                                    —
                                @else
                                    <x-ui.money :amount="($child['overdue'] ?? 0) > 0 ? $child['overdue'] : $child['balance']" />
                                @endif
                            </dd>
                        </div>
                    </dl>
                </a>
            @endforeach
        </div>
    @endif
</div>
