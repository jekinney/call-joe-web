<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI models contacts think with (managed in /admin/models, so a newer model is a click, not a deploy), and a
     * log of every call for cost and debugging.
     *
     * purpose: chat | memory | moderation | voice. plan_tier: small | large (the plan's llm_tier), or null for any.
     * Prices are US cents per million tokens.
     */
    public function up(): void
    {
        Schema::create('llm_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('groq');
            $table->string('model_id', 120);
            $table->string('label', 80);
            $table->string('purpose', 20)->index();
            $table->string('plan_tier', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('fallback_id')->nullable()->constrained('llm_models')->nullOnDelete();
            $table->decimal('input_cents_per_mtok', 10, 2)->default(0);
            $table->decimal('output_cents_per_mtok', 10, 2)->default(0);
            $table->unsignedInteger('max_tokens')->default(1024);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('llm_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('llm_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 120);
            $table->string('purpose', 20);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 20); // ok | error | fallback (answered by the fallback model)
            $table->string('error', 300)->nullable();
            $table->unsignedBigInteger('cost_micros')->default(0); // millionths of a dollar
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Starting models (Groq, per the plan: Llama 3.1 8B for Free, Llama 3.3 70B for Paid). Change them in /admin/models.
        $now = now();
        $small = DB::table('llm_models')->insertGetId([
            'model_id' => 'llama-3.1-8b-instant', 'label' => 'Llama 3.1 8B (fast)', 'purpose' => 'chat', 'plan_tier' => 'small',
            'input_cents_per_mtok' => 5, 'output_cents_per_mtok' => 8, 'max_tokens' => 1024, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('llm_models')->insert([
            ['model_id' => 'llama-3.3-70b-versatile', 'label' => 'Llama 3.3 70B', 'purpose' => 'chat', 'plan_tier' => 'large', 'fallback_id' => $small,
                'input_cents_per_mtok' => 59, 'output_cents_per_mtok' => 79, 'max_tokens' => 1024, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['model_id' => 'llama-3.3-70b-versatile', 'label' => 'Llama 3.3 70B (memory)', 'purpose' => 'memory', 'plan_tier' => null, 'fallback_id' => $small,
                'input_cents_per_mtok' => 59, 'output_cents_per_mtok' => 79, 'max_tokens' => 2048, 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_calls');
        Schema::dropIfExists('llm_models');
    }
};
