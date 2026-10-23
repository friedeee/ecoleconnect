<?php

namespace Database\Factories;

use App\Enums\DaySession;
use App\Models\RollCall;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RollCall>
 */
class RollCallFactory extends Factory
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
            'date' => '2026-10-12',
            'session' => DaySession::Morning,
        ];
    }
}
