<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\GradingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingPeriod>
 */
class GradingPeriodFactory extends Factory
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
            'number' => 1,
            'name' => '1er trimestre',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-12-18',
        ];
    }
}
