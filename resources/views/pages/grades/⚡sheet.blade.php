<?php

use App\Enums\AssessmentKind;
use App\Models\Assessment;
use App\Models\GradingPeriod;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public SchoolClass $schoolClass;

    #[Locked]
    public Subject $subject;

    #[Url(as: 'periode', except: '')]
    public string $periodId = '';

    /**
     * Scores as typed: "" for none, "abs" for absent, or a number. Keyed by assessment id, then student id.
     *
     * @var array<int, array<int, string>>
     */
    public array $scores = [];

    public bool $showForm = false;

    public string $kind = '';

    public string $held_on = '';

    public string $out_of = '';

    public string $title = '';

    public function mount(SchoolClass $schoolClass, Subject $subject): void
    {
        abort_unless($schoolClass->subjects()->whereKey($subject->id)->exists(), 404);
        Gate::authorize('grade', [$schoolClass, $subject]);

        $this->schoolClass = $schoolClass->load('academicYear');
        $this->subject = $subject;
        $this->loadScores();
    }

    public function updatedPeriodId(): void
    {
        unset($this->period, $this->assessments, $this->headings, $this->availableKinds);
        $this->showForm = false;
        $this->loadScores();
    }

    /**
     * @return Collection<int, GradingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return GradingPeriod::ensureFor($this->schoolClass->academicYear);
    }

    #[Computed]
    public function period(): GradingPeriod
    {
        return $this->periods->firstWhere('id', (int) $this->periodId) ?? GradingPeriod::covering($this->periods);
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function students(): Collection
    {
        return $this->schoolClass->students()->orderBy('last_name')->orderBy('first_name')->get();
    }

    /**
     * @return Collection<int, Assessment>
     */
    #[Computed]
    public function assessments(): Collection
    {
        return Assessment::with('marks')
            ->whereBelongsTo($this->period)
            ->whereBelongsTo($this->schoolClass)
            ->whereBelongsTo($this->subject)
            ->orderBy('held_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Column headings numbered per kind: "Int. 1", "Int. 2", "Dev. 1"…
     *
     * @return array<int, string>
     */
    #[Computed]
    public function headings(): array
    {
        $counts = [];

        return $this->assessments->mapWithKeys(function (Assessment $assessment) use (&$counts) {
            $counts[$assessment->kind->value] = ($counts[$assessment->kind->value] ?? 0) + 1;

            return [$assessment->id => $assessment->kind->shortLabel().' '.$counts[$assessment->kind->value]];
        })->all();
    }

    /**
     * Scale averages are shown on: 20, or the school's primary scale.
     */
    #[Computed]
    public function scale(): int
    {
        return auth()->user()->school->scaleFor($this->schoolClass->cycle);
    }

    /**
     * @return list<AssessmentKind>
     */
    #[Computed]
    public function kinds(): array
    {
        return AssessmentKind::for($this->schoolClass->cycle);
    }

    /**
     * Kinds of the cycle that have not reached their limit in this period.
     *
     * @return list<AssessmentKind>
     */
    #[Computed]
    public function availableKinds(): array
    {
        return array_values(array_filter(
            $this->kinds,
            fn (AssessmentKind $kind) => $this->assessments->where('kind', $kind)->count() < $kind->maxPerPeriod(),
        ));
    }

    protected function loadScores(): void
    {
        $this->scores = [];

        foreach ($this->assessments as $assessment) {
            foreach ($this->students as $student) {
                $mark = $assessment->marks->firstWhere('student_id', $student->id);
                $this->scores[$assessment->id][$student->id] = match (true) {
                    $mark === null => '',
                    $mark->is_absent => 'abs',
                    default => rtrim(rtrim(number_format($mark->score, 2, ',', ''), '0'), ','),
                };
            }
        }
    }

    public function openForm(): void
    {
        abort_if($this->availableKinds === [], 403);

        $this->resetValidation();
        $this->kind = $this->availableKinds[0]->value;
        $this->held_on = today()->between($this->period->starts_on, $this->period->ends_on) ? today()->format('Y-m-d') : $this->period->starts_on->format('Y-m-d');
        $this->out_of = (string) $this->scale;
        $this->title = '';
        $this->showForm = true;
    }

    public function addAssessment(): void
    {
        Gate::authorize('grade', [$this->schoolClass, $this->subject]);
        abort_if($this->period->isLocked(), 403);

        $kind = AssessmentKind::tryFrom($this->kind);

        $validated = $this->validate([
            'kind' => ['required', Rule::enum(AssessmentKind::class)->only($this->kinds), Rule::in(array_column($this->availableKinds, 'value'))],
            'held_on' => ['required', 'date'],
            'out_of' => ['required', Rule::in(['10', '20'])],
            'title' => ['nullable', 'string', 'max:80'],
        ], [
            'kind.in' => $kind ? 'Maximum atteint : '.$kind->maxPerPeriod().' '.$kind->pluralLabel().' par période.' : 'Type invalide.',
        ], ['kind' => 'type', 'held_on' => 'date', 'out_of' => 'barème', 'title' => 'intitulé']);

        $assessment = new Assessment([
            'grading_period_id' => $this->period->id,
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'kind' => $validated['kind'],
            'title' => trim($validated['title']) ?: null,
            'held_on' => $validated['held_on'],
            'out_of' => (int) $validated['out_of'],
        ]);
        $assessment->forceFill(['created_by' => auth()->id()])->save();

        $this->showForm = false;
        unset($this->assessments, $this->headings, $this->availableKinds);
        $this->loadScores();
    }

    public function deleteAssessment(int $assessmentId): void
    {
        Gate::authorize('grade', [$this->schoolClass, $this->subject]);
        abort_if($this->period->isLocked(), 403);

        ($this->assessments->firstWhere('id', $assessmentId) ?? abort(404))->delete();

        unset($this->assessments, $this->headings, $this->availableKinds);
        $this->loadScores();
    }

    public function save(): void
    {
        Gate::authorize('grade', [$this->schoolClass, $this->subject]);
        abort_if($this->period->isLocked(), 403);

        $this->resetValidation();
        $rows = [];

        foreach ($this->assessments as $assessment) {
            foreach ($this->students as $student) {
                $raw = mb_strtolower(trim($this->scores[$assessment->id][$student->id] ?? ''));
                $key = "scores.{$assessment->id}.{$student->id}";

                if ($raw === '') {
                    $rows[] = [$assessment, $student, null];

                    continue;
                }

                if (in_array($raw, ['abs', 'a'], true)) {
                    $rows[] = [$assessment, $student, 'absent'];

                    continue;
                }

                $score = str_replace(',', '.', $raw);

                if (! is_numeric($score) || (float) $score < 0 || (float) $score > $assessment->out_of) {
                    $this->addError($key, "Note entre 0 et {$assessment->out_of}, ou « abs ».");

                    continue;
                }

                $rows[] = [$assessment, $student, round((float) $score, 2)];
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as [$assessment, $student, $value]) {
                if ($value === null) {
                    Mark::where('assessment_id', $assessment->id)->where('student_id', $student->id)->delete();

                    continue;
                }

                $mark = Mark::firstOrNew(['assessment_id' => $assessment->id, 'student_id' => $student->id]);
                $mark->fill(['score' => $value === 'absent' ? null : $value, 'is_absent' => $value === 'absent'])->save();
            }
        });

        unset($this->assessments, $this->headings, $this->availableKinds);
        $this->loadScores();
        session()->flash('saved', 'Notes enregistrées.');
    }

    public function render()
    {
        return $this->view()->title($this->subject->name.' · '.$this->schoolClass->name);
    }
};
?>

