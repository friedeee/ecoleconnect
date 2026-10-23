<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Enums\DaySession;
use App\Models\Absence;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absence>
 */
class AbsenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'school_id' => fn (array $attributes): int => Enrollment::withoutGlobalScopes()->findOrFail($attributes['enrollment_id'])->school_id,
            'student_id' => fn (array $attributes): int => Enrollment::withoutGlobalScopes()->findOrFail($attributes['enrollment_id'])->student_id,
            'school_class_id' => fn (array $attributes): int => Enrollment::withoutGlobalScopes()->findOrFail($attributes['enrollment_id'])->school_class_id,
            'date' => '2026-10-12',
            'session' => DaySession::Morning,
            'status' => AttendanceStatus::Absent,
            'is_justified' => false,
        ];
    }

    /**
     * Absences have no enrollment column: it only helps the factory find the student and the class.
     */
    public function configure(): static
    {
        return $this->afterMaking(fn (Absence $absence) => $absence->offsetUnset('enrollment_id'));
    }
}
