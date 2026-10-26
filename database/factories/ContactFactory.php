<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Llm;
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
            // A cheap Gemini, like a lower-tier contact would get.
            'brain_id' => fn () => Llm::where('purpose', 'chat')->where('model_id', 'gemini-3.5-flash-lite')->value('id'),
        ];
    }

    /** No brain yet: a contact still being set up. */
    public function withoutBrain(): static
    {
        return $this->state(fn () => ['brain_id' => null]);
    }

    public function withVoice(): static
    {
        return $this->state(fn () => ['voice_provider_id' => 'test-voice', 'voice_settings' => ['provider' => 'vapi']]);
    }

    public function published(): static
    {
        return $this->withVoice()->state(fn () => [
            'avatar_path' => 'contacts/avatar-test.jpg',
        ])->afterCreating(function (Contact $contact) {
            $contact->forceFill(['is_published' => true, 'published_at' => now()])->save();
        });
    }
}
