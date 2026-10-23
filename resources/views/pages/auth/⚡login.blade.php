<?php

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

    public function login(): void
    {
        $this->validate();

        $throttleKey = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($throttleKey).' secondes.',
            ]);
        }

        if (! $this->attempt()) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => 'Ces identifiants ne correspondent à aucun compte actif.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'), navigate: true);
    }

    /**
     * Staff sign in with their e-mail address; parents with the phone number the school recorded.
     */
    protected function attempt(): bool
    {
        if (str_contains($this->email, '@')) {
            return Auth::attempt(['email' => Str::lower(trim($this->email)), 'password' => $this->password, 'is_active' => true], $this->remember);
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
    <h1 class="text-2xl font-semibold tracking-tight">Bon retour</h1>
    <p class="mt-1.5 text-sm text-zinc-500">Personnel de l'école ou parent d'élève, connectez-vous à votre espace.</p>

    <form wire:submit="login" class="mt-8 space-y-5">
        <x-ui.input label="E-mail ou numéro de téléphone" name="email" autocomplete="username" placeholder="vous@ecole.bj ou 01 97 00 00 00" hint="Parents : utilisez le numéro de téléphone donné à l'école." autofocus />
        <x-ui.input label="Mot de passe" name="password" type="password" autocomplete="current-password" placeholder="••••••••" />

        <label class="flex items-center gap-2 text-sm text-zinc-600">
            <input type="checkbox" wire:model="remember" class="size-4 rounded border-zinc-300 accent-brand-600">
            Rester connecté
        </label>

        <x-ui.button class="w-full">
            <span wire:loading.remove wire:target="login">Se connecter</span>
            <span wire:loading wire:target="login">Connexion…</span>
        </x-ui.button>
    </form>

    <p class="mt-8 text-center text-xs text-zinc-400">Mot de passe oublié ? Contactez l'administration de votre école.</p>
</div>
