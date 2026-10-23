<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'registration_number' => fake()->unique()->numerify('2026-#####'),
            'gender' => $gender = fake()->randomElement(Gender::cases()),
            'first_name' => fake()->firstName($gender === Gender::Male ? 'male' : 'female'),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-18 years', '-10 years')->format('Y-m-d'),
            'birth_place' => fake()->randomElement(['Cotonou', 'Porto-Novo', 'Parakou', 'Abomey', 'Ouidah']),
            'status' => StudentStatus::Active,
        ];
    }
}
