<?php

use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\FeeItem;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public Enrollment $enrollment;

    public bool $showForm = false;

    public string $amount = '';

    public string $paid_on = '';

    public string $method = 'cash';

    public string $reference = '';

    public string $notes = '';

    public ?int $cancellingId = null;

    public string $cancellation_reason = '';

    /** Receipt of the payment just recorded, offered for printing. */
    #[Locked]
    public ?int $lastPaymentId = null;

    public function mount(Enrollment $enrollment): void
    {
        $this->enrollment = $enrollment->load(['schoolClass', 'academicYear']);
    }

    /**
     * @return array{lines: list<array{item: FeeItem, paid: int, remaining: int, status: \App\Enums\FeeLineStatus}>, due: int, paid: int, balance: int, overdue: int}
     */
    #[Computed]
    public function statement(): array
    {
        return $this->enrollment->feeStatement();
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return Payment::with('receiver')
            ->where('student_id', $this->enrollment->student_id)
            ->whereBelongsTo($this->enrollment->academicYear)
            ->latest('paid_on')
            ->latest('id')
            ->get();
    }

    /**
     * Optional fees offered at the student's level, whether taken or not.
     *
     * @return Collection<int, FeeItem>
     */
    #[Computed]
    public function optionalFees(): Collection
    {
        return FeeItem::whereBelongsTo($this->enrollment->academicYear)
            ->where('level', $this->enrollment->schoolClass->level)
            ->where('is_optional', true)
            ->orderBy('label')
            ->get();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function takenOptionalFeeIds(): array
    {
        return $this->enrollment->optionalFees()->pluck('fee_items.id')->all();
    }

    public function openForm(): void
    {
        Gate::authorize('collect-payments');

        $this->resetValidation();
        $this->amount = (string) ($this->statement['overdue'] ?: max($this->statement['balance'], 0) ?: '');
        $this->paid_on = today()->format('Y-m-d');
        $this->method = PaymentMethod::Cash->value;
        $this->reference = '';
        $this->notes = '';
        $this->showForm = true;
    }

    public function record(): void
    {
        Gate::authorize('collect-payments');

        $validated = $this->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => [Rule::requiredIf(PaymentMethod::tryFrom($this->method)?->needsReference() ?? false), 'nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'reference.required' => 'Notez le numéro de transaction ou de chèque.',
        ], ['amount' => 'montant', 'paid_on' => 'date', 'method' => 'mode de paiement', 'reference' => 'référence', 'notes' => 'objet']);

        $payment = Payment::record($this->enrollment, [
            'amount' => (int) $validated['amount'],
            'paid_on' => $validated['paid_on'],
            'method' => $validated['method'],
            'reference' => trim($validated['reference']) ?: null,
            'notes' => trim($validated['notes']) ?: null,
        ], auth()->user());

        $this->lastPaymentId = $payment->id;
        $this->showForm = false;
        unset($this->statement, $this->payments);
    }

    public function toggleOptionalFee(int $feeItemId): void
    {
        Gate::authorize('collect-payments');

        abort_unless($this->optionalFees->contains('id', $feeItemId), 404);

        $this->enrollment->optionalFees()->toggle([$feeItemId]);
        unset($this->statement, $this->takenOptionalFeeIds);
    }

    public function startCancelling(int $paymentId): void
    {
        Gate::authorize('collect-payments');

        $this->cancellingId = $paymentId;
        $this->cancellation_reason = '';
        $this->resetValidation();
    }

    public function cancelPayment(): void
    {
        Gate::authorize('collect-payments');

        $this->validate(['cancellation_reason' => ['required', 'string', 'min:5', 'max:255']], attributes: ['cancellation_reason' => 'motif']);

        $payment = $this->payments->firstWhere('id', $this->cancellingId);

        abort_if($payment === null || $payment->isCancelled(), 404);

        $payment->cancel(auth()->user(), trim($this->cancellation_reason));

        $this->reset(['cancellingId', 'cancellation_reason', 'lastPaymentId']);
        unset($this->statement, $this->payments);
    }
};
?>

