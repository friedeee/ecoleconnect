<?php

use App\Models\ActivityLog;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Personnel')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $role = 'teacher';

    public string $section = '';

    /** Temporary password shown once after an account is created. */
    public ?string $temporaryPassword = null;

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function staff(): Collection
    {
        return User::where('school_id', auth()->user()->school_id)
            ->whereIn('role', array_map(fn (Role $role) => $role->value, Role::staffRoles()))
            ->when(auth()->user()->activeSection(), fn ($query, Section $section) => $query->where('section', $section))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function mount(): void
    {
        $this->section = auth()->user()->section->value ?? '';
    }

    /**
     * Sections the current admin may give to a new account.
     *
     * @return list<Section>
     */
    #[Computed]
    public function sections(): array
    {
        $user = auth()->user();

        return $user->worksForWholeSchool() ? $user->school->sections() : [$user->section];
    }

    public function create(): void
    {
        Gate::authorize('manage-school');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 .-]{8,20}$/'],
            'role' => ['required', Rule::in(array_map(fn (Role $role) => $role->value, Role::staffRoles()))],
            'section' => [
                // An admin of one section only creates accounts for that section.
                Rule::requiredIf(! auth()->user()->worksForWholeSchool() && $this->role !== Role::Accountant->value),
                'nullable',
                Rule::enum(Section::class)->only($this->sections),
            ],
        ], attributes: ['name' => 'nom', 'email' => 'adresse e-mail', 'phone' => 'téléphone', 'role' => 'rôle', 'section' => 'section']);

        $password = Str::password(10, symbols: false);

        $user = new User([
            'name' => trim($validated['name']),
            'email' => Str::lower($validated['email']),
            'phone' => $validated['phone'] ?: null,
            'password' => $password,
        ]);
        $user->forceFill([
            'school_id' => auth()->user()->school_id,
            'role' => $validated['role'],
            'must_change_password' => true,
            // The accountant always handles the whole school's money.
            'section' => $validated['role'] === Role::Accountant->value ? null : ($validated['section'] ?: null),
        ])->save();

        $this->reset(['name', 'email', 'phone', 'role']);
        $this->mount();
        $this->temporaryPassword = $user->email.' — mot de passe : '.$password;
        ActivityLog::record('staff.created', 'Compte créé : '.$user->name.' ('.$user->role->label().', '.$user->email.')', $user);
        unset($this->staff);
    }

    public function toggleActive(int $userId): void
    {
        Gate::authorize('manage-school');

        $user = $this->staff->firstWhere('id', $userId);

        abort_if($user === null || $user->is(auth()->user()), 403);

        $user->update(['is_active' => ! $user->is_active]);
        ActivityLog::record('staff.'.($user->is_active ? 'reactivated' : 'deactivated'), 'Compte de '.$user->name.' '.($user->is_active ? 'réactivé' : 'désactivé'), $user);
        unset($this->staff);
    }
};
?>

<div>
    <x-ui.page-header title="Personnel" description="Les comptes de l'administration, des enseignants et de la comptabilité." />

    @if ($temporaryPassword)
        <div class="mb-6 rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">
            <p class="font-medium">Compte créé. Transmettez ces identifiants à la personne concernée :</p>
            <p class="mt-1 font-mono">{{ $temporaryPassword }}</p>
            <p class="mt-1 text-xs">Ce mot de passe ne sera plus affiché.</p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Nouveau compte">
            <form wire:submit="create" class="space-y-4">
                <x-ui.input label="Nom complet" name="name" />
                <x-ui.input label="Adresse e-mail" name="email" type="email" />
                <x-ui.input label="Téléphone" name="phone" type="tel" />
                <x-ui.select label="Rôle" name="role">
                    @foreach (\App\Enums\Role::staffRoles() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                @if (count($this->sections) > 1 || ! auth()->user()->worksForWholeSchool())
                    <x-ui.select
                        label="Section"
                        name="section"
                        :placeholder="auth()->user()->worksForWholeSchool() ? 'Toute l\'école' : null"
                        hint="Un comptable voit toujours toute l'école."
                    >
                        @foreach ($this->sections as $option)
                            <option value="{{ $option->value }}" wire:key="section-{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
                <x-ui.button class="w-full">Créer le compte</x-ui.button>
            </form>
        </x-ui.card>

        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white lg:col-span-2">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Nom</th>
                        <th class="px-4 py-3">Rôle</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($this->staff as $member)
                        <tr wire:key="user-{{ $member->id }}" @class(['text-zinc-400' => ! $member->is_active])>
                            <td class="px-4 py-3 font-medium">
                                {{ $member->name }}
                                @unless ($member->is_active)
                                    <span class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-normal text-zinc-500">Désactivé</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3">
                                <p>{{ $member->role->label() }}</p>
                                <p class="text-xs text-zinc-500">{{ $member->section?->label() ?? 'Toute l\'école' }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p>{{ $member->email }}</p>
                                <p class="text-xs text-zinc-500">{{ $member->phone }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @unless ($member->is(auth()->user()))
                                    <button
                                        type="button"
                                        wire:click="toggleActive({{ $member->id }})"
                                        wire:confirm="{{ $member->is_active ? 'Désactiver' : 'Réactiver' }} le compte de {{ $member->name }} ?"
                                        @class([
                                            'text-sm font-medium hover:underline',
                                            'text-red-600' => $member->is_active,
                                            'text-brand-700' => ! $member->is_active,
                                        ])
                                    >{{ $member->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
