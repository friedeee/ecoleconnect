<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Mark;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mark>
 */
class MarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'school_id' => fn (array $attributes): int => Assessment::withoutGlobalScopes()->findOrFail($attributes['assessment_id'])->school_id,
            'student_id' => fn (array $attributes): int => Student::factory()->create(['school_id' => $attributes['school_id']])->id,
            'score' => fake()->numberBetween(4, 20),
            'is_absent' => false,
        ];
    }
}
