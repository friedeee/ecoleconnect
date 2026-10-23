<?php

namespace App\Console\Commands;

use App\Enums\Cycle;
use App\Models\School;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

#[Signature('school:create {name? : Nom de l\'école} {--admin-name= : Nom de l\'administrateur} {--admin-email= : E-mail de l\'administrateur} {--city= : Ville} {--cycle=* : Cycles proposés (nursery, primary, secondary)}')]
#[Description('Crée une école cliente et son premier compte administrateur')]
class CreateSchool extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name') ?? text('Nom de l\'école', required: true);
        $adminName = $this->option('admin-name') ?? text('Nom de l\'administrateur', required: true);
        $adminEmail = Str::lower($this->option('admin-email') ?? text('E-mail de l\'administrateur', required: true));
        $cycles = $this->option('cycle') ?: multiselect(
            'Cycles proposés',
            collect(Cycle::cases())->mapWithKeys(fn (Cycle $cycle) => [$cycle->value => $cycle->label()])->all(),
            required: true,
        );

        $validator = Validator::make(
            ['name' => $name, 'admin_name' => $adminName, 'admin_email' => $adminEmail, 'cycles' => $cycles],
            [
                'name' => ['required', 'string', 'max:255'],
                'admin_name' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'email', 'unique:users,email'],
                'cycles' => ['required', 'array', 'min:1'],
                'cycles.*' => [Rule::enum(Cycle::class)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = Str::password(12, symbols: false);

        $school = School::open([
            'name' => $name,
            'city' => $this->option('city'),
            'cycles' => array_values(array_filter(Cycle::cases(), fn (Cycle $cycle) => in_array($cycle->value, $cycles, true))),
        ], $adminName, $adminEmail, $password);

        $this->info("École « {$school->name} » créée.");
        $this->table(['Connexion', 'Valeur'], [
            ['Adresse', route('login')],
            ['E-mail', $adminEmail],
            ['Mot de passe provisoire', $password],
        ]);

        return self::SUCCESS;
    }
}
