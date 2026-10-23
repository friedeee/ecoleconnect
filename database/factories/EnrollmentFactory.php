<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
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
            'academic_year_id' => fn (array $attributes): int => SchoolClass::withoutGlobalScopes()->findOrFail($attributes['school_class_id'])->academic_year_id,
            'student_id' => fn (array $attributes): Student => Student::factory()->create(['school_id' => $attributes['school_id']]),
            'enrolled_on' => '2026-09-14',
            'is_repeating' => false,
        ];
    }
}
