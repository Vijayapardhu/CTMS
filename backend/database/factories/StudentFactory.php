<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'registration_number' => 'REG'.fake()->unique()->numberBetween(10000, 99999),
            'department' => fake()->randomElement(['Computer Science', 'Mechanical', 'Electrical', 'Civil', 'Biotech']),
            // Stored as a string in the students table.
            'year_of_study' => (string) fake()->numberBetween(1, 4),
            'has_valid_ticket' => true,
            'ticket_expiry_date' => now()->addMonths(6),
            'status' => 'ACTIVE',
        ];
    }

    /**
     * A student with no active transport ticket.
     */
    public function withoutTicket(): static
    {
        return $this->state([
            'has_valid_ticket' => false,
            'ticket_expiry_date' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'SUSPENDED']);
    }
}
