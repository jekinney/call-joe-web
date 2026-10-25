<?php

use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Llm;
use App\Models\LlmCall;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\LlmGateway;
use Livewire\Volt\Volt;

// /admin/models: manage the AI models without a deploy.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function modelsPage()
{
    return Volt::actingAs(test()->admin)->test('admin.models');
}

it('keeps regular users out', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.models'))->assertForbidden();
});

it('lists the models by purpose with last week\'s usage', function () {
    $large = Llm::where('plan_tier', 'large')->first();
    LlmCall::create(['llm_model_id' => $large->id, 'model' => $large->model_id, 'purpose' => 'chat', 'prompt_tokens' => 900, 'completion_tokens' => 100, 'status' => 'ok', 'cost_micros' => 2500]);
    LlmCall::create(['llm_model_id' => $large->id, 'model' => $large->model_id, 'purpose' => 'chat', 'status' => 'error']);

    $this->actingAs($this->admin)->get(route('admin.models'))
        ->assertOk()
        ->assertSee('Texting')->assertSee('Memory notes')
        ->assertSee('Llama 3.3 70B')->assertSee('llama-3.1-8b-instant')
        ->assertSee('If it fails: Llama 3.1 8B (fast)')
        ->assertSee('Last 7 days: 2 calls, 1,000 tokens, $0.0025, 1 errors')
        ->assertSee('No models yet.'); // Safety checks and Voice calls have none
});

it('warns when no Groq key is set', function () {
    config(['services.groq.key' => '']);
    $this->actingAs($this->admin)->get(route('admin.models'))->assertSee('No Groq API key is set');

    config(['services.groq.key' => 'gsk_test']);
    $this->actingAs($this->admin)->get(route('admin.models'))->assertDontSee('No Groq API key is set');
});

it('adds a model, and the gateway uses it right away', function () {
    $small = Llm::where('model_id', 'llama-3.1-8b-instant')->first();
    // No safety-check model yet: that empty answer is now cached, and saving must clear it.
    expect(fn () => app(LlmGateway::class)->modelFor(LlmPurpose::Moderation))->toThrow(LlmException::class);

    modelsPage()
        ->call('create')
        ->set('model_id', 'llama-guard-4')
        ->set('label', 'Llama Guard')
        ->set('purpose', 'moderation')
        ->set('fallback_id', (string) $small->id)
        ->set('input_cents_per_mtok', '20')
        ->set('output_cents_per_mtok', '20')
        ->set('max_tokens', 256)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('model-saved')
        ->assertSee('Llama Guard');

    expect(app(LlmGateway::class)->modelFor(LlmPurpose::Moderation)->model_id)->toBe('llama-guard-4');
});

it('edits a model, loading its current values', function () {
    $large = Llm::where('plan_tier', 'large')->first();

    modelsPage()
        ->call('edit', $large->id)
        ->assertSet('model_id', 'llama-3.3-70b-versatile')
        ->assertSet('plan_tier', 'large')
        ->assertSet('purpose', 'chat')
        ->set('model_id', 'llama-4-maverick')
        ->call('save')
        ->assertHasNoErrors();

    expect($large->refresh()->model_id)->toBe('llama-4-maverick');
});

it('validates the form, and a model cannot fall back to itself', function () {
    $large = Llm::where('plan_tier', 'large')->first();

    modelsPage()
        ->call('edit', $large->id)
        ->set('model_id', '')
        ->set('purpose', 'dreams')
        ->set('plan_tier', 'huge')
        ->set('fallback_id', (string) $large->id)
        ->set('input_cents_per_mtok', '-1')
        ->set('max_tokens', 5)
        ->call('save')
        ->assertHasErrors(['model_id', 'purpose', 'plan_tier', 'fallback_id', 'input_cents_per_mtok', 'max_tokens']);
});

it('turns a model off and on, and the gateway follows', function () {
    $large = Llm::where('plan_tier', 'large')->first();
    $paid = User::factory()->create();
    app(Entitlements::class)->assignPlan($paid, Plan::where('slug', 'paid')->first(), null);

    modelsPage()->call('toggle', $large->id)->assertSee('Turn on');
    expect(app(LlmGateway::class)->modelFor(LlmPurpose::Chat, $paid)->model_id)->toBe('llama-3.1-8b-instant');

    modelsPage()->call('toggle', $large->id);
    expect(app(LlmGateway::class)->modelFor(LlmPurpose::Chat, $paid)->model_id)->toBe('llama-3.3-70b-versatile');
});

it('tests a model and shows its answer, or the error', function () {
    $small = Llm::where('model_id', 'llama-3.1-8b-instant')->first();
    $this->llm->reply('Hello there, friend!');

    modelsPage()->call('test', $small->id)->assertSee('“Hello there, friend!” from llama-3.1-8b-instant');

    $this->llm->failFor('llama-3.1-8b-instant', retryable: false, message: 'Invalid API Key');
    modelsPage()->call('test', $small->id)->assertSee('Invalid API Key');
});

it('is linked from the admin sidebar', function () {
    $this->actingAs($this->admin)->get('/admin')->assertSee(route('admin.models'));
});
