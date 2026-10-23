<?php

namespace Database\Factories;

use App\Enums\AssessmentKind;
use App\Models\Assessment;
use App\Models\GradingPeriod;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
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
            'grading_period_id' => fn (array $attributes): int => GradingPeriod::factory()->create([
                'academic_year_id' => SchoolClass::withoutGlobalScopes()->findOrFail($attributes['school_class_id'])->academic_year_id,
            ])->id,
            'subject_id' => fn (array $attributes): int => Subject::factory()->create(['school_id' => $attributes['school_id']])->id,
            'kind' => AssessmentKind::Test,
            'held_on' => '2026-10-10',
            'out_of' => 20,
        ];
    }
}
