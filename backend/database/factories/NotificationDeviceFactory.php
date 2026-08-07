<?php

namespace Database\Factories;

use App\Enums\DevicePlatform;
use App\Models\NotificationDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationDevice>
 */
class NotificationDeviceFactory extends Factory
{
    protected $model = NotificationDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = 'tok_'.Str::random(48);

        return [
            'user_id' => User::factory(),
            'platform' => DevicePlatform::ANDROID->value,
            'token' => $token,
            'token_hash' => NotificationDevice::hashToken($token),
            'device_name' => fake()->randomElement(['Pixel 8', 'iPhone 15', 'Galaxy S24']),
            'app_version' => '1.0.0',
            'last_used_at' => now(),
        ];
    }

    public function platform(DevicePlatform $platform): static
    {
        return $this->state(['platform' => $platform->value]);
    }

    public function revoked(): static
    {
        return $this->state([
            'revoked_at' => now(),
            'revoked_reason' => 'Revoked in test setup.',
        ]);
    }
}
