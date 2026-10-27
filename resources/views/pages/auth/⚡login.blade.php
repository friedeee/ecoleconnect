<?php

use App\Models\ActivityLog;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Connexion')] class extends Component
{
    /** An e-mail address for staff, or a phone number for parents. */
    #[Validate('required|string|max:255')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /** Who is signing in, to label the identifier field: "staff" (e-mail) or "parent" (phone). */
    public string $audience = 'staff';

    public function updatedAudience(): void
    {
        $this->audience = $this->audience === 'parent' ? 'parent' : 'staff';
        $this->reset('email');
        $this->resetValidation();
    }

    public function login(): void
    {
        $this->validate();

        // Each tab only signs in its own accounts: staff with an e-mail address, parents with a phone number.
        $isEmail = str_contains($this->email, '@');

        if ($this->audience === 'staff' && ! $isEmail) {
            throw ValidationException::withMessages([
                'email' => 'Saisissez votre adresse e-mail. Parent d\'élève ? Choisissez l\'onglet « Parent d\'élève ».',
            ]);
        }

        if ($this->audience === 'parent' && $isEmail) {
            throw ValidationException::withMessages([
                'email' => 'Saisissez votre numéro de téléphone. Membre du personnel ? Choisissez l\'onglet « Personnel ».',
            ]);
        }

        // Two locks: one per account and computer, and one per account whatever the computer,
        // so that changing networks does not allow guessing a password.
        $identifier = $isEmail ? Str::lower(trim($this->email)) : User::normalizePhone($this->email);
        $throttleKey = Str::transliterate($identifier.'|'.request()->ip());
        $accountKey = 'login-account:'.Str::transliterate($identifier);

        foreach ([[$throttleKey, 5], [$accountKey, 10]] as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    'email' => 'Trop de tentatives. Réessayez dans '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
                ]);
            }
        }

        if (! $this->attempt()) {
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($accountKey, 15 * 60);

            throw ValidationException::withMessages([
                'email' => 'Ces identifiants ne correspondent à aucun compte actif.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($accountKey);
        ActivityLog::record('auth.login', 'Connexion', properties: ['agent' => mb_substr((string) request()->userAgent(), 0, 200)]);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'), navigate: true);
    }

    /**
     * Staff sign in with their e-mail address; parents with the phone number the school recorded.
     */
    protected function attempt(): bool
    {
        if ($this->audience === 'staff') {
            $user = User::where('email', Str::lower(trim($this->email)))
                ->where('is_active', true)
                ->where('role', '!=', Role::Parent->value)
                ->first();

            if ($user === null || ! Hash::check($this->password, $user->password)) {
                return false;
            }

            Auth::login($user, $this->remember);

            return true;
        }

        $phone = User::normalizePhone($this->email);

        // A parent with children in two schools has one account in each: the password tells them apart.
        $parent = strlen($phone) < 8 ? null : User::where('role', Role::Parent)
            ->where('phone', $phone)
            ->where('is_active', true)
            ->get()
            ->first(fn (User $user) => Hash::check($this->password, $user->password));

        if ($parent === null) {
            return false;
        }

        Auth::login($parent, $this->remember);

        return true;
    }
};
?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight text-zinc-950">Bon retour</h1>
    <p class="mt-1.5 text-sm text-zinc-500">Connectez-vous à votre espace ÉcoleConnect.</p>

    <div class="mt-7 grid grid-cols-2 rounded-xl bg-zinc-100 p-1 text-sm font-medium" role="tablist" aria-label="Je suis">
        @foreach (['staff' => ['briefcase', 'Personnel'], 'parent' => ['users', 'Parent d\'élève']] as $value => [$icon, $label])
            <button
                type="button"
                role="tab"
                wire:key="audience-{{ $value }}"
                wire:click="$set('audience', '{{ $value }}')"
                aria-selected="{{ $audience === $value ? 'true' : 'false' }}"
                @class([
                    'flex items-center justify-center gap-2 rounded-lg px-3 py-2 transition',
                    'bg-white text-zinc-900 shadow-sm' => $audience === $value,
                    'text-zinc-500 hover:text-zinc-800' => $audience !== $value,
                ])
            >
                <x-ui.icon :name="$icon" class="size-4" />
                {{ $label }}
            </button>
        @endforeach
    </div>

    <form wire:submit="login" class="mt-6 space-y-5">
        @if ($audience === 'parent')
            <x-ui.input
                wire:key="identifier-parent"
                label="Numéro de téléphone"
                name="email"
                type="tel"
                icon="phone"
                inputmode="tel"
                autocomplete="username"
                placeholder="01 97 00 00 00"
                hint="Le numéro que vous avez donné à l'école lors de l'inscription."
                autofocus
            />
        @else
            <x-ui.input
                wire:key="identifier-staff"
                label="Adresse e-mail"
                name="email"
                type="email"
                icon="envelope"
                autocomplete="username"
                placeholder="vous@ecole.bj"
                autofocus
            />
        @endif

        <x-ui.input label="Mot de passe" name="password" type="password" icon="lock" autocomplete="current-password" placeholder="••••••••" />

        <label class="flex items-center gap-2 text-sm text-zinc-600">
            <input type="checkbox" wire:model="remember" class="size-4 rounded border-zinc-300 accent-brand-600">
            Rester connecté sur cet appareil
        </label>

        <x-ui.button class="w-full py-3" wire:loading.attr="disabled" wire:target="login">
            <svg wire:loading wire:target="login" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-25" />
                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
            </svg>
            <span wire:loading.remove wire:target="login">Se connecter</span>
            <span wire:loading wire:target="login">Connexion…</span>
        </x-ui.button>
    </form>

    <div class="mt-8 flex items-start gap-3 rounded-xl bg-zinc-50 px-4 py-3 text-xs text-zinc-500 ring-1 ring-zinc-200/70">
        <x-ui.icon name="lock" class="mt-0.5 size-4 text-zinc-400" />
        <p>
            @if ($audience === 'parent')
                Pas encore d'accès, ou mot de passe oublié ? Demandez vos identifiants à l'administration de l'école de votre enfant.
            @else
                Mot de passe oublié ? Demandez à l'administrateur de votre école de vous en donner un nouveau.
            @endif
        </p>
    </div>
</div>