<x-ui.card title="Frais de scolarité {{ $enrollment->academicYear->name }}" icon="banknotes" class="lg:col-span-3">
    <x-slot:actions>
        @can('collect-payments')
            @unless ($showForm)
                <x-ui.button type="button" wire:click="openForm" icon="plus">Encaisser</x-ui.button>
            @endunless
        @endcan
    </x-slot:actions>

    @if ($lastPaymentId)
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">
            <x-ui.icon name="check" class="size-5 text-brand-600" />
            <span class="flex-1 font-medium">Paiement enregistré.</span>
            <a href="{{ route('fees.receipt', $lastPaymentId) }}" target="_blank" class="font-semibold underline">Imprimer le reçu</a>
            <a href="{{ route('fees.receipt.pdf', $lastPaymentId) }}" class="font-semibold underline">PDF</a>
        </div>
    @endif

    @if ($showForm)
        <form wire:submit="record" class="mb-6 grid gap-4 rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.input label="Montant (F CFA)" name="amount" type="number" min="1" step="1" inputmode="numeric" />
            <x-ui.input label="Date" name="paid_on" type="date" />
            <x-ui.select label="Mode de paiement" name="method">
                @foreach (\App\Enums\PaymentMethod::cases() as $option)
                    <option value="{{ $option->value }}" wire:key="method-{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Référence" name="reference" placeholder="N° de transaction ou de chèque" hint="Obligatoire sauf en espèces." />
            <x-ui.input label="Objet (facultatif)" name="notes" placeholder="ex. 2e tranche" class="sm:col-span-2 lg:col-span-3" />
            <div class="flex items-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)">Annuler</x-ui.button>
                <x-ui.button class="flex-1">Valider</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->statement['lines'] === [])
        <p class="text-sm text-zinc-500">
            Aucun frais n'est défini pour le niveau {{ $enrollment->schoolClass->level->value }} cette année.
            @can('manage-fee-schedule')
                <a href="{{ route('fees.schedule', ['niveau' => $enrollment->schoolClass->level->value]) }}" wire:navigate class="font-medium text-brand-700 hover:underline">Définir le barème</a>
            @endcan
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-4">
            @foreach ([
                ['label' => 'Total de l\'année', 'amount' => $this->statement['due'], 'class' => 'text-zinc-900'],
                ['label' => 'Déjà payé', 'amount' => $this->statement['paid'], 'class' => 'text-brand-700'],
                ['label' => 'Reste à payer', 'amount' => max($this->statement['balance'], 0), 'class' => 'text-zinc-900'],
                ['label' => 'En retard', 'amount' => $this->statement['overdue'], 'class' => $this->statement['overdue'] > 0 ? 'text-red-600' : 'text-zinc-400'],
            ] as $total)
                <div wire:key="total-{{ $loop->index }}">
                    <p class="text-xs text-zinc-500">{{ $total['label'] }}</p>
                    <x-ui.money :amount="$total['amount']" :class="'mt-0.5 block text-lg font-semibold '.$total['class']" />
                </div>
            @endforeach
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="pb-2">Frais</th>
                        <th class="pb-2">Échéance</th>
                        <th class="pb-2 text-right">Montant</th>
                        <th class="pb-2 text-right">Payé</th>
                        <th class="pb-2 pl-4">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($this->statement['lines'] as $line)
                        <tr wire:key="line-{{ $line['item']->id }}">
                            <td class="py-2 font-medium">
                                {{ $line['item']->label }}
                                @if ($line['item']->is_optional)
                                    <span class="ml-1 text-xs font-normal text-zinc-500">(optionnel)</span>
                                @endif
                            </td>
                            <td class="py-2 text-zinc-600">{{ $line['item']->due_on?->format('d/m/Y') ?? 'À l\'inscription' }}</td>
                            <td class="py-2 text-right"><x-ui.money :amount="$line['item']->amount" /></td>
                            <td class="py-2 text-right text-zinc-600"><x-ui.money :amount="$line['paid']" /></td>
                            <td class="py-2 pl-4"><x-ui.badge :tone="$line['status']->tone()">{{ $line['status']->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($this->optionalFees->isNotEmpty())
        <div class="mt-6">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Frais optionnels</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->optionalFees as $fee)
                    @php($taken = in_array($fee->id, $this->takenOptionalFeeIds, true))
                    <button
                        type="button"
                        wire:key="optional-{{ $fee->id }}"
                        wire:click="toggleOptionalFee({{ $fee->id }})"
                        @cannot('collect-payments') disabled @endcannot
                        @class([
                            'inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm ring-1 ring-inset transition disabled:cursor-default',
                            'bg-brand-50 text-brand-800 ring-brand-300' => $taken,
                            'text-zinc-600 ring-zinc-300 hover:bg-zinc-50' => ! $taken,
                        ])
                        aria-pressed="{{ $taken ? 'true' : 'false' }}"
                    >
                        <span @class(['flex size-4 items-center justify-center rounded border', 'border-brand-600 bg-brand-600 text-white' => $taken, 'border-zinc-300' => ! $taken])>
                            @if ($taken)<x-ui.icon name="check" class="size-3" />@endif
                        </span>
                        {{ $fee->label }} · <x-ui.money :amount="$fee->amount" />
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mt-6">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Paiements</p>
        @if ($this->payments->isEmpty())
            <p class="text-sm text-zinc-500">Aucun paiement cette année.</p>
        @else
            <ul class="divide-y divide-zinc-100 text-sm">
                @foreach ($this->payments as $payment)
                    <li wire:key="payment-{{ $payment->id }}" class="py-2.5">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <x-ui.money :amount="$payment->amount" :class="$payment->isCancelled() ? 'font-semibold text-zinc-400 line-through' : 'font-semibold'" />
                            <span class="text-zinc-600">{{ $payment->paid_on->format('d/m/Y') }} · {{ $payment->method->label() }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</span>
                            <span class="font-mono text-xs text-zinc-500">N° {{ $payment->receipt_number }}</span>
                            @if ($payment->isCancelled())
                                <x-ui.badge tone="red">Annulé</x-ui.badge>
                            @endif
                            <span class="ml-auto flex gap-3">
                                <a href="{{ route('fees.receipt', $payment) }}" target="_blank" class="font-medium text-brand-700 hover:underline">Reçu</a>
                                @can('collect-payments')
                                    @unless ($payment->isCancelled())
                                        <button type="button" wire:click="startCancelling({{ $payment->id }})" class="font-medium text-red-600 hover:underline">Annuler</button>
                                    @endunless
                                @endcan
                            </span>
                        </div>
                        @if ($payment->isCancelled())
                            <p class="mt-1 text-xs text-red-700">Motif : {{ $payment->cancellation_reason }}</p>
                        @endif
                        @if ($cancellingId === $payment->id)
                            <form wire:submit="cancelPayment" class="mt-3 flex flex-col gap-2 rounded-lg bg-red-50 p-3 sm:flex-row sm:items-start">
                                <x-ui.input label="Motif de l'annulation" name="cancellation_reason" placeholder="ex. Erreur de montant, chèque rejeté…" class="flex-1" />
                                <div class="flex gap-2 sm:pt-7">
                                    <x-ui.button type="button" variant="secondary" wire:click="$set('cancellingId', null)">Retour</x-ui.button>
                                    <x-ui.button variant="danger">Annuler le paiement</x-ui.button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-ui.card>
