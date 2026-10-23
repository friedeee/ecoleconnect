<?php

use App\Enums\Cycle;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Matières')] class extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $code = '';

    public string $cycle = '';

    /**
     * @return Collection<int, Subject>
     */
    #[Computed]
    public function subjects(): Collection
    {
        return Subject::withCount('classes')->orderBy('cycle')->orderBy('name')->get();
    }

    /**
     * Cycles the school teaches, within the section the user works in.
     *
     * @return list<Cycle>
     */
    #[Computed]
    public function cycles(): array
    {
        $user = auth()->user();
        $section = $user->activeSection();

        return array_values(array_filter(
            Cycle::cases(),
            fn (Cycle $cycle) => $user->school->teaches($cycle) && ($section === null || $cycle->section() === $section),
        ));
    }

    /**
     * Section admins only change the subjects of their own cycles; shared subjects belong to the whole school.
     */
    public function canChange(Subject $subject): bool
    {
        return auth()->user()->worksForWholeSchool()
            || ($subject->cycle !== null && $subject->cycle->section() === auth()->user()->section);
    }

    public function edit(int $subjectId): void
    {
        $subject = Subject::findOrFail($subjectId);

        abort_unless($this->canChange($subject), 403);

        $this->editingId = $subject->id;
        $this->name = $subject->name;
        $this->code = $subject->code;
        $this->cycle = $subject->cycle->value ?? '';
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'code', 'cycle']);
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-school');

        $this->code = Str::upper(trim($this->code));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => [
                'required', 'string', 'max:10', 'alpha_num:ascii',
                Rule::unique('subjects')->where('school_id', auth()->user()->school_id)->ignore($this->editingId),
            ],
            'cycle' => [
                Rule::requiredIf(! auth()->user()->worksForWholeSchool()),
                'nullable',
                Rule::enum(Cycle::class)->only($this->cycles),
            ],
        ], attributes: ['name' => 'nom', 'code' => 'code', 'cycle' => 'cycle']);

        $subject = $this->editingId ? Subject::findOrFail($this->editingId) : new Subject;

        abort_if($subject->exists && ! $this->canChange($subject), 403);

        $subject->fill(['name' => trim($validated['name']), 'code' => $validated['code'], 'cycle' => $validated['cycle'] ?: null])->save();

        $this->cancel();
        unset($this->subjects);
    }

    public function delete(int $subjectId): void
    {
        Gate::authorize('manage-school');

        $subject = Subject::withCount('classes')->findOrFail($subjectId);

        abort_unless($this->canChange($subject), 403);

        if ($subject->classes_count > 0) {
            $this->addError('delete', "« {$subject->name} » est utilisée par {$subject->classes_count} classe(s). Retirez-la d'abord de ces classes.");

            return;
        }

        $subject->delete();
        unset($this->subjects);
    }
};
?>

<div>
    <x-ui.page-header title="Matières" description="Les matières enseignées dans votre école. Les coefficients se règlent ensuite classe par classe." />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="$editingId ? 'Modifier la matière' : 'Nouvelle matière'">
            <form wire:submit="save" class="space-y-4">
                <x-ui.input label="Nom" name="name" placeholder="ex. Mathématiques" />
                <x-ui.input label="Code" name="code" placeholder="ex. MATH" hint="Court, utilisé sur les bulletins." />
                <x-ui.select label="Cycle" name="cycle" :placeholder="auth()->user()->worksForWholeSchool() ? 'Tous les cycles' : 'Choisir…'">
                    @foreach ($this->cycles as $option)
                        <option value="{{ $option->value }}" wire:key="cycle-{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <div class="flex gap-2">
                    <x-ui.button>{{ $editingId ? 'Enregistrer' : 'Ajouter' }}</x-ui.button>
                    @if ($editingId)
                        <x-ui.button type="button" variant="secondary" wire:click="cancel">Annuler</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <div class="lg:col-span-2">
            @error('delete')
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
            @enderror

            @if ($this->subjects->isEmpty())
                <x-ui.empty>Aucune matière pour l'instant.</x-ui.empty>
            @else
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Matière</th>
                                <th class="px-4 py-3">Cycle</th>
                                <th class="px-4 py-3">Classes</th>
                                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($this->subjects as $subject)
                                <tr wire:key="subject-{{ $subject->id }}">
                                    <td class="px-4 py-3 font-mono text-xs">{{ $subject->code }}</td>
                                    <td class="px-4 py-3 font-medium">{{ $subject->name }}</td>
                                    <td class="px-4 py-3">
                                        @if ($subject->cycle)
                                            <x-ui.badge :tone="$subject->cycle->tone()">{{ $subject->cycle->label() }}</x-ui.badge>
                                        @else
                                            <span class="text-xs text-zinc-500">Tous</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-zinc-600">{{ $subject->classes_count }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        @if ($this->canChange($subject))
                                            <button type="button" wire:click="edit({{ $subject->id }})" class="text-sm font-medium text-brand-700 hover:underline">Modifier</button>
                                            <button type="button" wire:click="delete({{ $subject->id }})" wire:confirm="Supprimer {{ $subject->name }} ?" class="ml-3 text-sm font-medium text-red-600 hover:underline">Supprimer</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
