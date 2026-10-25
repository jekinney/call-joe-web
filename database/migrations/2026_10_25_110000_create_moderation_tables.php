<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safety (App\Services\Moderator).
     *
     * - moderation_events: every blocked or flagged text, reply, image prompt or call, with a short excerpt for review
     *   in /admin/moderation (reviewed_at once an admin has looked).
     * - users: flags (strikes, the suspension reason), suspension, and which version of the terms they accepted.
     * - A fast model for the safety checks, editable in /admin/models.
     * - The safety rule every contact follows now also rules out playing younger and real people's likeness.
     */
    public function up(): void
    {
        Schema::create('moderation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source', 20);   // user_text | contact_reply | image_prompt | call
            $table->string('category', 20); // App\Enums\ModerationCategory
            $table->string('action', 10);   // blocked | flagged
            $table->string('excerpt', 300)->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reviewed_at', 'id']);
            $table->index(['user_id', 'id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('flags')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
        });

        $now = now();
        $small = DB::table('llm_models')->where('model_id', 'llama-3.1-8b-instant')->where('purpose', 'chat')->value('id');
        DB::table('llm_models')->insert([
            'model_id' => 'llama-3.1-8b-instant', 'label' => 'Llama 3.1 8B (safety checks)', 'purpose' => 'moderation', 'plan_tier' => null,
            'fallback_id' => $small, 'input_cents_per_mtok' => 5, 'output_cents_per_mtok' => 8, 'max_tokens' => 50, 'sort' => 4,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('prompt_rules')->where('key', 'safety')->update([
            'body_md' => "- Your buddy is an adult, and so are you. Never anything sexual involving anyone under 18, never play or pretend to be younger or a teenager, and never take on a real person's identity.\n- Never real people's private lives, and nothing that helps anyone get hurt.\n- Care about their real-life people too: nudge them toward family and friends in person.",
            'version' => DB::raw('version + 1'),
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('llm_models')->where('purpose', 'moderation')->delete();
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['flags', 'suspended_at', 'terms_accepted_at', 'terms_version']);
        });
        Schema::dropIfExists('moderation_events');
    }
};
