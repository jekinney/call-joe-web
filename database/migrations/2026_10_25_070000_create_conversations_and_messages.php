<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Texting. A thread is everything between a user and one contact (user_contact_id). A conversation is one stretch
     * of it: it keeps the prompt it started with (so the AI provider's cache keeps hitting), and a new one starts
     * after a long quiet spell (App\Services\Texting::CONVERSATION_GAP_HOURS).
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_contact_id')->constrained()->cascadeOnDelete();
            $table->text('prompt_text');
            $table->char('prompt_hash', 64);
            $table->timestamp('started_at');
            $table->timestamp('last_message_at')->index();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender', 10); // user | contact
            $table->text('body');
            $table->jsonb('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_contact_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
