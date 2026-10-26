<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paid plans through Braintree.
     *
     * - users.braintree_customer_id: their customer record at Braintree (made at their first checkout).
     * - subscriptions: one per Braintree subscription. `paid_through` is the end of the period Braintree last charged
     *   for; the user's plan runs until then (plus a short grace), and each successful renewal moves it on, so a
     *   missed webhook or a failed card ends in Free, never in free paid service.
     * - payments: every charge Braintree reports (cents), for the admin dashboard and refunds. Unique per transaction,
     *   so a repeated webhook can't count twice.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('braintree_customer_id', 100)->nullable()->unique()->after('plan_expires_at');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('braintree_subscription_id', 100)->unique();
            $table->string('status', 20); // active | past_due | canceled | expired
            $table->timestamp('paid_through')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('braintree_transaction_id', 100)->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('status', 20); // paid | failed
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('braintree_customer_id');
        });
    }
};
