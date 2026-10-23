<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('platform:create-admin {--name= : Nom complet} {--email= : E-mail de connexion}')]
#[Description('Crée un compte opérateur de la plateforme, qui ouvre et gère les écoles clientes')]
class CreateSuperAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?? text('Nom complet', required: true);
        $email = Str::lower($this->option('email') ?? text('E-mail de connexion', required: true));
        $password = password('Mot de passe (12 caractères minimum)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['school_id' => null, 'role' => Role::SuperAdmin])->save();

        $this->info("Compte opérateur créé pour {$email}. Connectez-vous sur ".route('login').'.');

        return self::SUCCESS;
    }
}
