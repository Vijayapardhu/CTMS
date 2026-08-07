<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The known plaintext password every factory user is created with, so
     * tests can log in as them.
     */
    public const PASSWORD = 'Str0ng!Passw0rd';

    /** Hashed once per run — bcrypt is deliberately slow. */
    protected static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => '+91'.fake()->unique()->numerify('##########'),
            'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            // Least privilege by default: a test that needs elevation must ask.
            'role' => UserRole::STUDENT->value,
            'is_active' => true,
            'email_verified' => true,
            'email_verified_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::ADMIN->value]);
    }

    public function driver(): static
    {
        return $this->state(['role' => UserRole::DRIVER->value]);
    }

    public function student(): static
    {
        return $this->state(['role' => UserRole::STUDENT->value]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
