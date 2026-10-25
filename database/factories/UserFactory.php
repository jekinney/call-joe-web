<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'date_of_birth' => now()->subYears(fake()->numberBetween(21, 70))->toDateString(),
            'timezone' => 'UTC',
            'locale' => 'en',
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Someone who runs the service (/admin).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Someone under 18 (never allowed in).
     */
    public function minor(): static
    {
        return $this->state(fn (array $attributes) => [
            'date_of_birth' => now()->subYears(16)->toDateString(),
        ]);
    }

    /**
     * An account with no birthday on file.
     */
    public function withoutBirthDate(): static
    {
        return $this->state(fn (array $attributes) => [
            'date_of_birth' => null,
        ]);
    }
}
