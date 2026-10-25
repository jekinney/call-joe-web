<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->firstName().' '.fake()->lastName();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'tagline' => fake()->sentence(6),
            'gender' => fake()->randomElement(['woman', 'man']),
            'persona_md' => "# Who you are\nYou're {$name}, on the phone with your buddy.\n",
            'looks' => 'A friendly face with warm brown eyes.',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'avatar_path' => 'contacts/avatar-test.jpg',
        ])->afterCreating(function (Contact $contact) {
            $contact->forceFill(['is_published' => true, 'published_at' => now()])->save();
        });
    }
}
