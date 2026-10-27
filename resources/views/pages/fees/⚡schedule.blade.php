<?php

use App\Models\ActivityLog;
use App\Enums\ClassLevel;
use App\Models\AcademicYear;
use App\Models\FeeItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Barème des frais')] class extends Component
{
    #[Url(as: 'niveau')]
    public string $level = '';

    #[Locked]
    public ?int $editingId = null;

    public string $label = '';

    public string $amount = '';

    public string $due_on = '';

    public bool $is_optional = false;

    /** @var list<string> */
    public array $copyTo = [];

    public function mount(): void
    {
        if (ClassLevel::tryFrom($this->level) === null || ! in_array(ClassLevel::from($this->level), $this->levels, true)) {
            $this->level = $this->levels[0]->value ?? '';
        }
    }

    #[Computed]
    public function currentYear(): ?AcademicYear
    {
        return auth()->user()->school->currentAcademicYear;
    }

    /**
     * Levels of the cycles the school teaches.
     *
     * @return list<ClassLevel>
     */
    #[Computed]
    public function levels(): array
    {
        return array_values(array_filter(ClassLevel::cases(), fn (ClassLevel $level) => auth()->user()->school->teaches($level->cycle())));
    }

    /**
     * Compulsory total and number of lines per level, for the overview.
     *
     * @return array<string, array{total: int, lines: int}>
     */
    #[Computed]
    public function summary(): array
    {
        return FeeItem::whereBelongsTo($this->currentYear)
            ->selectRaw('level, sum(case when is_optional then 0 else amount end) as total, count(*) as lines')
            ->groupBy('level')
            ->get()
            ->mapWithKeys(fn (FeeItem $item) => [$item->level->value => ['total' => (int) $item->total, 'lines' => (int) $item->lines]])
            ->all();
    }

    /**
     * @return Collection<int, FeeItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return FeeItem::whereBelongsTo($this->currentYear)
            ->where('level', $this->level)
            ->orderBy('is_optional')
            ->orderByRaw('due_on asc nulls first')
            ->orderBy('id')
            ->get();
    }

    public function selectLevel(string $level): void
    {
        abort_unless(in_array(ClassLevel::tryFrom($level), $this->levels, true), 404);

        $this->level = $level;
        $this->cancel();
        $this->copyTo = [];
        unset($this->items);
    }

    public function edit(int $itemId): void
    {
        $item = $this->items->firstWhere('id', $itemId) ?? abort(404);

        $this->editingId = $item->id;
        $this->label = $item->label;
        $this->amount = (string) $item->amount;
        $this->due_on = $item->due_on?->format('Y-m-d') ?? '';
        $this->is_optional = $item->is_optional;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'label', 'amount', 'due_on', 'is_optional']);
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-fee-schedule');

        $validated = $this->validate([
            'label' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'due_on' => ['nullable', 'date'],
            'is_optional' => ['boolean'],
        ], attributes: ['label' => 'libellé', 'amount' => 'montant', 'due_on' => 'échéance']);

        $item = $this->editingId ? ($this->items->firstWhere('id', $this->editingId) ?? abort(404)) : new FeeItem;
        $before = $item->exists ? $item->label.' : '.number_format($item->amount, 0, ',', ' ').' F CFA' : null;
        $item->fill([
            'academic_year_id' => $this->currentYear->id,
            'level' => $this->level,
            'label' => trim($validated['label']),
            'amount' => (int) $validated['amount'],
            'due_on' => $validated['due_on'] ?: null,
            'is_optional' => $validated['is_optional'],
        ])->save();

        ActivityLog::record('fees.schedule-changed', 'Barème '.$this->level.' : « '.$item->label.' » '.($before ? 'modifié' : 'ajouté').' — '.number_format($item->amount, 0, ',', ' ').' F CFA', $item, $before ? ['changes' => [['student' => 'Avant', 'old' => $before, 'new' => $item->label.' : '.number_format($item->amount, 0, ',', ' ').' F CFA']]] : []);

        $this->cancel();
        unset($this->items, $this->summary);
    }

    public function delete(int $itemId): void
    {
        Gate::authorize('manage-fee-schedule');

        $item = $this->items->firstWhere('id', $itemId) ?? abort(404);
        $item->delete();

        ActivityLog::record('fees.schedule-changed', 'Barème '.$this->level.' : « '.$item->label.' » ('.number_format($item->amount, 0, ',', ' ').' F CFA) supprimé', $item);
        unset($this->items, $this->summary);
    }

    /**
     * Copies the current level's schedule to levels that have none yet.
     */
    public function copy(): void
    {
        Gate::authorize('manage-fee-schedule');

        $emptyLevels = array_values(array_filter(
            array_column($this->levels, 'value'),
            fn (string $level) => $level !== $this->level && ! isset($this->summary[$level]),
        ));

        $this->validate(
            ['copyTo' => ['required', 'array', 'min:1'], 'copyTo.*' => [Rule::in($emptyLevels)]],
            ['copyTo.required' => 'Cochez au moins un niveau.'],
        );

        foreach ($this->copyTo as $level) {
            foreach ($this->items as $item) {
                $item->replicate()->fill(['level' => $level])->save();
            }
        }

        ActivityLog::record('fees.schedule-changed', 'Barème '.$this->level.' copié vers '.implode(', ', $this->copyTo));

        $this->copyTo = [];
        unset($this->summary);
    }
};
?>

