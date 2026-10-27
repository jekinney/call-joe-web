<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Role;
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
            'terms_accepted_at' => now(),
            'terms_version' => config('legal.terms_version'),
        ];
    }

    /**
     * Someone who hasn't accepted the current terms (signed up before they changed).
     */
    public function withOldTerms(): static
    {
        return $this->state(fn (array $attributes) => [
            'terms_version' => '2000-01-01',
        ]);
    }

    /**
     * An account an admin has suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'suspended_at' => now(),
            'flags' => ['suspension_reason' => 'Testing'],
        ]);
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
            'role_id' => Role::owner()->id,
        ]);
    }

    /**
     * An admin whose role allows only these.
     */
    public function allowing(Permission ...$permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::factory()->allowing(...$permissions)->create()->id,
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
