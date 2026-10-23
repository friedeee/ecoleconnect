<?php

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SchoolClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Frais de scolarité')] class extends Component
{
    use WithPagination;

    #[Url(as: 'vue', except: 'impayes')]
    public string $tab = 'impayes';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'classe', except: '')]
    public string $classId = '';

    #[Url(as: 'du', except: '')]
    public string $from = '';

    #[Url(as: 'au', except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'classId', 'from', 'to'], true)) {
            $this->resetPage();
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
        return SchoolClass::whereBelongsTo($this->currentYear)->get()->sortBy(fn (SchoolClass $class) => [$class->level->rank(), $class->name])->values();
    }

    /**
     * Year totals over the enrollments the user can see.
     *
     * @return array{due: int, paid: int, overdue: int, late_students: int, today: int}
     */
    #[Computed]
    public function totals(): array
    {
        $totals = DB::query()
            ->fromSub(Enrollment::withFeeTotals()->whereBelongsTo($this->currentYear), 'accounts')
            ->selectRaw('coalesce(sum(fees_due), 0) as due')
            ->selectRaw('coalesce(sum(least(fees_paid, fees_due)), 0) as paid')
            ->selectRaw('coalesce(sum(greatest(fees_due_to_date - fees_paid, 0)), 0) as overdue')
            ->selectRaw('count(*) filter (where fees_due_to_date > fees_paid) as late_students')
            ->first();

        return [
            'due' => (int) $totals->due,
            'paid' => (int) $totals->paid,
            'overdue' => (int) $totals->overdue,
            'late_students' => (int) $totals->late_students,
            'today' => (int) Payment::valid()->whereBelongsTo($this->currentYear)->whereDate('paid_on', today())->sum('amount'),
        ];
    }

    /**
     * Students who owe money that is already due, the largest debts first.
     *
     * @return LengthAwarePaginator<int, Enrollment>
     */
    #[Computed]
    public function unpaid(): LengthAwarePaginator
    {
        return Enrollment::query()
            ->fromSub(Enrollment::withFeeTotals()->whereBelongsTo($this->currentYear), 'enrollments')
            ->whereColumn('fees_due_to_date', '>', 'fees_paid')
            ->when($this->classId !== '', fn (Builder $query) => $query->where('school_class_id', (int) $this->classId))
            ->when($this->search !== '', fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query->search($this->search)))
            ->with(['student.guardians', 'schoolClass'])
            ->orderByRaw('fees_due_to_date - fees_paid desc')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        return $this->paymentsQuery()
            ->with(['student', 'receiver'])
            ->latest('paid_on')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * Valid payments matching the filters, for the total under the list.
     */
    #[Computed]
    public function paymentsTotal(): int
    {
        return (int) $this->paymentsQuery()->valid()->sum('amount');
    }

    /**
     * @return Builder<Payment>
     */
    protected function paymentsQuery(): Builder
    {
        return Payment::whereBelongsTo($this->currentYear)
            ->when($this->from !== '' && strtotime($this->from), fn (Builder $query) => $query->whereDate('paid_on', '>=', $this->from))
            ->when($this->to !== '' && strtotime($this->to), fn (Builder $query) => $query->whereDate('paid_on', '<=', $this->to))
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('receipt_number', '%'.$this->search.'%')
                ->orWhereHas('student', fn (Builder $query) => $query->search($this->search))));
    }
};
?>

