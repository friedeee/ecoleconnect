<?php

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Mot de passe')] class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        $user = auth()->user();

        $this->validate([
            // A temporary password was just typed at login: asking for it again adds nothing.
            'current_password' => $user->must_change_password ? ['nullable'] : ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], attributes: ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);

        if (Hash::check($this->password, $user->password)) {
            $this->addError('password', 'Choisissez un mot de passe différent de l\'actuel.');

            return;
        }

        $user->forceFill(['password' => $this->password, 'must_change_password' => false])->save();

        ActivityLog::record('auth.password-changed', 'Mot de passe changé');

        session()->regenerate();
        session()->flash('status', 'Votre mot de passe a été enregistré.');

        $this->redirectRoute('dashboard', navigate: true);
    }
};
?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">
        {{ auth()->user()->must_change_password ? 'Choisissez votre mot de passe' : 'Changer de mot de passe' }}
    </h1>
    <p class="mt-1.5 text-sm text-zinc-500">
        @if (auth()->user()->must_change_password)
            Le mot de passe que l'école vous a remis est provisoire. Choisissez-en un personnel, que vous seul connaissez.
        @else
            Bonjour {{ auth()->user()->name }}.
        @endif
    </p>

    <form wire:submit="save" class="mt-8 space-y-5">
        @unless (auth()->user()->must_change_password)
            <x-ui.input label="Mot de passe actuel" name="current_password" type="password" autocomplete="current-password" />
        @endunless
        <x-ui.input label="Nouveau mot de passe" name="password" type="password" autocomplete="new-password" hint="Au moins 8 caractères, avec des lettres et des chiffres." />
        <x-ui.input label="Confirmez le mot de passe" name="password_confirmation" type="password" autocomplete="new-password" />

        <x-ui.button class="w-full">Enregistrer</x-ui.button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm text-zinc-500 hover:underline">Se déconnecter</button>
    </form>
</div>
