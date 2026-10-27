<?php

use App\Enums\LlmProvider;
use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Llm;
use App\Models\LlmCall;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\LlmGateway;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

// /admin/models: manage the AI models without a deploy.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function modelsPage()
{
    return Volt::actingAs(test()->admin)->test('admin.models');
}

function grokRow(): Llm
{
    return Llm::where('purpose', 'chat')->where('model_id', 'grok-4.20-0309-non-reasoning')->firstOrFail();
}

it('keeps regular users out', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.models'))->assertForbidden();
});

it('lists the models by purpose with last week\'s usage', function () {
    $grok = grokRow();
    LlmCall::create(['llm_model_id' => $grok->id, 'model' => $grok->model_id, 'purpose' => 'chat', 'prompt_tokens' => 900, 'completion_tokens' => 100, 'status' => 'ok', 'cost_micros' => 2500]);
    LlmCall::create(['llm_model_id' => $grok->id, 'model' => $grok->model_id, 'purpose' => 'chat', 'status' => 'error']);

    $this->actingAs($this->admin)->get(route('admin.models'))
        ->assertOk()
        ->assertSee('Texting')->assertSee('Memory notes')->assertSee('Picture scenes')->assertSee('Voice calls')
        ->assertSee('Grok 4.20 fast')->assertSee('claude-sonnet-5-5')->assertSee('gemini-3.5-flash-lite')->assertSee('gemini-3.8-flash')
        ->assertSee('If it fails: Claude Haiku 5.5 (fast)')
        ->assertSee('Last 7 days: 2 calls, 1,000 tokens, $0.0025, 1 errors')
        ->assertSee('Assigned')->assertSee('Fast')
        ->assertDontSee('No models yet.');
});

it('shows which providers have a key', function () {
    config(['services.anthropic.key' => 'sk-ant-test', 'services.gemini.key' => '', 'services.xai.key' => 'xai-test', 'services.groq.key' => '']);

    $this->actingAs($this->admin)->get(route('admin.models'))
        ->assertSee('Claude (Anthropic): key set')
        ->assertSee('Gemini (Google): no key (GEMINI_API_KEY)')
        ->assertSee('Grok (xAI): key set')
        ->assertSee('Groq: no key (GROQ_API_KEY)');
});

it('adds a model, and the gateway uses it right away', function () {
    // Take the voice model away: that empty answer is now cached, and saving must clear it.
    Llm::where('purpose', 'voice')->delete();
    app(LlmGateway::class)->forgetModels();
    expect(fn () => app(LlmGateway::class)->modelFor(LlmPurpose::Voice))->toThrow(LlmException::class);

    modelsPage()
        ->call('create')
        ->assertSet('provider', 'anthropic')
        ->set('provider', 'xai')
        ->set('model_id', 'grok-4.20-0309-non-reasoning')
        ->set('label', 'Grok for calls')
        ->set('purpose', 'voice')
        ->set('fallback_id', (string) grokRow()->id)
        ->set('fast_thinking', true)
        ->set('input_cents_per_mtok', '125')
        ->set('output_cents_per_mtok', '250')
        ->set('max_tokens', 256)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('model-saved')
        ->assertSee('Grok for calls');

    $voice = app(LlmGateway::class)->modelFor(LlmPurpose::Voice);
    expect($voice->only(['provider', 'model_id', 'fast_thinking']))->toBe(['provider' => LlmProvider::Xai, 'model_id' => 'grok-4.20-0309-non-reasoning', 'fast_thinking' => true]);
});

it('adds a model for contacts to be given, without any plan picking it', function () {
    modelsPage()->call('create')
        ->set('provider', 'gemini')->set('model_id', 'gemini-9-flash')->set('label', 'Gemini 9 Flash')->set('plan_tier', 'assigned')
        ->set('input_cents_per_mtok', '20')->set('output_cents_per_mtok', '100')
        ->call('save')->assertHasNoErrors();

    expect(Llm::firstWhere('model_id', 'gemini-9-flash')->plan_tier)->toBe(Llm::ASSIGNED)
        ->and(app(LlmGateway::class)->modelFor(LlmPurpose::Chat, User::factory()->create())->model_id)->toBe('grok-4.20-0309-non-reasoning');
});

