<?php

use App\Models\LlmCall;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminStats;
use Livewire\Volt\Volt;

// /admin: the overview of users, money and AI cost.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('keeps regular users out', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.home'))->assertForbidden();
});

it('shows an empty service without errors', function () {
    $this->actingAs($this->admin)->get(route('admin.home'))
        ->assertOk()->assertSee('Monthly recurring')->assertSee('No charges yet.');
});

it('counts people, money and AI cost', function () {
    $plan = Plan::factory()->create(['price_cents' => 1499]);
    $payer = User::factory()->create(['name' => 'Pat Payer']);
    $sub = Subscription::create(['user_id' => $payer->id, 'plan_id' => $plan->id, 'braintree_subscription_id' => 's1', 'status' => 'active', 'paid_through' => now()->addMonth()]);
    Payment::create(['user_id' => $payer->id, 'subscription_id' => $sub->id, 'braintree_transaction_id' => 't1', 'amount_cents' => 1499, 'status' => 'paid', 'occurred_at' => now()->subDay()]);
    Payment::create(['user_id' => $payer->id, 'braintree_transaction_id' => 't0', 'amount_cents' => 1499, 'status' => 'paid', 'occurred_at' => now()->subDays(60)]);
    Payment::create(['user_id' => $payer->id, 'braintree_transaction_id' => 't2', 'amount_cents' => 1499, 'status' => 'failed', 'occurred_at' => now()->subDay()]);
    LlmCall::create(['model' => 'm', 'purpose' => 'chat', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'latency_ms' => 1, 'status' => 'ok', 'cost_micros' => 500_000]);
    LlmCall::create(['model' => 'm', 'purpose' => 'memory', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'latency_ms' => 1, 'status' => 'error', 'cost_micros' => 0]);

    $stats = app(AdminStats::class)->overview(30);

    expect($stats['users']['paying'])->toBe(1)
        ->and($stats['revenue'])->toMatchArray(['mrr_cents' => 1499, 'paid_cents' => 1499, 'failed' => 1])
        ->and($stats['ai'])->toMatchArray(['cost_micros' => 500_000, 'calls' => 2, 'errors' => 1])
        ->and($stats['ai']['by_purpose'])->toBe(['chat' => 500_000, 'memory' => 0])
        ->and($stats['margin_cents'])->toBe(1499 - 50);

    $this->actingAs($this->admin)->get(route('admin.home'))->assertOk()->assertSee('Pat Payer')->assertSee('$14.99');
});

it('changes the period and ignores odd ones', function () {
    $this->actingAs($this->admin);

    Volt::test('admin.home')->set('days', 7)->assertSee('Last 7 days')->set('days', 5)->assertOk();
});
