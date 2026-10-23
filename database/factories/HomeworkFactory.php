<?php

namespace Database\Factories;

use App\Models\Homework;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Homework>
 */
class HomeworkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'school_id' => fn (array $attributes): int => SchoolClass::withoutGlobalScopes()->findOrFail($attributes['school_class_id'])->school_id,
            'subject_id' => fn (array $attributes): int => Subject::factory()->create(['school_id' => $attributes['school_id']])->id,
            'title' => 'Exercices '.fake()->numberBetween(1, 20).' à '.fake()->numberBetween(21, 40).' page '.fake()->numberBetween(10, 120),
            'instructions' => fake()->sentence(12),
            'given_on' => '2026-10-14',
            'due_on' => '2026-10-19',
        ];
    }
}
