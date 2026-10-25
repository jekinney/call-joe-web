<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The contacts on a user's phone. Removing one only archives the row (deleted_at): everything they shared with
     * that contact (texts, calls, memory, in later phases) hangs off this row and comes back if they add them again.
     */
    public function up(): void
    {
        Schema::create('user_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('nickname', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contacts');
    }
};
