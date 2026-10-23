<?php

namespace Database\Factories;

use App\Enums\Cycle;
use App\Enums\PeriodType;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $name = 'Complexe Scolaire '.fake()->unique()->lastName(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'city' => fake()->randomElement(['Cotonou', 'Porto-Novo', 'Parakou', 'Abomey-Calavi', 'Bohicon']),
            'address' => fake()->streetAddress(),
            'phone' => '+229 01 '.fake()->numerify('## ## ## ##'),
            'email' => fake()->unique()->safeEmail(),
            'cycles' => Cycle::cases(),
            'period_type' => PeriodType::Trimester,
            'primary_scale' => 10,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the school only teaches the given cycles.
     */
    public function teaching(Cycle ...$cycles): static
    {
        return $this->state(fn (array $attributes) => ['cycles' => $cycles]);
    }
}
