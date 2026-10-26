<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brain now runs on Claude, Gemini and Grok (and Groq, if a key is added): every model in /admin/models has a
     * provider, and a "fast" switch that makes it think as little as it allows (Claude: low effort; Gemini: a thinking
     * budget of zero).
     *
     * The starting models replace the Groq ones, picked by measuring real answers to a persona-sized prompt:
     *   Gemini 3.5 Flash-Lite   0.9 s   (answers without thinking)
     *   Grok 4.20 non-reasoning 1.4 s
     *   Claude Haiku 5.5        1.4 s   at low effort (2.5 s at its default)
     *   Claude Sonnet 5.5       1.7 s   at low effort (2.5 s at its default)
     *   Gemini 3.8 Flash        1.7 s   with thinking off (5.9 s with it on)
     * Texting uses Claude (Haiku on Free, Sonnet on Paid); calls use the fastest, Gemini 3.5 Flash-Lite. Each model has a
     * fallback on another provider, so one provider being down or out of credit doesn't silence anyone. The "backup"
     * tier is never chosen on its own: those models only answer when something else fails.
     *
     * Prices are US cents per million tokens, as published (Gemini 3.8 Flash's is an estimate: check it).
     */
    public function up(): void
    {
        Schema::table('llm_models', function (Blueprint $table) {
            $table->boolean('fast_thinking')->default(false)->after('is_active');
        });

        DB::table('llm_models')->where('provider', 'groq')->whereIn('label', self::GROQ_STARTERS)->delete();

        $now = now();
        $add = fn (array $row) => DB::table('llm_models')->insertGetId($row + ['is_active' => true, 'plan_tier' => null, 'fast_thinking' => false, 'max_tokens' => 1024, 'created_at' => $now, 'updated_at' => $now]);

        $backupLite = $add(['provider' => 'gemini', 'model_id' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite (backup)', 'purpose' => 'chat', 'plan_tier' => 'backup', 'input_cents_per_mtok' => 30, 'output_cents_per_mtok' => 250, 'sort' => 7]);
        $backupFlash = $add(['provider' => 'gemini', 'model_id' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash (backup)', 'purpose' => 'chat', 'plan_tier' => 'backup', 'fast_thinking' => true, 'input_cents_per_mtok' => 100, 'output_cents_per_mtok' => 600, 'sort' => 8]);
        $backupGrok = $add(['provider' => 'xai', 'model_id' => 'grok-4.20-0309-non-reasoning', 'label' => 'Grok 4.20 fast (backup)', 'purpose' => 'chat', 'plan_tier' => 'backup', 'input_cents_per_mtok' => 125, 'output_cents_per_mtok' => 250, 'sort' => 9]);

        $haiku = $add(['provider' => 'anthropic', 'model_id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (fast)', 'purpose' => 'chat', 'plan_tier' => 'small', 'fast_thinking' => true, 'input_cents_per_mtok' => 10, 'output_cents_per_mtok' => 50, 'fallback_id' => $backupLite, 'sort' => 1]);
        $add(['provider' => 'anthropic', 'model_id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'purpose' => 'chat', 'plan_tier' => 'large', 'fast_thinking' => true, 'input_cents_per_mtok' => 200, 'output_cents_per_mtok' => 1000, 'fallback_id' => $backupFlash, 'sort' => 2]);
        $add(['provider' => 'anthropic', 'model_id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5 (memory)', 'purpose' => 'memory', 'input_cents_per_mtok' => 200, 'output_cents_per_mtok' => 1000, 'max_tokens' => 4096, 'fallback_id' => $backupFlash, 'sort' => 3]);
        $add(['provider' => 'anthropic', 'model_id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (safety checks)', 'purpose' => 'moderation', 'fast_thinking' => true, 'input_cents_per_mtok' => 10, 'output_cents_per_mtok' => 50, 'fallback_id' => $backupLite, 'sort' => 4]);
        $add(['provider' => 'gemini', 'model_id' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite (picture scenes)', 'purpose' => 'scenes', 'input_cents_per_mtok' => 30, 'output_cents_per_mtok' => 250, 'fallback_id' => $haiku, 'sort' => 5]);
        $add(['provider' => 'gemini', 'model_id' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite (calls)', 'purpose' => 'voice', 'input_cents_per_mtok' => 30, 'output_cents_per_mtok' => 250, 'max_tokens' => 512, 'fallback_id' => $backupGrok, 'sort' => 6]);
    }

    /** The starting models are removed (add the ones you want in /admin/models). */
    public function down(): void
    {
        DB::table('llm_models')->whereIn('label', self::STARTERS)->delete();

        Schema::table('llm_models', function (Blueprint $table) {
            $table->dropColumn('fast_thinking');
        });
    }

    private const GROQ_STARTERS = ['Llama 3.1 8B (fast)', 'Llama 3.3 70B', 'Llama 3.3 70B (memory)', 'Llama 3.1 8B (safety checks)', 'Llama 3.1 8B (picture scenes)'];

    private const STARTERS = [
        'Gemini 3.5 Flash-Lite (backup)', 'Gemini 3.8 Flash (backup)', 'Grok 4.20 fast (backup)', 'Claude Haiku 5.5 (fast)', 'Claude Sonnet 5.5',
        'Claude Sonnet 5.5 (memory)', 'Claude Haiku 5.5 (safety checks)', 'Gemini 3.5 Flash-Lite (picture scenes)', 'Gemini 3.5 Flash-Lite (calls)',
    ];
};