it('suggests the models a provider offers while adding one', function () {
    config(['services.xai.key' => 'xai-test', 'services.xai.base_url' => 'https://xai.test/v1']);
    Http::fake(['xai.test/v1/models' => Http::response(['data' => [['id' => 'grok-4.7'], ['id' => 'grok-4.20-0309-non-reasoning']]])]);

    modelsPage()->call('create')
        ->assertSee('6 models to pick from')->assertSee('value="claude-sonnet-5-5"', false) // Claude's are built in
        ->set('provider', 'xai')
        ->assertSee('2 models to pick from')->assertSee('value="grok-4.7"', false)
        ->set('provider', 'groq') // no key, so nothing to ask
        ->assertDontSee('models to pick from');
});

it('edits a model, loading its current values', function () {
    $sonnet = Llm::where('purpose', 'chat')->where('model_id', 'claude-sonnet-5-5')->first();

    modelsPage()
        ->call('edit', $sonnet->id)
        ->assertSet('provider', 'anthropic')
        ->assertSet('model_id', 'claude-sonnet-5-5')
        ->assertSet('plan_tier', 'assigned')
        ->assertSet('purpose', 'chat')
        ->assertSet('fast_thinking', true)
        ->set('model_id', 'claude-opus-5-5')
        ->set('fast_thinking', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($sonnet->refresh()->only(['model_id', 'fast_thinking']))->toBe(['model_id' => 'claude-opus-5-5', 'fast_thinking' => false]);
});

it('validates the form, and a model cannot fall back to itself', function () {
    $grok = grokRow();

    modelsPage()
        ->call('edit', $grok->id)
        ->set('provider', 'openai')
        ->set('model_id', '')
        ->set('purpose', 'dreams')
        ->set('plan_tier', 'huge')
        ->set('fallback_id', (string) $grok->id)
        ->set('input_cents_per_mtok', '-1')
        ->set('max_tokens', 5)
        ->call('save')
        ->assertHasErrors(['provider', 'model_id', 'purpose', 'plan_tier', 'fallback_id', 'input_cents_per_mtok', 'max_tokens']);
});

it('turns a model off and on, and the gateway follows', function () {
    $paid = User::factory()->create();
    app(Entitlements::class)->assignPlan($paid, Plan::where('slug', 'paid')->first(), null);

    modelsPage()->call('toggle', grokRow()->id)->assertSee('Turn on');
    expect(app(LlmGateway::class)->modelFor(LlmPurpose::Chat, $paid)->model_id)->toBe('claude-haiku-5-5');

    modelsPage()->call('toggle', grokRow()->id);
    expect(app(LlmGateway::class)->modelFor(LlmPurpose::Chat, $paid)->model_id)->toBe('grok-4.20-0309-non-reasoning');
});

it('tests a model on its own and shows its answer, or the error', function () {
    $haiku = Llm::where('purpose', 'chat')->where('model_id', 'claude-haiku-5-5')->first();
    $this->llm->reply('Hello there, friend!');

    modelsPage()->call('test', $haiku->id)->assertSee('“Hello there, friend!” from claude-haiku-5-5');

    // Even though it has a backup, the test shows the problem instead of covering it up.
    $this->llm->failFor('claude-haiku-5-5', retryable: true, message: 'Invalid API Key');
    modelsPage()->call('test', $haiku->id)->assertSee('Invalid API Key')->assertDontSee('Hello there');
});

it('does not text a live voice model, which only streams', function () {
    $live = Llm::where('purpose', 'voice')->first();

    modelsPage()->call('test', $live->id)->assertSee('Live voice models can\'t be tested with a text');

    expect(LlmCall::count())->toBe(0);
});

it('is linked from the admin sidebar', function () {
    $this->actingAs($this->admin)->get('/admin')->assertSee(route('admin.models'));
});
