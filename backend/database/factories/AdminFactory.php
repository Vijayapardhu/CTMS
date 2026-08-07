<?php

namespace Database\Factories;

use App\Enums\AccessLevel;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'designation' => fake()->randomElement(['Transport Officer', 'Fleet Manager', 'Operations Head']),
            'department' => 'Transport',
            // Must be one of the values in the admins.access_level column
            // definition — 'standard' or similar would fail the CHECK constraint.
            'access_level' => AccessLevel::OPERATIONS->value,
            'can_approve_incidents' => true,
            'can_manage_routes' => true,
            'can_manage_drivers' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['access_level' => AccessLevel::SUPER_ADMIN->value]);
    }

    public function viewer(): static
    {
        return $this->state([
            'access_level' => AccessLevel::VIEWER->value,
            'can_approve_incidents' => false,
            'can_manage_routes' => false,
            'can_manage_drivers' => false,
        ]);
    }
}
