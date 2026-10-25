<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voice calls (in the app, through Vapi). Each call gets its own short-lived Vapi assistant built with the
     * contact's prompt and memory at the moment the call starts, so the prompt stays on the server and stays the same
     * for the whole call. Vapi's end-of-call report fills in the length, the transcript and the reason it ended.
     */
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_contact_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10)->default('outgoing'); // outgoing: the user called; incoming: the contact did
            $table->string('status', 20)->default('starting');   // starting | active | ended | failed
            $table->string('vapi_assistant_id', 100)->nullable()->unique();
            $table->string('vapi_call_id', 100)->nullable()->unique();
            $table->char('prompt_hash', 64);
            $table->unsignedInteger('max_seconds');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('ended_reason', 100)->nullable();
            $table->text('transcript')->nullable();
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->timestamps();

            $table->index(['user_contact_id', 'id']);
        });

        Schema::table('memories', function (Blueprint $table) {
            $table->unsignedBigInteger('last_call_id')->nullable()->after('last_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn('last_call_id');
        });
        Schema::dropIfExists('calls');
    }
};
