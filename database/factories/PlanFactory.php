<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'price_cents' => 999,
            'is_default' => false,
            'sort' => 10,
            'limits' => [
                'contacts' => 5,
                'metered' => ['text_messages' => 10, 'voice_seconds' => 120, 'images' => 2],
                'features' => ['rated_r' => false, 'voicemail' => true, 'videos' => false],
                'llm_tier' => 'small',
                'worry_max_stage' => 3,
                'away_outreach_days' => 5,
            ],
        ];
    }
}
