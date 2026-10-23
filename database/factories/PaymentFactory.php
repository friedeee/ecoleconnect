<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'academic_year_id' => fn (array $attributes): int => Enrollment::withoutGlobalScopes()->findOrFail($attributes['enrollment_id'])->academic_year_id,
            'student_id' => fn (array $attributes): int => Enrollment::withoutGlobalScopes()->findOrFail($attributes['enrollment_id'])->student_id,
            'receipt_number' => fake()->unique()->numerify('2026-#####'),
            'amount' => fake()->numberBetween(5, 50) * 1000,
            'paid_on' => '2026-10-01',
            'method' => PaymentMethod::Cash,
            'received_by' => fn (array $attributes): User => User::factory()->accountant()->create(['school_id' => $attributes['school_id']]),
        ];
    }

    /**
     * Payments have no enrollment column: it only helps the factory find the student and the year.
     */
    public function configure(): static
    {
        return $this->afterMaking(fn (Payment $payment) => $payment->offsetUnset('enrollment_id'));
    }
}
