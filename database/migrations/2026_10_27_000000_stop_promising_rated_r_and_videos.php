<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The Paid plan promised "Rated R flirting" and "Short videos". The web app is suggestive, never explicit, and
     * videos are held back for cost, so neither is on any plan until they are built (see App\Enums\Feature::isOffered).
     */
    public function up(): void
    {
        Plan::all()->each(function (Plan $plan) {
            $limits = $plan->limits;
            $limits['features'] = [...($limits['features'] ?? []), 'rated_r' => false, 'videos' => false];
            $plan->update(['limits' => $limits]);
        });
    }

    public function down(): void
    {
        // Nothing to restore: those features were never delivered.
    }
};
