<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans (Free, Paid) and what each allows. The two starting plans are created here, so every environment has them;
     * admins adjust them later. See App\Models\Plan for the shape of `limits`.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('braintree_plan_id', 100)->nullable();
            $table->jsonb('limits');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('plans')->insert([
            [
                'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_default' => true, 'sort' => 1,
                'limits' => json_encode([
                    'contacts' => 2,
                    'metered' => ['text_messages' => 50, 'voice_seconds' => 10 * 60, 'images' => 5],
                    'features' => ['rated_r' => false, 'voicemail' => false, 'videos' => false],
                    'llm_tier' => 'small',
                    'worry_max_stage' => 2,
                    'away_outreach_days' => 2,
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'paid', 'name' => 'Paid', 'price_cents' => 1499, 'is_default' => false, 'sort' => 2,
                'limits' => json_encode([
                    'contacts' => 25,
                    'metered' => ['text_messages' => 1000, 'voice_seconds' => 300 * 60, 'images' => 100],
                    'features' => ['rated_r' => true, 'voicemail' => true, 'videos' => true],
                    'llm_tier' => 'large',
                    'worry_max_stage' => 6,
                    'away_outreach_days' => 14,
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