<div>
    <x-ui.page-header title="Frais de scolarité" :description="$this->currentYear ? 'Année scolaire '.$this->currentYear->name : null">
        @can('manage-fee-schedule')
            <x-ui.button variant="secondary" :href="route('fees.schedule')">Barème des frais</x-ui.button>
        @endcan
    </x-ui.page-header>

    @if (! $this->currentYear)
        <x-ui.empty>Aucune année scolaire active.</x-ui.empty>
    @else
        @php($rate = $this->totals['due'] > 0 ? round($this->totals['paid'] / $this->totals['due'] * 100) : 0)
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="card p-5">
                <p class="text-sm text-zinc-500">Attendu sur l'année</p>
                <x-ui.money :amount="$this->totals['due']" class="mt-2 block text-xl font-semibold" />
            </div>
            <div class="card p-5">
                <p class="text-sm text-zinc-500">Encaissé · {{ $rate }} %</p>
                <x-ui.money :amount="$this->totals['paid']" class="mt-2 block text-xl font-semibold text-brand-700" />
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100" role="progressbar" aria-valuenow="{{ $rate }}" aria-valuemin="0" aria-valuemax="100" aria-label="Taux de recouvrement">
                    <div class="h-full rounded-full bg-brand-500" style="width: {{ min($rate, 100) }}%"></div>
                </div>
            </div>
            <div class="card p-5">
                <p class="text-sm text-zinc-500">En retard · {{ $this->totals['late_students'] }} élève(s)</p>
                <x-ui.money :amount="$this->totals['overdue']" :class="'mt-2 block text-xl font-semibold '.($this->totals['overdue'] > 0 ? 'text-red-600' : '')" />
            </div>
            <div class="card p-5">
                <p class="text-sm text-zinc-500">Encaissé aujourd'hui</p>
                <x-ui.money :amount="$this->totals['today']" class="mt-2 block text-xl font-semibold" />
            </div>
        </div>

        <div class="mt-8 mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="flex rounded-lg bg-zinc-200/60 p-1 text-sm font-medium" role="tablist">
                @foreach (['impayes' => 'Impayés', 'encaissements' => 'Encaissements'] as $value => $label)
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

            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ $tab === 'impayes' ? 'Rechercher un élève…' : 'Élève ou n° de reçu…' }}"
                aria-label="Rechercher"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 lg:max-w-xs"
            >

            @if ($tab === 'impayes')
                <select wire:model.live="classId" aria-label="Filtrer par classe" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none lg:w-48">
                    <option value="">Toutes les classes</option>
                    @foreach ($this->classes as $class)
                        <option value="{{ $class->id }}" wire:key="class-{{ $class->id }}">{{ $class->name }}</option>
                    @endforeach
                </select>
            @else
                <div class="flex items-center gap-2 text-sm text-zinc-600">
                    <label for="from">Du</label>
                    <input id="from" type="date" wire:model.live="from" class="rounded-lg border border-zinc-300 bg-white px-2.5 py-2 text-sm focus:border-brand-500 focus:outline-none">
                    <label for="to">au</label>
                    <input id="to" type="date" wire:model.live="to" class="rounded-lg border border-zinc-300 bg-white px-2.5 py-2 text-sm focus:border-brand-500 focus:outline-none">
                </div>
            @endif
        </div>

        @if ($tab === 'impayes')
            @if ($this->unpaid->isEmpty())
                <x-ui.empty icon="check" title="Aucun impayé">Aucun élève n'a de frais échus non réglés{{ $search !== '' || $classId !== '' ? ' pour ces filtres' : '' }}.</x-ui.empty>
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Élève</th>
                                <th class="px-4 py-3">Classe</th>
                                <th class="px-4 py-3 text-right">Dû à ce jour</th>
                                <th class="px-4 py-3 text-right">Payé</th>
                                <th class="px-4 py-3 text-right">Retard</th>
                                <th class="px-4 py-3">Contact parent</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($this->unpaid as $account)
                                @php($guardian = $account->student->guardians->firstWhere('pivot.is_primary_contact', true) ?? $account->student->guardians->first())
                                <tr wire:key="unpaid-{{ $account->id }}" class="hover:bg-zinc-50">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('students.show', $account->student) }}" wire:navigate class="font-medium text-zinc-900 hover:text-brand-700">{{ $account->student->full_name }}</a>
                                        <p class="font-mono text-xs text-zinc-500">{{ $account->student->registration_number }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $account->schoolClass->name }}</td>
                                    <td class="px-4 py-3 text-right"><x-ui.money :amount="$account->fees_due_to_date" /></td>
                                    <td class="px-4 py-3 text-right text-zinc-600"><x-ui.money :amount="$account->fees_paid" /></td>
                                    <td class="px-4 py-3 text-right font-semibold text-red-600"><x-ui.money :amount="$account->fees_due_to_date - $account->fees_paid" /></td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        @if ($guardian)
                                            <a href="tel:{{ $guardian->phone }}" class="text-brand-700 hover:underline">{{ $guardian->phone }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$this->unpaid" noun="élèves en retard" class="mt-4" />
            @endif
        @else
            @if ($this->payments->isEmpty())
                <x-ui.empty icon="banknotes" title="Aucun encaissement">Aucun paiement ne correspond à ces filtres.</x-ui.empty>
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Reçu</th>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Élève</th>
                                <th class="px-4 py-3">Mode</th>
                                <th class="px-4 py-3 text-right">Montant</th>
                                <th class="px-4 py-3">Encaissé par</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($this->payments as $payment)
                                <tr wire:key="payment-{{ $payment->id }}" @class(['hover:bg-zinc-50', 'text-zinc-400' => $payment->isCancelled()])>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <a href="{{ route('fees.receipt', $payment) }}" target="_blank" class="font-mono text-xs text-brand-700 hover:underline">{{ $payment->receipt_number }}</a>
                                        @if ($payment->isCancelled())
                                            <x-ui.badge tone="red" class="ml-1">Annulé</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $payment->paid_on->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('students.show', $payment->student_id) }}" wire:navigate class="font-medium hover:text-brand-700">{{ $payment->student?->full_name ?? '—' }}</a>
                                    </td>
                                    <td class="px-4 py-3">{{ $payment->method->label() }}</td>
                                    <td class="px-4 py-3 text-right"><x-ui.money :amount="$payment->amount" :class="$payment->isCancelled() ? 'line-through' : 'font-semibold'" /></td>
                                    <td class="px-4 py-3 text-zinc-600">{{ $payment->receiver->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-zinc-600">Total encaissé (hors annulés) : <x-ui.money :amount="$this->paymentsTotal" class="font-semibold text-zinc-900" /></p>
                    <x-ui.pagination :paginator="$this->payments" noun="paiements" />
                </div>
            @endif
        @endif
    @endif
</div>
