<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TimetableEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetableEntry>
 */
class TimetableEntryFactory extends Factory
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
            'teacher_id' => null,
            'day' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'room' => null,
        ];
    }
}
