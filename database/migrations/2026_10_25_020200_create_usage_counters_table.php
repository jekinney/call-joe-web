<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much of each metered thing (texts, voice seconds, images) a user has used in the current period. One row
     * per user, metric and period; App\Services\Entitlements adds to it atomically, never past the plan's limit.
     */
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 40);
            $table->date('period_start');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'metric', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