<div>
    <x-ui.page-header eyebrow="Frais de scolarité" title="Barème des frais" :description="$this->currentYear ? 'Année scolaire '.$this->currentYear->name.' · montants en F CFA' : null">
        <x-ui.button variant="secondary" :href="route('fees.index')">Retour aux frais</x-ui.button>
    </x-ui.page-header>

    @if (! $this->currentYear)
        <x-ui.empty>Aucune année scolaire active.</x-ui.empty>
    @else
        <div class="grid gap-6 lg:grid-cols-4">
            <nav class="card p-2 lg:self-start" aria-label="Niveaux">
                @foreach ($this->levels as $option)
                    <button
                        type="button"
                        wire:key="level-{{ $option->value }}"
                        wire:click="selectLevel('{{ $option->value }}')"
                        @class([
                            'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm transition',
                            'bg-brand-50 font-semibold text-brand-900' => $option->value === $level,
                            'text-zinc-700 hover:bg-zinc-50' => $option->value !== $level,
                        ])
                    >
                        <span>{{ $option->value }}</span>
                        @isset($this->summary[$option->value])
                            <x-ui.money :amount="$this->summary[$option->value]['total']" class="text-xs text-zinc-500" />
                        @else
                            <span class="text-xs text-amber-600">À définir</span>
                        @endisset
                    </button>
                @endforeach
            </nav>

            <div class="space-y-6 lg:col-span-3">
                <x-ui.card :title="'Frais du niveau '.$level">
                    @if ($this->items->isEmpty())
                        <p class="text-sm text-zinc-500">Aucun frais pour ce niveau. Ajoutez par exemple l'inscription puis chaque tranche de scolarité avec sa date limite.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                                    <tr>
                                        <th class="pb-2">Libellé</th>
                                        <th class="pb-2">Échéance</th>
                                        <th class="pb-2 text-right">Montant</th>
                                        <th class="pb-2"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100">
                                    @foreach ($this->items as $item)
                                        <tr wire:key="item-{{ $item->id }}" @class(['bg-brand-50/50' => $editingId === $item->id])>
                                            <td class="py-2 font-medium">
                                                {{ $item->label }}
                                                @if ($item->is_optional)
                                                    <x-ui.badge class="ml-1">Optionnel</x-ui.badge>
                                                @endif
                                            </td>
                                            <td class="py-2 text-zinc-600">{{ $item->due_on?->format('d/m/Y') ?? 'À l\'inscription' }}</td>
                                            <td class="py-2 text-right"><x-ui.money :amount="$item->amount" /></td>
                                            <td class="whitespace-nowrap py-2 pl-4 text-right">
                                                <button type="button" wire:click="edit({{ $item->id }})" class="font-medium text-brand-700 hover:underline">Modifier</button>
                                                <button type="button" wire:click="delete({{ $item->id }})" wire:confirm="Supprimer « {{ $item->label }} » du barème {{ $level }} ? Les soldes des élèves de ce niveau seront recalculés." class="ml-3 font-medium text-red-600 hover:underline">Supprimer</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-zinc-200 font-semibold">
                                        <td class="pt-2" colspan="2">Total obligatoire</td>
                                        <td class="pt-2 text-right"><x-ui.money :amount="$this->items->where('is_optional', false)->sum('amount')" /></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif

                    <form wire:submit="save" class="mt-6 grid gap-4 border-t border-zinc-100 pt-5 sm:grid-cols-2 lg:grid-cols-4">
                        <x-ui.input label="Libellé" name="label" placeholder="ex. 1re tranche" class="lg:col-span-2" />
                        <x-ui.input label="Montant (F CFA)" name="amount" type="number" min="1" inputmode="numeric" />
                        <x-ui.input label="Date limite" name="due_on" type="date" hint="Vide : dû à l'inscription." />
                        <label class="flex items-center gap-2 text-sm text-zinc-700 sm:col-span-2">
                            <input type="checkbox" wire:model="is_optional" class="size-4 rounded border-zinc-300 accent-brand-600">
                            Frais optionnel (tenue, cantine, transport…) : payé seulement par les élèves qui le prennent
                        </label>
                        <div class="flex justify-end gap-2 sm:col-span-2">
                            @if ($editingId)
                                <x-ui.button type="button" variant="secondary" wire:click="cancel">Annuler</x-ui.button>
                            @endif
                            <x-ui.button>{{ $editingId ? 'Enregistrer' : 'Ajouter au barème' }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>

                @php($emptyLevels = array_filter($this->levels, fn ($option) => $option->value !== $level && ! isset($this->summary[$option->value])))
                @if ($this->items->isNotEmpty() && $emptyLevels !== [])
                    <x-ui.card title="Copier ce barème vers d'autres niveaux">
                        <form wire:submit="copy">
                            <div class="flex flex-wrap gap-2">
                                @foreach ($emptyLevels as $option)
                                    <label wire:key="copy-{{ $option->value }}" class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm ring-1 ring-zinc-300 ring-inset has-checked:bg-brand-50 has-checked:ring-brand-400">
                                        <input type="checkbox" wire:model="copyTo" value="{{ $option->value }}" class="size-4 rounded border-zinc-300 accent-brand-600">
                                        {{ $option->value }}
                                    </label>
                                @endforeach
                            </div>
                            @error('copyTo')
                                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            <x-ui.button class="mt-4" variant="secondary">Copier</x-ui.button>
                            <p class="mt-2 text-xs text-zinc-500">Seuls les niveaux sans barème sont proposés. Vous pourrez ajuster les montants ensuite.</p>
                        </form>
                    </x-ui.card>
                @endif
            </div>
        </div>
    @endif
</div>
