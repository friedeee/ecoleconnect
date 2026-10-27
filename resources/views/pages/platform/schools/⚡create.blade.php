<?php

use App\Models\ActivityLog;
use App\Enums\Cycle;
use App\Models\School;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ouvrir une école')] class extends Component
{
    public string $name = '';

    public string $city = '';

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    /** @var list<string> */
    public array $cycles = [];

    public string $admin_name = '';

    public string $admin_email = '';

    /**
     * Login details of the school just opened, shown once.
     *
     * @var array{school: string, email: string, password: string}|null
     */
    public ?array $credentials = null;

    public function create(): void
    {
        Gate::authorize('manage-platform');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 .-]{8,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'cycles' => ['required', 'array', 'min:1'],
            'cycles.*' => ['distinct', Rule::enum(Cycle::class)],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ], [
            'cycles.required' => 'Cochez au moins un cycle.',
        ], [
            'name' => 'nom de l\'école',
            'city' => 'ville',
            'address' => 'adresse',
            'phone' => 'téléphone',
            'email' => 'e-mail de l\'école',
            'admin_name' => 'nom de l\'administrateur',
            'admin_email' => 'e-mail de l\'administrateur',
        ]);

        $password = Str::password(12, symbols: false);

        $school = School::open([
            'name' => trim($validated['name']),
            'city' => $validated['city'] ?: null,
            'address' => $validated['address'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'email' => $validated['email'] ? Str::lower($validated['email']) : null,
            // Keep the cycles in teaching order, whatever order they were ticked in.
            'cycles' => array_values(array_filter(Cycle::cases(), fn (Cycle $cycle) => in_array($cycle->value, $validated['cycles'], true))),
        ], trim($validated['admin_name']), $validated['admin_email'], $password);

        $this->credentials = ['school' => $school->name, 'email' => Str::lower($validated['admin_email']), 'password' => $password];
        ActivityLog::record('platform.school-opened', 'École '.$school->name.' ouverte, administrateur '.$this->credentials['email'], $school);
        $this->reset(['name', 'city', 'address', 'phone', 'email', 'cycles', 'admin_name', 'admin_email']);
    }
};
?>

<div>
    <x-ui.page-header eyebrow="Plateforme" title="Ouvrir une école" description="Crée l'école, son année scolaire en cours, les matières de ses cycles et le compte de son administrateur.">
        <x-ui.button :href="route('platform.schools.index')" variant="secondary">Retour aux écoles</x-ui.button>
    </x-ui.page-header>

    @if ($credentials)
        <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50 px-5 py-4 text-sm text-brand-900" role="status">
            <p class="font-semibold">« {{ $credentials['school'] }} » est ouverte. Transmettez ces identifiants à son administrateur :</p>
            <dl class="mt-3 grid gap-1 font-mono sm:grid-cols-[auto_1fr] sm:gap-x-4">
                <dt class="text-brand-700">Adresse</dt><dd>{{ route('login') }}</dd>
                <dt class="text-brand-700">E-mail</dt><dd>{{ $credentials['email'] }}</dd>
                <dt class="text-brand-700">Mot de passe</dt><dd>{{ $credentials['password'] }}</dd>
            </dl>
            <p class="mt-3 text-xs">Ce mot de passe ne sera plus affiché.</p>
        </div>
    @endif

    <form wire:submit="create" class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="L'école" icon="school" class="lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Nom de l'école" name="name" placeholder="Complexe Scolaire Les Palmiers" class="sm:col-span-2" />
                <x-ui.input label="Ville" name="city" placeholder="Cotonou" />
                <x-ui.input label="Adresse" name="address" />
                <x-ui.input label="Téléphone" name="phone" type="tel" placeholder="+229 01 00 00 00 00" />
                <x-ui.input label="E-mail de l'école" name="email" type="email" />
            </div>

            <fieldset class="mt-6">
                <legend class="mb-1.5 text-sm font-medium text-zinc-700">Cycles proposés</legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach (\App\Enums\Cycle::cases() as $cycle)
                        <label wire:key="cycle-{{ $cycle->value }}" class="flex cursor-pointer items-start gap-3 rounded-xl p-3.5 ring-1 ring-zinc-300 ring-inset transition has-checked:bg-brand-50 has-checked:ring-2 has-checked:ring-brand-500">
                            <input type="checkbox" wire:model="cycles" value="{{ $cycle->value }}" class="mt-0.5 size-4 rounded border-zinc-300 accent-brand-600">
                            <span>
                                <span class="block text-sm font-semibold text-zinc-900">{{ $cycle->label() }}</span>
                                <span class="block text-xs text-zinc-500">{{ collect($cycle->levels())->first()->value }} → {{ collect($cycle->levels())->last()->value }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('cycles')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </fieldset>
        </x-ui.card>

        <div class="space-y-6">
            <x-ui.card title="Premier administrateur" icon="user-plus">
                <div class="space-y-4">
                    <x-ui.input label="Nom complet" name="admin_name" placeholder="Directeur ou fondateur" />
                    <x-ui.input label="E-mail de connexion" name="admin_email" type="email" />
                    <p class="text-xs text-zinc-500">Un mot de passe provisoire sera généré et affiché une seule fois.</p>
                </div>
            </x-ui.card>

            <x-ui.button class="w-full" icon="check">
                <span wire:loading.remove wire:target="create">Ouvrir l'école</span>
                <span wire:loading wire:target="create">Création…</span>
            </x-ui.button>
        </div>
    </form>
</div>
