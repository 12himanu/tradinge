<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'timezone' => 'Asia/Kathmandu',
            'settings' => config('stock_ai.default_user_settings'),
            // Set explicitly: strict mode rejects reading attributes a fresh factory model never had.
            'default_exchange_id' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
