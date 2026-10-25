<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared picture cache (App\Services\PictureCache). When a contact sends the same scene again (to anyone),
     * an existing picture is reused instead of making a new one. A key holds a small pool of variants, rotated, so
     * different people don't always get the identical picture.
     *
     * The key covers the contact, their identity version and their looks, plus the scene's signature: changing a
     * contact's persona or looks starts their cache over. Scenes involving the user ("us at the lake") are never
     * shared.
     */
    public function up(): void
    {
        Schema::create('image_cache', function (Blueprint $table) {
            $table->id();
            $table->char('cache_key', 64);
            $table->unsignedTinyInteger('variant');
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('contact_version');
            $table->text('scene_signature');
            $table->foreignId('image_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['cache_key', 'variant']);
            $table->index('contact_id');
            $table->index('last_hit_at');
        });

        Schema::table('image_requests', function (Blueprint $table) {
            $table->text('scene_signature')->nullable()->after('cache_key');
            $table->boolean('from_cache')->default(false)->after('status');
        });

        $now = now();
        $small = DB::table('llm_models')->where('model_id', 'llama-3.1-8b-instant')->where('purpose', 'chat')->value('id');
        DB::table('llm_models')->insert([
            'model_id' => 'llama-3.1-8b-instant', 'label' => 'Llama 3.1 8B (picture scenes)', 'purpose' => 'scenes', 'plan_tier' => null,
            'fallback_id' => $small, 'input_cents_per_mtok' => 5, 'output_cents_per_mtok' => 8, 'max_tokens' => 120, 'sort' => 5,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('llm_models')->where('purpose', 'scenes')->delete();
        Schema::table('image_requests', function (Blueprint $table) {
            $table->dropColumn(['scene_signature', 'from_cache']);
        });
        Schema::dropIfExists('image_cache');
    }
};
