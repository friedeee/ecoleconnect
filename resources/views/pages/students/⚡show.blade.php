<?php

use App\Enums\GuardianRelationship;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public Student $student;

    /**
     * Login details of a parent account just opened, shown once.
     *
     * @var array{name: string, phone: string, password: string}|null
     */
    #[Locked]
    public ?array $credentials = null;

    public function mount(Student $student): void
    {
        $this->student = $student->load([
            'guardians.user',
            'enrollments' => fn ($query) => $query->with(['academicYear', 'schoolClass'])->latest('enrolled_on'),
            'currentEnrollment',
        ]);
    }

    /**
     * Opens the guardian's parent portal account, or gives it a new temporary password.
     */
    public function openParentAccess(int $guardianId): void
    {
        Gate::authorize('manage-students');

        $guardian = $this->guardian($guardianId);
        $password = $guardian->openPortalAccount();

        $this->credentials = ['name' => $guardian->full_name, 'phone' => $guardian->phone, 'password' => $password];
        $this->student->load('guardians.user');
    }

    public function toggleParentAccess(int $guardianId): void
    {
        Gate::authorize('manage-students');

        $user = $this->guardian($guardianId)->user ?? abort(404);
        $user->forceFill(['is_active' => ! $user->is_active])->save();

        $this->student->load('guardians.user');
    }

    protected function guardian(int $guardianId): Guardian
    {
        return $this->student->guardians->firstWhere('id', $guardianId) ?? abort(404);
    }

    public function render()
    {
        return $this->view()->title($this->student->full_name);
    }
};
?>

<div>
    <x-ui.page-header :title="$student->full_name" :description="'Matricule '.$student->registration_number">
        @can('manage-students')
            <x-ui.button variant="secondary" :href="route('students.edit', $student)">Modifier</x-ui.button>
        @endcan
    </x-ui.page-header>

    @if ($credentials)
        <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50 px-5 py-4 text-sm text-brand-900" role="status">
            <p class="font-semibold">Accès parent prêt pour {{ $credentials['name'] }}. Transmettez-lui ces identifiants :</p>
            <dl class="mt-2 grid gap-1 font-mono sm:grid-cols-[auto_1fr] sm:gap-x-4">
                <dt class="text-brand-700">Adresse</dt><dd>{{ route('login') }}</dd>
                <dt class="text-brand-700">Téléphone</dt><dd>{{ $credentials['phone'] }}</dd>
                <dt class="text-brand-700">Mot de passe</dt><dd>{{ $credentials['password'] }}</dd>
            </dl>
            <p class="mt-2 text-xs">Mot de passe provisoire, affiché une seule fois : le parent en choisira un nouveau à sa première connexion.</p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Identité" class="lg:col-span-2">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                @foreach ([
                    'Sexe' => $student->gender->label(),
                    'Date de naissance' => $student->birth_date?->translatedFormat('d F Y'),
                    'Lieu de naissance' => $student->birth_place,
                    'Adresse' => $student->address,
                    'Statut' => $student->status->label(),
                ] as $label => $value)
                    <div wire:key="identity-{{ $loop->index }}">
                        <dt class="text-zinc-500">{{ $label }}</dt>
                        <dd class="mt-0.5 font-medium">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card title="Parents et tuteurs">
            <ul class="space-y-4 text-sm">
                @forelse ($student->guardians as $guardian)
                    <li wire:key="guardian-{{ $guardian->id }}">
                        <p class="font-medium">
                            {{ $guardian->full_name }}
                            @if ($guardian->pivot->is_primary_contact)
                                <span class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-700">Principal</span>
                            @endif
                        </p>
                        <p class="text-zinc-500">{{ GuardianRelationship::from($guardian->pivot->relationship)->label() }}{{ $guardian->profession ? ' · '.$guardian->profession : '' }}</p>
                        <p class="mt-1"><a href="tel:{{ $guardian->phone }}" class="text-brand-700 hover:underline">{{ $guardian->phone }}</a></p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            @if ($guardian->user)
                                <x-ui.badge :tone="$guardian->user->is_active ? 'brand' : 'neutral'">{{ $guardian->user->is_active ? 'Accès parent actif' : 'Accès parent désactivé' }}</x-ui.badge>
                                @can('manage-students')
                                    <button type="button" wire:click="openParentAccess({{ $guardian->id }})" wire:confirm="Donner un nouveau mot de passe provisoire à {{ $guardian->full_name }} ? L'ancien ne fonctionnera plus." data-confirm-button="Réinitialiser" class="font-medium text-brand-700 hover:underline">Nouveau mot de passe</button>
                                    <button type="button" wire:click="toggleParentAccess({{ $guardian->id }})" class="font-medium text-zinc-500 hover:underline">{{ $guardian->user->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                                @endcan
                            @else
                                @can('manage-students')
                                    <button type="button" wire:click="openParentAccess({{ $guardian->id }})" class="font-semibold text-brand-700 hover:underline">Créer l'accès parent</button>
                                @endcan
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="text-zinc-500">Aucun parent enregistré.</li>
                @endforelse
            </ul>
        </x-ui.card>

        @if ($student->currentEnrollment)
            @can('view-fees')
                <livewire:student-fees :enrollment="$student->currentEnrollment" />
            @endcan
            @can('view-attendance')
                <livewire:student-attendance :enrollment="$student->currentEnrollment" />
            @endcan
        @endif

        <x-ui.card title="Scolarité" class="lg:col-span-3">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="pb-2">Année</th>
                        <th class="pb-2">Classe</th>
                        <th class="pb-2">Inscrit le</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($student->enrollments as $enrollment)
                        <tr wire:key="enrollment-{{ $enrollment->id }}">
                            <td class="py-2">{{ $enrollment->academicYear->name }}</td>
                            <td class="py-2">
                                <a href="{{ route('classes.show', $enrollment->schoolClass) }}" wire:navigate class="text-brand-700 hover:underline">{{ $enrollment->schoolClass->name }}</a>
                            </td>
                            <td class="py-2">{{ $enrollment->enrolled_on->format('d/m/Y') }}</td>
                            <td class="py-2 text-zinc-500">{{ $enrollment->is_repeating ? 'Redoublant' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
