<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each contact remembers about their user: one set of notes per user_contact, so contacts never share them.
     * Every change keeps a version. `forgotten` lists things the user asked the contact to forget, so they aren't
     * written back from old texts. `last_message_id` is the newest text already reflected in the notes.
     */
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('notes_md')->default('');
            $table->jsonb('forgotten')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamps();
        });

        Schema::create('memory_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('notes_md');
            $table->string('reason', 20); // update | forget | reset
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_versions');
        Schema::dropIfExists('memories');
    }
};
