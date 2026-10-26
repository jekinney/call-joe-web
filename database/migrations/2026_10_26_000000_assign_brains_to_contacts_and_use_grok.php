<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who thinks for a contact, and who makes their pictures.
     *
     * - Each contact has a brain (contacts.brain_id): the model that writes their texts. Only the person who creates
     *   the contact picks it (in /admin/contacts), so what a contact costs is decided by whoever built them: a
     *   cheap Gemini for a lower-tier contact, Grok or Claude for a better one. Users never choose or change it.
     * - Grok writes texts by default (any contact without a brain) and makes the pictures (Grok Imagine, $0.02 each).
     *   Claude does the careful jobs (memory notes, safety checks, picture scenes). Gemini is the cheap option to assign,
     *   and runs calls. The "assigned" tier is never picked by a plan: those models answer only when a contact is given
     *   one, or when something else fails.
     * - image_requests.cost_micros: what each picture cost (millionths of a dollar; nothing when reused).
     *
     * Prices are US cents per million tokens, as published (Gemini 3.8 Flash's is an estimate: check it).
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('brain_id')->nullable()->after('required_plan_id')->constrained('llm_models')->nullOnDelete();
        });
        Schema::table('image_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_micros')->default(0)->after('from_cache');
        });

        DB::table('llm_models')->whereIn('label', self::PREVIOUS)->delete();

        $now = now();
        $add = fn (array $row) => DB::table('llm_models')->insertGetId($row + [
            'is_active' => true, 'plan_tier' => null, 'fast_thinking' => false, 'max_tokens' => 1024, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $grok = $add(['provider' => 'xai', 'model_id' => 'grok-4.20-0309-non-reasoning', 'label' => 'Grok 4.20 fast', 'purpose' => 'chat', 'input_cents_per_mtok' => 125, 'output_cents_per_mtok' => 250, 'sort' => 1]);
        $haiku = $add(['provider' => 'anthropic', 'model_id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (fast)', 'purpose' => 'chat', 'plan_tier' => 'assigned', 'fast_thinking' => true, 'input_cents_per_mtok' => 10, 'output_cents_per_mtok' => 50, 'sort' => 2]);
        $sonnet = $add(['provider' => 'anthropic', 'model_id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'purpose' => 'chat', 'plan_tier' => 'assigned', 'fast_thinking' => true, 'input_cents_per_mtok' => 200, 'output_cents_per_mtok' => 1000, 'sort' => 3]);
        $lite = $add(['provider' => 'gemini', 'model_id' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite', 'purpose' => 'chat', 'plan_tier' => 'assigned', 'input_cents_per_mtok' => 30, 'output_cents_per_mtok' => 250, 'sort' => 4]);
        $flash = $add(['provider' => 'gemini', 'model_id' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'purpose' => 'chat', 'plan_tier' => 'assigned', 'fast_thinking' => true, 'input_cents_per_mtok' => 100, 'output_cents_per_mtok' => 600, 'sort' => 5]);
        $memory = $add(['provider' => 'anthropic', 'model_id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5 (memory)', 'purpose' => 'memory', 'input_cents_per_mtok' => 200, 'output_cents_per_mtok' => 1000, 'max_tokens' => 4096, 'sort' => 6]);
        $safety = $add(['provider' => 'anthropic', 'model_id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (safety checks)', 'purpose' => 'moderation', 'fast_thinking' => true, 'input_cents_per_mtok' => 10, 'output_cents_per_mtok' => 50, 'sort' => 7]);
        $scenes = $add(['provider' => 'anthropic', 'model_id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (picture scenes)', 'purpose' => 'scenes', 'fast_thinking' => true, 'input_cents_per_mtok' => 10, 'output_cents_per_mtok' => 50, 'sort' => 8]);
        $calls = $add(['provider' => 'gemini', 'model_id' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite (calls)', 'purpose' => 'voice', 'input_cents_per_mtok' => 30, 'output_cents_per_mtok' => 250, 'max_tokens' => 512, 'sort' => 9]);

        // If a model fails, another provider answers.
        foreach ([$grok => $haiku, $haiku => $lite, $sonnet => $grok, $lite => $haiku, $flash => $haiku, $memory => $grok, $safety => $grok, $scenes => $grok, $calls => $grok] as $model => $fallback) {
            DB::table('llm_models')->where('id', $model)->update(['fallback_id' => $fallback]);
        }
    }

    /** The starting models are removed (add the ones you want in /admin/models). */
    public function down(): void
    {
        DB::table('llm_models')->whereIn('label', self::STARTERS)->delete();

        Schema::table('image_requests', function (Blueprint $table) {
            $table->dropColumn('cost_micros');
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brain_id');
        });
    }

    private const PREVIOUS = [
        'Gemini 3.5 Flash-Lite (backup)', 'Gemini 3.8 Flash (backup)', 'Grok 4.20 fast (backup)', 'Claude Haiku 5.5 (fast)', 'Claude Sonnet 5.5',
        'Claude Sonnet 5.5 (memory)', 'Claude Haiku 5.5 (safety checks)', 'Gemini 3.5 Flash-Lite (picture scenes)', 'Gemini 3.5 Flash-Lite (calls)',
    ];

    private const STARTERS = [
        'Grok 4.20 fast', 'Claude Haiku 5.5 (fast)', 'Claude Sonnet 5.5', 'Gemini 3.5 Flash-Lite', 'Gemini 3.8 Flash', 'Claude Sonnet 5.5 (memory)',
        'Claude Haiku 5.5 (safety checks)', 'Claude Haiku 5.5 (picture scenes)', 'Gemini 3.5 Flash-Lite (calls)',
    ];
};
