<?php

use App\Models\AcademicYear;
use App\Models\GradingPeriod;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Bulletins')] class extends Component
{
    #[Url(as: 'classe', except: '')]
    public string $classId = '';

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
     * @return Collection<int, SchoolClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        return SchoolClass::whereBelongsTo($this->currentYear)->get()->sortBy(fn (SchoolClass $class) => [$class->level->rank(), $class->name])->values();
    }

    #[Computed]
    public function schoolClass(): ?SchoolClass
    {
        return $this->classes->firstWhere('id', (int) $this->classId) ?? $this->classes->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function results(): ?array
    {
        return $this->schoolClass && $this->period ? $this->period->classResults($this->schoolClass) : null;
    }

    #[Computed]
    public function scale(): int
    {
        return $this->schoolClass ? auth()->user()->school->scaleFor($this->schoolClass->cycle) : 20;
    }

    /**
     * Locking freezes every mark of the period, for all classes, before report cards are handed out.
     */
    public function toggleLock(): void
    {
        Gate::authorize('manage-whole-school');

        $this->period->forceFill(['locked_at' => $this->period->isLocked() ? null : now()])->save();
        unset($this->periods, $this->period);
    }
};
?>

<div>
    <x-ui.page-header title="Bulletins" :description="$this->period ? $this->period->name.' · '.$this->currentYear->name : null">
        <x-ui.button variant="secondary" :href="route('grades.index', ['periode' => $this->period?->id])">Saisie des notes</x-ui.button>
    </x-ui.page-header>

    @if (! $this->currentYear || $this->classes->isEmpty())
        <x-ui.empty>Aucune classe pour l'année en cours.</x-ui.empty>
    @else
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center">
            <select wire:model.live="classId" aria-label="Classe" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none lg:w-48">
                @foreach ($this->classes as $class)
                    <option value="{{ $class->id }}" wire:key="class-{{ $class->id }}" @selected($class->is($this->schoolClass))>{{ $class->name }}</option>
                @endforeach
            </select>

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

            <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
                @can('manage-whole-school')
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        wire:click="toggleLock"
                        wire:confirm="{{ $this->period->isLocked() ? 'Déverrouiller le '.$this->period->name.' ? Les enseignants pourront de nouveau modifier les notes.' : 'Verrouiller le '.$this->period->name.' ? Plus personne ne pourra modifier les notes de cette période, dans toutes les classes.' }}"
                    >{{ $this->period->isLocked() ? 'Déverrouiller la période' : 'Verrouiller la période' }}</x-ui.button>
                @else
                    @if ($this->period->isLocked())
                        <x-ui.badge tone="amber">Période verrouillée</x-ui.badge>
                    @endif
                @endcan
                <a href="{{ route('grades.report-cards', [$this->schoolClass, $this->period]) }}" target="_blank" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-900/20 transition hover:bg-brand-700">
                    <x-ui.icon name="grades" class="size-4.5" /> Imprimer les bulletins
                </a>
                <a href="{{ route('grades.report-cards.pdf', [$this->schoolClass, $this->period]) }}" class="text-sm font-semibold text-brand-700 hover:underline">PDF de la classe</a>
            </div>
        </div>

        @unless ($this->period->isLocked())
            <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                La période n'est pas verrouillée : les notes peuvent encore changer. Verrouillez-la avant de remettre les bulletins.
            </p>
        @endunless

        @php($format = fn (?float $value) => $value === null ? '—' : number_format($value * $this->scale / 20, 2, ',', ' '))
        <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['label' => 'Moyenne de la classe', 'value' => $format($this->results['class_average'])],
                ['label' => 'Plus forte moyenne', 'value' => $format($this->results['highest'])],
                ['label' => 'Plus faible moyenne', 'value' => $format($this->results['lowest'])],
                ['label' => 'Élèves classés', 'value' => $this->results['graded'].' / '.count($this->results['students'])],
            ] as $stat)
                <div class="card p-4" wire:key="stat-{{ $loop->index }}">
                    <p class="text-xs text-zinc-500">{{ $stat['label'] }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>

        @if ($this->results['students'] === [])
            <x-ui.empty>Aucun élève dans cette classe.</x-ui.empty>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="px-4 py-3">Rang</th>
                            <th class="px-4 py-3">Élève</th>
                            <th class="px-4 py-3 text-right">Moyenne /{{ $this->scale }}</th>
                            <th class="px-4 py-3">Mention</th>
                            <th class="px-4 py-3 text-right">Matières notées</th>
                            <th class="px-4 py-3"><span class="sr-only">Bulletin</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach (collect($this->results['students'])->sortBy(fn ($row) => $row['rank'] ?? PHP_INT_MAX) as $row)
                            <tr wire:key="row-{{ $row['student']->id }}" class="hover:bg-zinc-50">
                                <td class="px-4 py-3 font-semibold tabular-nums">{{ $row['rank'] ? ($row['rank'] === 1 ? '1er' : $row['rank'].'e') : '—' }}</td>
                                <td class="px-4 py-3 font-medium">{{ $row['student']->full_name }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $format($row['general']) }}</td>
                                <td class="px-4 py-3">{{ $row['mention']?->label() ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-zinc-600">{{ count(array_filter($row['averages'], fn ($average) => $average !== null)) }} / {{ count($this->results['subjects']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="{{ route('grades.report-cards', [$this->schoolClass, $this->period, 'eleve' => $row['student']->id]) }}" target="_blank" class="font-medium text-brand-700 hover:underline">Bulletin</a>
                                    <a href="{{ route('grades.report-cards.pdf', [$this->schoolClass, $this->period, 'eleve' => $row['student']->id]) }}" class="ml-3 font-medium text-brand-700 hover:underline">PDF</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
