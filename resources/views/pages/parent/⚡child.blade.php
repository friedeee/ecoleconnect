<?php

use App\Models\Absence;
use App\Models\Enrollment;
use App\Models\GradingPeriod;
use App\Models\Homework;
use App\Models\Payment;
use App\Models\Student;
use App\Models\TimetableEntry;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::parent')] class extends Component
{
    /** @var array<string, string> */
    public const TABS = [
        'bulletins' => 'Bulletins',
        'absences' => 'Absences',
        'frais' => 'Frais',
        'exercices' => 'Exercices',
        'emploi-du-temps' => 'Emploi du temps',
    ];

    #[Locked]
    public Student $student;

    #[Url(as: 'onglet', except: 'bulletins')]
    public string $tab = 'bulletins';

    public function mount(Student $student): void
    {
        // A parent only follows their own children.
        abort_unless(auth()->user()->children()->contains($student), 404);

        $this->student = $student->load('currentEnrollment.schoolClass.academicYear');

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'bulletins';
        }
    }

    #[Computed]
    public function enrollment(): ?Enrollment
    {
        return $this->student->currentEnrollment;
    }

    #[Computed]
    public function scale(): int
    {
        return $this->enrollment ? auth()->user()->school->scaleFor($this->enrollment->schoolClass->cycle) : 20;
    }

    /**
     * Results of the periods the school has released (locked), latest first.
     *
     * @return list<array{period: GradingPeriod, results: array<string, mixed>, row: ?array<string, mixed>}>
     */
    #[Computed]
    public function reports(): array
    {
        if ($this->enrollment === null) {
            return [];
        }

        return GradingPeriod::ensureFor($this->enrollment->academicYear)
            ->filter->isLocked()
            ->sortByDesc('number')
            ->map(function (GradingPeriod $period) {
                $results = $period->classResults($this->enrollment->schoolClass);

                return [
                    'period' => $period,
                    'results' => $results,
                    'row' => collect($results['students'])->first(fn (array $row) => $row['student']->is($this->student)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Absence>
     */
    #[Computed]
    public function absences(): Collection
    {
        $year = $this->enrollment?->academicYear;

        return $year === null ? new Collection : Absence::where('student_id', $this->student->id)
            ->whereBetween('date', [$year->starts_on->toDateString(), $year->ends_on->toDateString()])
            ->latest('date')
            ->orderByDesc('session')
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function statement(): ?array
    {
        return $this->enrollment?->feeStatement();
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->enrollment === null ? new Collection : Payment::where('student_id', $this->student->id)
            ->whereBelongsTo($this->enrollment->academicYear)
            ->latest('paid_on')
            ->latest('id')
            ->get();
    }

    /**
     * Exercises of the last weeks and those still to hand in.
     *
     * @return Collection<int, Homework>
     */
    #[Computed]
    public function homework(): Collection
    {
        return $this->enrollment === null ? new Collection : Homework::with(['subject', 'author'])
            ->whereBelongsTo($this->enrollment->schoolClass)
            ->where(fn ($query) => $query->where('given_on', '>=', today()->subDays(45))->orWhere('due_on', '>=', today()))
            ->latest('given_on')
            ->latest('id')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Collection<int, TimetableEntry>>
     */
    #[Computed]
    public function week(): \Illuminate\Support\Collection
    {
        return $this->enrollment === null ? collect() : TimetableEntry::with(['subject', 'teacher'])
            ->whereBelongsTo($this->enrollment->schoolClass)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('day');
    }

    public function render()
    {
        return $this->view()->title($this->student->first_name.' '.mb_strtoupper($this->student->last_name));
    }
};
?>

@php($format = fn (?float $value) => $value === null ? '—' : number_format($value * $this->scale / 20, 2, ',', ' '))
@php($ordinal = fn (?int $rank) => $rank === null ? '—' : ($rank === 1 ? '1er' : $rank.'e'))

<div>
    <a href="{{ route('parent.home') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-900">
        <x-ui.icon name="chevron-right" class="size-4 rotate-180" /> Mes enfants
    </a>

    <div class="mt-3 flex items-center gap-4">
        <x-ui.avatar :name="$student->full_name" />
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold tracking-tight">{{ $student->first_name }} {{ mb_strtoupper($student->last_name) }}</h1>
            <p class="text-sm text-zinc-500">{{ $this->enrollment?->schoolClass->name ?? 'Non inscrit cette année' }}{{ $this->enrollment ? ' · '.$this->enrollment->academicYear->name : '' }}</p>
        </div>
    </div>

    <nav class="-mx-4 mt-5 overflow-x-auto px-4" aria-label="Rubriques">
        <div class="flex w-max gap-1 rounded-xl bg-zinc-200/60 p-1 text-sm font-medium" role="tablist">
            @foreach ($this::TABS as $value => $label)
                <button
                    type="button"
                    role="tab"
                    wire:key="tab-{{ $value }}"
                    wire:click="$set('tab', '{{ $value }}')"
                    aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                    @class(['whitespace-nowrap rounded-lg px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-xs' => $tab === $value, 'text-zinc-600 hover:text-zinc-900' => $tab !== $value])
                >{{ $label }}</button>
            @endforeach
        </div>
    </nav>

    <div class="mt-5">
        @if ($this->enrollment === null)
            <x-ui.empty>{{ $student->first_name }} n'est pas inscrit(e) pour l'année en cours.</x-ui.empty>
        @elseif ($tab === 'bulletins')
            @forelse ($this->reports as $report)
                <x-ui.card :title="$report['period']->name" wire:key="report-{{ $report['period']->id }}" class="mb-4">
                    <x-slot:actions>
                        <a href="{{ route('parent.report-card', [$student, $report['period']]) }}" class="text-sm font-semibold text-brand-700 hover:underline">Bulletin PDF</a>
                    </x-slot:actions>
                    @if ($report['row'] === null || $report['row']['general'] === null)
                        <p class="text-sm text-zinc-500">Pas de moyenne sur cette période.</p>
                    @else
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <p class="text-xs text-zinc-500">Moyenne générale</p>
                                <p class="text-xl font-semibold text-brand-700">{{ $format($report['row']['general']) }}<span class="text-sm font-normal text-zinc-500">/{{ $this->scale }}</span></p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Rang</p>
                                <p class="text-xl font-semibold">{{ $ordinal($report['row']['rank']) }}<span class="text-sm font-normal text-zinc-500"> sur {{ $report['results']['graded'] }}</span></p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Mention</p>
                                <p class="text-base font-semibold">{{ $report['row']['mention']?->label() }}</p>
                            </div>
                        </div>
                        <table class="mt-4 min-w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                                <tr><th class="pb-2">Matière</th><th class="pb-2 text-center">Coef.</th><th class="pb-2 text-right">Moyenne</th><th class="pb-2 text-right">Classe</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @foreach ($report['results']['subjects'] as $line)
                                    @php($average = $report['row']['averages'][$line['subject']->id] ?? null)
                                    <tr wire:key="line-{{ $report['period']->id }}-{{ $line['subject']->id }}">
                                        <td class="py-2">{{ $line['subject']->name }}</td>
                                        <td class="py-2 text-center text-zinc-500">{{ $line['coefficient'] }}</td>
                                        <td @class(['py-2 text-right font-semibold tabular-nums', 'text-red-600' => $average !== null && $average < 10])>{{ $format($average) }}</td>
                                        <td class="py-2 text-right tabular-nums text-zinc-500">{{ $format($line['average']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-3 text-xs text-zinc-500">Moyenne de la classe : {{ $format($report['results']['class_average']) }} · plus forte : {{ $format($report['results']['highest']) }}</p>
                    @endif
                </x-ui.card>
            @empty
                <x-ui.empty icon="grades" title="Aucun bulletin pour l'instant">Les bulletins apparaissent ici dès que l'école a clôturé la période.</x-ui.empty>
            @endforelse
        @elseif ($tab === 'absences')
            @php($absent = $this->absences->where('status', \App\Enums\AttendanceStatus::Absent))
            <div class="mb-4 grid grid-cols-3 gap-3">
                <div class="card p-4"><p class="text-xs text-zinc-500">Absences</p><p class="mt-1 text-xl font-semibold">{{ $absent->count() }}</p></div>
                <div class="card p-4"><p class="text-xs text-zinc-500">Non justifiées</p><p @class(['mt-1 text-xl font-semibold', 'text-red-600' => $absent->where('is_justified', false)->isNotEmpty()])>{{ $absent->where('is_justified', false)->count() }}</p></div>
                <div class="card p-4"><p class="text-xs text-zinc-500">Retards</p><p class="mt-1 text-xl font-semibold">{{ $this->absences->where('status', \App\Enums\AttendanceStatus::Late)->count() }}</p></div>
            </div>
            @if ($this->absences->isEmpty())
                <x-ui.empty icon="check" title="Aucune absence">Aucune absence ni retard cette année.</x-ui.empty>
            @else
                <ul class="card divide-y divide-zinc-100 text-sm">
                    @foreach ($this->absences as $absence)
                        <li wire:key="absence-{{ $absence->id }}" class="flex flex-wrap items-center gap-2 px-4 py-3">
                            <span class="font-medium">{{ \Illuminate\Support\Str::ucfirst($absence->date->translatedFormat('l j F')) }}</span>
                            <span class="text-zinc-500">{{ mb_strtolower($absence->session->label()) }}</span>
                            <span class="ml-auto flex gap-1.5">
                                <x-ui.badge :tone="$absence->status->tone()">{{ $absence->status->label() }}</x-ui.badge>
                                @if ($absence->status === \App\Enums\AttendanceStatus::Absent)
                                    <x-ui.badge :tone="$absence->is_justified ? 'brand' : 'neutral'">{{ $absence->is_justified ? 'Justifiée' : 'Non justifiée' }}</x-ui.badge>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-zinc-500">Pour justifier une absence, adressez-vous à l'administration de l'école.</p>
            @endif
        @elseif ($tab === 'frais')
            @if ($this->statement === null || $this->statement['lines'] === [])
                <x-ui.empty icon="banknotes">Aucun frais n'est encore défini pour cette classe.</x-ui.empty>
            @else
                <div class="mb-4 grid grid-cols-3 gap-3">
                    <div class="card p-4"><p class="text-xs text-zinc-500">Payé</p><x-ui.money :amount="$this->statement['paid']" class="mt-1 block font-semibold text-brand-700" /></div>
                    <div class="card p-4"><p class="text-xs text-zinc-500">Reste à payer</p><x-ui.money :amount="max($this->statement['balance'], 0)" class="mt-1 block font-semibold" /></div>
                    <div class="card p-4"><p class="text-xs text-zinc-500">En retard</p><x-ui.money :amount="$this->statement['overdue']" :class="'mt-1 block font-semibold '.($this->statement['overdue'] > 0 ? 'text-red-600' : 'text-zinc-400')" /></div>
                </div>
                <x-ui.card title="Échéancier" class="mb-4">
                    <ul class="divide-y divide-zinc-100 text-sm">
                        @foreach ($this->statement['lines'] as $line)
                            <li wire:key="line-{{ $line['item']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                                <span class="font-medium">{{ $line['item']->label }}</span>
                                <span class="text-xs text-zinc-500">{{ $line['item']->due_on ? 'avant le '.$line['item']->due_on->format('d/m/Y') : 'à l\'inscription' }}</span>
                                <span class="ml-auto flex items-center gap-2">
                                    <x-ui.money :amount="$line['item']->amount" class="tabular-nums" />
                                    <x-ui.badge :tone="$line['status']->tone()">{{ $line['status']->label() }}</x-ui.badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
                <x-ui.card title="Paiements et reçus">
                    @if ($this->payments->isEmpty())
                        <p class="text-sm text-zinc-500">Aucun paiement cette année.</p>
                    @else
                        <ul class="divide-y divide-zinc-100 text-sm">
                            @foreach ($this->payments as $payment)
                                <li wire:key="payment-{{ $payment->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                                    <x-ui.money :amount="$payment->amount" :class="$payment->isCancelled() ? 'text-zinc-400 line-through' : 'font-semibold'" />
                                    <span class="text-zinc-500">{{ $payment->paid_on->format('d/m/Y') }} · {{ $payment->method->label() }}</span>
                                    @if ($payment->isCancelled())
                                        <x-ui.badge tone="red">Annulé</x-ui.badge>
                                    @endif
                                    <a href="{{ route('parent.receipt', $payment) }}" class="ml-auto font-semibold text-brand-700 hover:underline">Reçu {{ $payment->receipt_number }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif
        @elseif ($tab === 'exercices')
            @forelse ($this->homework as $exercise)
                <article wire:key="homework-{{ $exercise->id }}" class="card mb-3 p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge tone="brand">{{ $exercise->subject->name }}</x-ui.badge>
                        <span class="text-xs text-zinc-500">Donné le {{ $exercise->given_on->format('d/m') }}</span>
                        @if ($exercise->due_on)
                            @php($soon = $exercise->due_on->isToday() || $exercise->due_on->isTomorrow())
                            <span @class(['ml-auto text-xs font-semibold', 'text-red-600' => $soon, 'text-zinc-400' => ! $soon && $exercise->due_on->isPast(), 'text-zinc-700' => ! $soon && ! $exercise->due_on->isPast()])>
                                À rendre {{ $exercise->due_on->isToday() ? 'aujourd\'hui' : ($exercise->due_on->isTomorrow() ? 'demain' : 'le '.$exercise->due_on->translatedFormat('l j F')) }}
                            </span>
                        @endif
                    </div>
                    <h2 class="mt-2 font-semibold">{{ $exercise->title }}</h2>
                    @if ($exercise->instructions)
                        <p class="mt-1 whitespace-pre-line text-sm text-zinc-600">{{ $exercise->instructions }}</p>
                    @endif
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-zinc-500">
                        @if ($exercise->author)
                            <span>{{ $exercise->author->name }}</span>
                        @endif
                        @if ($exercise->attachment_path)
                            <a href="{{ route('parent.homework-file', $exercise) }}" class="font-semibold text-brand-700 hover:underline">Télécharger : {{ $exercise->attachment_name }}</a>
                        @endif
                    </div>
                </article>
            @empty
                <x-ui.empty icon="book-open" title="Aucun exercice">Aucun exercice récent pour cette classe.</x-ui.empty>
            @endforelse
        @else
            @if ($this->week->isEmpty())
                <x-ui.empty icon="calendar">L'emploi du temps de la classe n'est pas encore publié.</x-ui.empty>
            @else
                <div class="space-y-3">
                    @foreach (\App\Models\TimetableEntry::DAYS as $number => $label)
                        @if ($this->week->has($number))
                            <x-ui.card :title="$label" wire:key="day-{{ $number }}">
                                <ul class="divide-y divide-zinc-100 text-sm">
                                    @foreach ($this->week->get($number) as $entry)
                                        <li wire:key="entry-{{ $entry->id }}" class="flex items-center gap-3 py-2">
                                            <span class="w-24 shrink-0 text-xs font-semibold tabular-nums text-brand-700">{{ substr($entry->starts_at, 0, 5) }} – {{ substr($entry->ends_at, 0, 5) }}</span>
                                            <span class="font-medium">{{ $entry->subject->name }}</span>
                                            <span class="ml-auto truncate text-xs text-zinc-500">{{ $entry->teacher?->name }}{{ $entry->room ? ' · '.$entry->room : '' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </x-ui.card>
                        @endif
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
