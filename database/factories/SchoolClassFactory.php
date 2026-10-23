<?php

namespace Database\Factories;

use App\Enums\ClassLevel;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
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
            'level' => $level = fake()->randomElement(ClassLevel::cases()),
            'name' => $level->value.' '.fake()->unique()->bothify('?#'),
            'capacity' => 50,
        ];
    }
}