<div>
    <x-ui.page-header eyebrow="Notes · {{ $this->period->name }}" :title="$subject->name.' — '.$schoolClass->name" :description="'Moyennes sur '.$this->scale.($schoolClass->cycle === \App\Enums\Cycle::Secondary ? ' · (moyenne des interrogations + devoirs) ÷ (1 + nombre de devoirs)' : ' · moyenne des évaluations')">
        <x-ui.button variant="secondary" :href="route('grades.index', ['periode' => $this->period->id])">Toutes mes classes</x-ui.button>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
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
        @if ($this->period->isLocked())
            <x-ui.badge tone="amber">Période verrouillée : notes en lecture seule</x-ui.badge>
        @endif
        <p class="text-xs text-zinc-500 sm:ml-auto">
            @foreach ($this->kinds as $option)
                <span wire:key="count-{{ $option->value }}" @class(['font-semibold text-zinc-700' => $this->assessments->where('kind', $option)->count() >= $option->maxPerPeriod()])>
                    {{ \Illuminate\Support\Str::ucfirst($option->pluralLabel()) }} : {{ $this->assessments->where('kind', $option)->count() }}/{{ $option->maxPerPeriod() }}
                </span>{{ $loop->last ? '' : ' · ' }}
            @endforeach
        </p>
        @if (! $this->period->isLocked() && ! $showForm && $this->availableKinds !== [])
            <x-ui.button type="button" wire:click="openForm" icon="plus">Nouvelle évaluation</x-ui.button>
        @endif
    </div>

    @if ($showForm)
        <x-ui.card title="Nouvelle évaluation" class="mb-4">
            <form wire:submit="addAssessment" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <x-ui.select label="Type" name="kind">
                    @foreach ($this->availableKinds as $option)
                        <option value="{{ $option->value }}" wire:key="kind-{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Date" name="held_on" type="date" />
                <x-ui.select label="Noté sur" name="out_of">
                    <option value="10">10</option>
                    <option value="20">20</option>
                </x-ui.select>
                <x-ui.input label="Intitulé (facultatif)" name="title" placeholder="ex. Les fractions" />
                <div class="flex items-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)">Annuler</x-ui.button>
                    <x-ui.button class="flex-1">Ajouter</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if (session('saved'))
        <div class="mb-4 flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm text-brand-900" role="status">
            <x-ui.icon name="check" class="size-4.5 text-brand-600" /> {{ session('saved') }}
        </div>
    @endif

    @if ($this->students->isEmpty())
        <x-ui.empty>Aucun élève inscrit dans cette classe.</x-ui.empty>
    @elseif ($this->assessments->isEmpty())
        <x-ui.empty icon="grades" title="Aucune évaluation">Ajoutez une {{ mb_strtolower($this->kinds[0]->label()) }} pour commencer la saisie.</x-ui.empty>
    @else
        <form wire:submit="save">
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-xs text-zinc-500">
                        <tr>
                            <th class="sticky left-0 z-10 bg-zinc-50 px-4 py-3 text-left font-medium uppercase tracking-wide">Élève</th>
                            @foreach ($this->assessments as $assessment)
                                <th class="px-2 py-2 text-center font-medium" wire:key="head-{{ $assessment->id }}">
                                    <span class="block font-semibold text-zinc-700" title="{{ $assessment->title }}">{{ $this->headings[$assessment->id] }}</span>
                                    <span class="block">{{ $assessment->held_on->format('d/m') }} · /{{ $assessment->out_of }}</span>
                                    @unless ($this->period->isLocked())
                                        <button type="button" wire:click="deleteAssessment({{ $assessment->id }})" wire:confirm="Supprimer cette {{ mb_strtolower($assessment->kind->label()) }} du {{ $assessment->held_on->format('d/m') }} et toutes ses notes ?" class="mt-0.5 text-[11px] text-red-500 hover:underline">Supprimer</button>
                                    @endunless
                                </th>
                            @endforeach
                            <th class="px-4 py-3 text-right font-medium uppercase tracking-wide">Moy. /{{ $this->scale }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach ($this->students as $student)
                            @php($average = \App\Models\Assessment::averageFor($this->assessments, $student->id, $schoolClass->cycle))
                            <tr wire:key="student-{{ $student->id }}" class="hover:bg-zinc-50/60">
                                <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-4 py-2 font-medium">{{ $student->full_name }}</td>
                                @foreach ($this->assessments as $assessment)
                                    @php($key = 'scores.'.$assessment->id.'.'.$student->id)
                                    <td class="px-2 py-1.5 text-center" wire:key="cell-{{ $assessment->id }}-{{ $student->id }}">
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            wire:model="{{ $key }}"
                                            @disabled($this->period->isLocked())
                                            aria-label="Note de {{ $student->full_name }}, {{ $assessment->kind->label() }} du {{ $assessment->held_on->format('d/m') }}"
                                            title="{{ $errors->first($key) }}"
                                            @class([
                                                'w-16 rounded-lg border-0 px-2 py-1.5 text-center text-sm tabular-nums ring-1 ring-inset focus:ring-2 focus:ring-brand-500 focus:outline-none disabled:bg-zinc-50 disabled:text-zinc-500',
                                                'bg-red-50 ring-red-400' => $errors->has($key),
                                                'ring-zinc-200' => ! $errors->has($key),
                                            ])
                                        >
                                    </td>
                                @endforeach
                                <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">
                                    {{ $average === null ? '—' : number_format($average * $this->scale / 20, 2, ',', ' ') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($errors->any())
                <p class="mt-3 text-sm text-red-600">Certaines notes sont invalides (en rouge) : une note va de 0 au barème de l'évaluation, ou « abs » pour un absent.</p>
            @endif

            @unless ($this->period->isLocked())
                <div class="sticky bottom-0 mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-100/90 py-3 backdrop-blur">
                    <p class="text-xs text-zinc-500">Virgule ou point acceptés · « abs » = absent (ne compte pas dans la moyenne) · case vide = pas encore noté</p>
                    <x-ui.button icon="check">
                        <span wire:loading.remove wire:target="save">Enregistrer les notes</span>
                        <span wire:loading wire:target="save">Enregistrement…</span>
                    </x-ui.button>
                </div>
            @endunless
        </form>
    @endif
</div>
