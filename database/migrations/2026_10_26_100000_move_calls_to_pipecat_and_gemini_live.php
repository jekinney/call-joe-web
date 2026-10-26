<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calls move from Vapi to our own Pipecat voice service, speaking with Gemini Live (one key, no cloned voices).
     *
     * - calls.vapi_assistant_id becomes voice_session_id (the voice service's id for the call); vapi_call_id goes.
     * - calls.llm_model_id: the live model the call used, so its usage is priced at that model's rates.
     * - A contact's voice is now just the name of one of Gemini's built-in voices (contacts.voice); the provider setting goes.
     *   Voices that aren't a Gemini voice are cleared, so those contacts need a voice picked before they can take calls.
     * - The "Voice calls" model is a Gemini Live model. Its price is an estimate (audio tokens): check it.
     */
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->renameColumn('vapi_assistant_id', 'voice_session_id');
            $table->dropColumn('vapi_call_id');
            $table->foreignId('llm_model_id')->nullable()->after('voice_session_id')->constrained('llm_models')->nullOnDelete();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->renameColumn('voice_provider_id', 'voice');
            $table->dropColumn('voice_settings');
        });
        DB::table('contacts')->whereNotIn('voice', config('contacts.voices'))->update(['voice' => null]);

        DB::table('llm_models')->where('purpose', 'voice')->update([
            'provider' => 'gemini', 'model_id' => 'gemini-3.8-live', 'label' => 'Gemini 3.8 Live (calls)', 'fast_thinking' => false,
            'input_cents_per_mtok' => 300, 'output_cents_per_mtok' => 1200, 'fallback_id' => null, 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->jsonb('voice_settings')->nullable();
            $table->renameColumn('voice', 'voice_provider_id');
        });
        Schema::table('calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('llm_model_id');
            $table->string('vapi_call_id', 100)->nullable()->unique();
            $table->renameColumn('voice_session_id', 'vapi_assistant_id');
        });
    }
};
