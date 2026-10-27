<?php

use App\Models\ActivityLog;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?Student $student = null;

    public string $registration_number = '';

    public string $last_name = '';

    public string $first_name = '';

    public string $gender = '';

    public string $birth_date = '';

    public string $birth_place = '';

    public string $address = '';

    public string $class_id = '';

    public bool $is_repeating = false;

    public string $guardian_last_name = '';

    public string $guardian_first_name = '';

    public string $guardian_phone = '';

    public string $guardian_whatsapp = '';

    public string $guardian_relationship = 'father';

    public string $guardian_profession = '';

    public function mount(?Student $student = null): void
    {
        if ($student === null || ! $student->exists) {
            return;
        }

        $this->student = $student->load(['currentEnrollment', 'guardians']);
        $this->fill(array_map(
            fn (?string $value): string => $value ?? '',
            $student->only(['registration_number', 'last_name', 'first_name', 'birth_place', 'address']),
        ));
        $this->gender = $student->gender->value;
        $this->birth_date = $student->birth_date?->format('Y-m-d') ?? '';
        $this->class_id = (string) ($student->currentEnrollment?->school_class_id ?? '');
        $this->is_repeating = $student->currentEnrollment?->is_repeating ?? false;

        $guardian = $this->primaryGuardian();

        if ($guardian !== null) {
            $this->guardian_last_name = $guardian->last_name;
            $this->guardian_first_name = $guardian->first_name;
            $this->guardian_phone = $guardian->phone;
            $this->guardian_whatsapp = $guardian->whatsapp ?? '';
            $this->guardian_relationship = $guardian->pivot->relationship;
            $this->guardian_profession = $guardian->profession ?? '';
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
        return SchoolClass::whereBelongsTo($this->currentYear)->orderBy('level')->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $schoolId = auth()->user()->school_id;

        return [
            'registration_number' => [
                'nullable', 'string', 'max:30',
                Rule::unique('students')->where('school_id', $schoolId)->ignore($this->student?->id),
            ],
            'last_name' => ['required', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            // Only the current year's classes the user can see: their school, and their section.
            'class_id' => ['required', Rule::in($this->classes->modelKeys())],
            'is_repeating' => ['boolean'],
            'guardian_last_name' => ['required', 'string', 'max:80'],
            'guardian_first_name' => ['required', 'string', 'max:80'],
            'guardian_phone' => ['required', 'string', 'regex:/^\+?[0-9 .-]{8,20}$/'],
            'guardian_whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 .-]{8,20}$/'],
            'guardian_relationship' => ['required', Rule::enum(GuardianRelationship::class)],
            'guardian_profession' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'registration_number' => 'matricule',
            'last_name' => 'nom',
            'first_name' => 'prénoms',
            'gender' => 'sexe',
            'birth_date' => 'date de naissance',
            'class_id' => 'classe',
            'guardian_last_name' => 'nom du parent',
            'guardian_first_name' => 'prénoms du parent',
            'guardian_phone' => 'téléphone du parent',
            'guardian_whatsapp' => 'numéro WhatsApp',
            'guardian_relationship' => 'lien de parenté',
        ];
    }

    public function save(): void
    {
        Gate::authorize('manage-students');

        $this->validate();

        $student = DB::transaction(function (): Student {
            $student = $this->student ?? new Student;

            $student->fill([
                'registration_number' => $this->registration_number ?: $this->nextRegistrationNumber(),
                'last_name' => trim($this->last_name),
                'first_name' => trim($this->first_name),
                'gender' => $this->gender,
                'birth_date' => $this->birth_date ?: null,
                'birth_place' => $this->birth_place ?: null,
                'address' => $this->address ?: null,
            ])->save();

            $student->enrollments()->updateOrCreate(
                ['academic_year_id' => $this->currentYear->id],
                [
                    'school_class_id' => (int) $this->class_id,
                    'is_repeating' => $this->is_repeating,
                    'enrolled_on' => $student->currentEnrollment?->enrolled_on ?? today(),
                ],
            );

            $this->saveGuardian($student);

            return $student;
        });

        ActivityLog::record($this->student ? 'student.updated' : 'student.enrolled', ($this->student ? 'Dossier modifié : ' : 'Élève inscrit : ').$student->full_name.' ('.$student->registration_number.')', $student);

        session()->flash('status', $this->student ? 'Dossier de l\'élève mis à jour.' : 'Élève inscrit avec succès.');

        $this->redirectRoute('students.show', $student, navigate: true);
    }

    /**
     * Updates the primary guardian, or links an existing guardian with the same phone (siblings).
     */
    protected function saveGuardian(Student $student): void
    {
        $phone = $this->normalizePhone($this->guardian_phone);
        $attributes = [
            'last_name' => trim($this->guardian_last_name),
            'first_name' => trim($this->guardian_first_name),
            'phone' => $phone,
            'whatsapp' => $this->guardian_whatsapp ? $this->normalizePhone($this->guardian_whatsapp) : null,
            'profession' => $this->guardian_profession ?: null,
        ];

        $guardian = $this->primaryGuardian();

        if ($guardian !== null) {
            $guardian->update($attributes);
        } else {
            $guardian = Guardian::firstOrCreate(['phone' => $phone], $attributes);
        }

        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => $this->guardian_relationship, 'is_primary_contact' => true],
        ]);
    }

    protected function primaryGuardian(): ?Guardian
    {
        if ($this->student === null) {
            return null;
        }

        return $this->student->guardians->firstWhere('pivot.is_primary_contact', true)
            ?? $this->student->guardians->first();
    }

    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone);
    }

    /**
     * Builds the next matricule for the current year, e.g. "2026-0042".
     */
    protected function nextRegistrationNumber(): string
    {
        $prefix = $this->currentYear->starts_on->format('Y').'-';
        $last = Student::where('registration_number', 'like', $prefix.'%')
            ->orderByDesc('registration_number')
            ->value('registration_number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function render()
    {
        return $this->view()->title($this->student ? 'Modifier '.$this->student->full_name : 'Inscrire un élève');
    }
};
?>

<div>
    <x-ui.page-header
        :title="$student ? 'Modifier le dossier' : 'Inscrire un élève'"
        :description="$student?->full_name ?? ($this->currentYear ? 'Année scolaire '.$this->currentYear->name : null)"
    />

    @if (! $this->currentYear)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Créez d'abord une année scolaire active dans les <a href="{{ route('settings') }}" wire:navigate class="font-medium underline">paramètres</a>.
        </div>
    @elseif ($this->classes->isEmpty())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Aucune classe n'existe pour cette année. <a href="{{ route('classes.index') }}" wire:navigate class="font-medium underline">Créez une classe</a> avant d'inscrire des élèves.
        </div>
    @else
        <form wire:submit="save" class="space-y-6">
            <x-ui.card title="Élève">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Nom" name="last_name" />
                    <x-ui.input label="Prénoms" name="first_name" />
                    <x-ui.select label="Sexe" name="gender" placeholder="Choisir…">
                        @foreach (\App\Enums\Gender::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Matricule" name="registration_number" hint="Laissez vide pour le générer automatiquement." />
                    <x-ui.input label="Date de naissance" name="birth_date" type="date" />
                    <x-ui.input label="Lieu de naissance" name="birth_place" />
                    <x-ui.input label="Adresse" name="address" class="sm:col-span-2" />
                </div>
            </x-ui.card>

            <x-ui.card :title="'Inscription '.$this->currentYear->name">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select label="Classe" name="class_id" placeholder="Choisir une classe…">
                        @foreach ($this->classes as $class)
                            <option value="{{ $class->id }}" wire:key="class-{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm text-zinc-700">
                        <input type="checkbox" wire:model="is_repeating" class="rounded border-zinc-300 text-brand-600">
                        Redoublant
                    </label>
                </div>
            </x-ui.card>

            <x-ui.card title="Parent ou tuteur principal">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Nom" name="guardian_last_name" />
                    <x-ui.input label="Prénoms" name="guardian_first_name" />
                    <x-ui.input label="Téléphone" name="guardian_phone" type="tel" placeholder="+229 01 97 00 00 00" hint="Un parent déjà enregistré avec ce numéro sera réutilisé." />
                    <x-ui.input label="WhatsApp" name="guardian_whatsapp" type="tel" hint="Si différent du téléphone." />
                    <x-ui.select label="Lien de parenté" name="guardian_relationship">
                        @foreach (\App\Enums\GuardianRelationship::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Profession" name="guardian_profession" />
                </div>
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="$student ? route('students.show', $student) : route('students.index')">Annuler</x-ui.button>
                <x-ui.button>
                    <span wire:loading.remove wire:target="save">Enregistrer</span>
                    <span wire:loading wire:target="save">Enregistrement…</span>
                </x-ui.button>
            </div>
        </form>
    @endif
</div>
