<?php

namespace Database\Factories;

use App\Enums\ClassLevel;
use App\Models\AcademicYear;
use App\Models\FeeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeItem>
 */
class FeeItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'school_id' => fn (array $attributes): int => AcademicYear::withoutGlobalScopes()->findOrFail($attributes['academic_year_id'])->school_id,
            'level' => fake()->randomElement(ClassLevel::cases()),
            'label' => 'Scolarité',
            'amount' => fake()->numberBetween(5, 60) * 1000,
            'due_on' => null,
            'is_optional' => false,
        ];
    }

    public function optional(): static
    {
        return $this->state(fn (array $attributes) => ['is_optional' => true]);
    }
}
