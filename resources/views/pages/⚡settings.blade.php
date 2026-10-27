<?php

use App\Models\ActivityLog;
use App\Enums\PeriodType;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\GradingPeriod;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Paramètres')] class extends Component
{
    public string $name = '';

    public string $city = '';

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public string $motto = '';

    public string $period_type = '';

    public string $primary_scale = '';

    public string $year_name = '';

    public string $year_starts_on = '';

    public string $year_ends_on = '';

    public function mount(): void
    {
        $school = $this->school();

        $this->name = $school->name;
        $this->city = $school->city ?? '';
        $this->address = $school->address ?? '';
        $this->phone = $school->phone ?? '';
        $this->email = $school->email ?? '';
        $this->motto = $school->motto ?? '';

        $this->period_type = $school->period_type->value;
        $this->primary_scale = (string) $school->primary_scale;

        $startYear = now()->month >= 8 ? now()->year : now()->year - 1;
        $this->year_name = $startYear.'-'.($startYear + 1);
        $this->year_starts_on = $startYear.'-09-15';
        $this->year_ends_on = ($startYear + 1).'-07-15';
    }

    protected function school(): School
    {
        return auth()->user()->school;
    }

    /**
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::withCount('enrollments')->orderByDesc('starts_on')->get();
    }

    public function saveSchool(): void
    {
        Gate::authorize('manage-whole-school');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'motto' => ['nullable', 'string', 'max:255'],
        ], attributes: ['name' => 'nom de l\'école', 'city' => 'ville', 'address' => 'adresse', 'phone' => 'téléphone', 'motto' => 'devise']);

        $this->school()->update(array_map(fn (string $value) => $value === '' ? null : $value, $validated));

        ActivityLog::record('settings.school', 'Informations de l\'école modifiées', $this->school());

        session()->flash('status', 'Informations de l\'école enregistrées.');
        $this->redirectRoute('settings', navigate: true);
    }

    public function saveGrading(): void
    {
        Gate::authorize('manage-whole-school');

        $validated = $this->validate([
            'period_type' => ['required', Rule::enum(PeriodType::class)],
            'primary_scale' => ['required', Rule::in(['10', '20'])],
        ], attributes: ['period_type' => 'découpage de l\'année', 'primary_scale' => 'notation du primaire']);

        $school = $this->school();
        $year = $school->currentAcademicYear;

        if ($validated['period_type'] !== $school->period_type->value && $year !== null) {
            $periods = GradingPeriod::whereBelongsTo($year);

            // Periods can only be redrawn while no mark has been given in them.
            if (Assessment::whereIn('grading_period_id', $periods->clone()->select('id'))->exists()) {
                $this->addError('period_type', 'Des notes ont déjà été saisies cette année : le découpage ne peut plus changer avant l\'année prochaine.');

                return;
            }

            $periods->delete();
        }

        $school->update(['period_type' => $validated['period_type'], 'primary_scale' => (int) $validated['primary_scale']]);

        ActivityLog::record('settings.grading', 'Bulletins : '.$school->period_type->label().', primaire noté sur '.$school->primary_scale, $school);

        session()->flash('status', 'Réglages des bulletins enregistrés.');
        $this->redirectRoute('settings', navigate: true);
    }

    public function createYear(): void
    {
        Gate::authorize('manage-whole-school');

        $validated = $this->validate([
            'year_name' => ['required', 'string', 'max:20', Rule::unique('academic_years', 'name')->where('school_id', $this->school()->id)],
            'year_starts_on' => ['required', 'date'],
            'year_ends_on' => ['required', 'date', 'after:year_starts_on'],
        ], attributes: ['year_name' => 'nom de l\'année', 'year_starts_on' => 'date de rentrée', 'year_ends_on' => 'date de fin']);

        $year = AcademicYear::create([
            'name' => $validated['year_name'],
            'starts_on' => $validated['year_starts_on'],
            'ends_on' => $validated['year_ends_on'],
        ]);

        if (! AcademicYear::where('is_current', true)->exists()) {
            $this->makeCurrent($year->id);
        }

        unset($this->years);
    }

    public function makeCurrent(int $yearId): void
    {
        Gate::authorize('manage-whole-school');

        $year = AcademicYear::findOrFail($yearId);

        DB::transaction(function () use ($year): void {
            AcademicYear::where('is_current', true)->update(['is_current' => false]);
            $year->update(['is_current' => true]);

            ActivityLog::record('settings.year', 'Année '.$year->name.' devenue l\'année en cours', $year);
        });

        $this->school()->unsetRelation('currentAcademicYear');
        unset($this->years);
    }
};
?>

<div>
    <x-ui.page-header title="Paramètres" />

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Informations de l'école">
            <form wire:submit="saveSchool" class="space-y-4">
                <x-ui.input label="Nom de l'école" name="name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Ville" name="city" />
                    <x-ui.input label="Téléphone" name="phone" type="tel" />
                </div>
                <x-ui.input label="Adresse" name="address" />
                <x-ui.input label="E-mail" name="email" type="email" />
                <x-ui.input label="Devise" name="motto" hint="Affichée sur les bulletins, ex. « Travail – Discipline – Succès »." />
                <x-ui.button>Enregistrer</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Notes et bulletins">
            <form wire:submit="saveGrading" class="space-y-4">
                <x-ui.select label="Découpage de l'année" name="period_type" hint="Ne peut plus changer une fois des notes saisies dans l'année.">
                    @foreach (\App\Enums\PeriodType::cases() as $option)
                        <option value="{{ $option->value }}" wire:key="period-type-{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Notation à la maternelle et au primaire" name="primary_scale" hint="Le secondaire est toujours noté sur 20.">
                    <option value="10">Sur 10</option>
                    <option value="20">Sur 20</option>
                </x-ui.select>
                <x-ui.button>Enregistrer</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Années scolaires">
            <ul class="divide-y divide-zinc-100 text-sm">
                @forelse ($this->years as $year)
                    <li wire:key="year-{{ $year->id }}" class="flex items-center justify-between gap-2 py-2">
                        <div>
                            <p class="font-medium">
                                {{ $year->name }}
                                @if ($year->is_current)
                                    <span class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-700">En cours</span>
                                @endif
                            </p>
                            <p class="text-xs text-zinc-500">{{ $year->starts_on->format('d/m/Y') }} → {{ $year->ends_on->format('d/m/Y') }} · {{ $year->enrollments_count }} inscrit(s)</p>
                        </div>
                        @unless ($year->is_current)
                            <button
                                type="button"
                                wire:click="makeCurrent({{ $year->id }})"
                                wire:confirm="Faire de {{ $year->name }} l'année en cours ? Toute l'application basculera sur cette année."
                                data-confirm-button="Activer"
                                class="text-sm font-medium text-brand-700 hover:underline"
                            >Activer</button>
                        @endunless
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">Aucune année scolaire.</li>
                @endforelse
            </ul>

            <form wire:submit="createYear" class="mt-4 grid gap-3 border-t border-zinc-200 pt-4 sm:grid-cols-3">
                <x-ui.input label="Année" name="year_name" placeholder="2026-2027" />
                <x-ui.input label="Rentrée" name="year_starts_on" type="date" />
                <x-ui.input label="Fin" name="year_ends_on" type="date" />
                <x-ui.button class="sm:col-span-3">Ajouter l'année</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
