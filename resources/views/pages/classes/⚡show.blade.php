<?php

use App\Models\ActivityLog;
use App\Enums\Role;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public SchoolClass $schoolClass;

    /**
     * Parent accounts opened in bulk, to print and hand out: shown once.
     *
     * @var list<array{student: string, guardian: string, phone: string, password: string}>
     */
    #[Locked]
    public array $parentAccesses = [];

    public string $subject_id = '';

    public string $coefficient = '1';

    public string $teacher_id = '';

    public function mount(SchoolClass $schoolClass): void
    {
        $this->schoolClass = $schoolClass->load(['academicYear', 'headTeacher']);
    }

    /**
     * @return Collection<int, \App\Models\Student>
     */
    #[Computed]
    public function students(): Collection
    {
        return $this->schoolClass->students()->orderBy('last_name')->orderBy('first_name')->get();
    }

    /**
     * @return Collection<int, Subject>
     */
    #[Computed]
    public function assignedSubjects(): Collection
    {
        return $this->schoolClass->subjects()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Subject>
     */
    #[Computed]
    public function availableSubjects(): Collection
    {
        return Subject::forCycle($this->schoolClass->cycle)
            ->whereNotIn('id', $this->assignedSubjects->modelKeys())
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function teachers(): Collection
    {
        return User::where('school_id', auth()->user()->school_id)
            ->availableIn($this->schoolClass->cycle->section())
            ->where('role', Role::Teacher)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function addSubject(): void
    {
        Gate::authorize('manage-school');

        $validated = $this->validate([
            'subject_id' => ['required', Rule::in($this->availableSubjects->modelKeys())],
            'coefficient' => ['required', 'integer', 'min:1', 'max:10'],
            'teacher_id' => ['nullable', Rule::in($this->teachers->modelKeys())],
        ], attributes: ['subject_id' => 'matière', 'teacher_id' => 'enseignant']);

        $this->schoolClass->subjects()->syncWithoutDetaching([
            (int) $validated['subject_id'] => [
                'coefficient' => (int) $validated['coefficient'],
                'teacher_id' => $validated['teacher_id'] ?: null,
            ],
        ]);

        $this->reset(['subject_id', 'teacher_id']);
        $this->coefficient = '1';
        unset($this->assignedSubjects, $this->availableSubjects);
    }

    public function removeSubject(int $subjectId): void
    {
        Gate::authorize('manage-school');

        $this->schoolClass->subjects()->detach($subjectId);
        unset($this->assignedSubjects, $this->availableSubjects);
    }

    /**
     * Opens a portal account for the main contact of every student who has none yet.
     */
    public function openParentAccesses(): void
    {
        Gate::authorize('manage-students');

        $this->parentAccesses = [];

        foreach ($this->schoolClass->students()->with('guardians.user')->orderBy('last_name')->orderBy('first_name')->get() as $student) {
            $guardian = $student->guardians->firstWhere('pivot.is_primary_contact', true) ?? $student->guardians->first();

            if ($guardian === null || $guardian->user !== null) {
                continue;
            }

            $this->parentAccesses[] = [
                'student' => $student->full_name,
                'guardian' => $guardian->full_name,
                'phone' => $guardian->phone,
                'password' => $guardian->openPortalAccount(),
            ];
        }

        if ($this->parentAccesses !== []) {
            ActivityLog::record('parent-access.opened', count($this->parentAccesses).' accès parents ouverts pour la classe '.$this->schoolClass->name, $this->schoolClass);
        }

        if ($this->parentAccesses === []) {
            session()->flash('parent-accesses', 'Tous les parents de cette classe ont déjà un accès.');
        }
    }

    public function render()
    {
        return $this->view()->title('Classe '.$this->schoolClass->name);
    }
};
?>

<div>
    <x-ui.page-header
        :title="'Classe '.$schoolClass->name"
        :description="$schoolClass->academicYear->name.' · '.$this->students->count().' élève(s)'.($schoolClass->headTeacher ? ' · '.$schoolClass->cycle->headTeacherTitle().' : '.$schoolClass->headTeacher->name : '')"
    >
        @can('manage-students')
            <x-ui.button
                type="button"
                variant="secondary"
                class="print:hidden"
                wire:click="openParentAccesses"
                wire:confirm="Créer les accès parents de la classe {{ $schoolClass->name }} ? Un compte est ouvert pour le contact principal de chaque élève qui n'en a pas encore."
            >Accès parents</x-ui.button>
        @endcan
    </x-ui.page-header>

    @if (session('parent-accesses'))
        <p class="mb-6 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-600">{{ session('parent-accesses') }}</p>
    @endif

    @if ($parentAccesses !== [])
        <x-ui.card title="Identifiants des parents · {{ $schoolClass->name }}" class="mb-6">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" onclick="window.print()" class="print:hidden">Imprimer</x-ui.button>
            </x-slot:actions>
            <p class="mb-3 text-sm text-zinc-600">À découper et remettre à chaque parent. Connexion sur <strong>{{ route('login') }}</strong> avec le numéro de téléphone. Mots de passe provisoires, affichés une seule fois.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($parentAccesses as $access)
                    <div wire:key="access-{{ $loop->index }}" class="break-inside-avoid rounded-lg border border-dashed border-zinc-300 p-3 text-sm">
                        <p class="font-semibold">{{ $access['guardian'] }}</p>
                        <p class="text-xs text-zinc-500">Parent de {{ $access['student'] }}</p>
                        <p class="mt-2 font-mono">Téléphone : {{ $access['phone'] }}</p>
                        <p class="font-mono">Mot de passe : {{ $access['password'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-6 lg:grid-cols-5">
        <x-ui.card title="Élèves" class="lg:col-span-3">
            @if ($this->students->isEmpty())
                <p class="text-sm text-zinc-500">Aucun élève inscrit dans cette classe.</p>
            @else
                <ol class="divide-y divide-zinc-100 text-sm">
                    @foreach ($this->students as $student)
                        <li wire:key="student-{{ $student->id }}" class="flex items-center justify-between gap-2 py-2">
                            <a href="{{ route('students.show', $student) }}" wire:navigate class="hover:text-brand-700">
                                <span class="mr-2 inline-block w-6 text-right tabular-nums text-zinc-400">{{ $loop->iteration }}.</span>{{ $student->full_name }}
                            </a>
                            <span class="text-xs text-zinc-500">{{ $student->gender->value }}{{ $student->pivot->is_repeating ? ' · Red.' : '' }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.card>

        <x-ui.card title="Matières et coefficients" class="lg:col-span-2">
            @if ($this->assignedSubjects->isEmpty())
                <p class="text-sm text-zinc-500">Aucune matière affectée.</p>
            @else
                <ul class="divide-y divide-zinc-100 text-sm">
                    @foreach ($this->assignedSubjects as $subject)
                        <li wire:key="subject-{{ $subject->id }}" class="flex items-center justify-between gap-2 py-2">
                            <div>
                                <p class="font-medium">{{ $subject->name }} <span class="text-zinc-500">· coef. {{ $subject->pivot->coefficient }}</span></p>
                                <p class="text-xs text-zinc-500">{{ $this->teachers->firstWhere('id', $subject->pivot->teacher_id)?->name ?? 'Enseignant non affecté' }}</p>
                            </div>
                            @can('manage-school')
                                <button
                                    type="button"
                                    wire:click="removeSubject({{ $subject->id }})"
                                    wire:confirm="Retirer {{ $subject->name }} de cette classe ?"
                                    class="text-xs font-medium text-red-600 hover:underline"
                                >Retirer</button>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif

            @can('manage-school')
                @if ($this->availableSubjects->isNotEmpty())
                    <form wire:submit="addSubject" class="mt-4 grid gap-3 border-t border-zinc-200 pt-4">
                        <x-ui.select label="Matière" name="subject_id" placeholder="Choisir…">
                            @foreach ($this->availableSubjects as $subject)
                                <option value="{{ $subject->id }}" wire:key="available-{{ $subject->id }}">{{ $subject->name }}</option>
                            @endforeach
                        </x-ui.select>
                        <div class="grid grid-cols-3 gap-3">
                            <x-ui.input label="Coef." name="coefficient" type="number" min="1" max="10" />
                            <x-ui.select label="Enseignant" name="teacher_id" placeholder="Aucun" class="col-span-2">
                                @foreach ($this->teachers as $teacher)
                                    <option value="{{ $teacher->id }}" wire:key="teacher-{{ $teacher->id }}">{{ $teacher->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                        <x-ui.button>Ajouter la matière</x-ui.button>
                    </form>
                @elseif ($this->assignedSubjects->isEmpty())
                    <p class="mt-4 text-sm text-zinc-500">Créez d'abord des matières dans <a href="{{ route('subjects.index') }}" wire:navigate class="text-brand-700 underline">Matières</a>.</p>
                @endif
            @endcan
        </x-ui.card>
    </div>
</div>
