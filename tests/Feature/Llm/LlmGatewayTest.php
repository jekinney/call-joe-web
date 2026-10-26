<?php

use App\Enums\LlmProvider;
use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Contact;
use App\Models\Llm;
use App\Models\LlmCall;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\LlmGateway;
use App\Services\LlmProviders;
use Illuminate\Support\Facades\Http;

// Choosing the model, sending it to the right provider, logging every call, and falling back when a model fails.

function gateway(): LlmGateway
{
    return app(LlmGateway::class);
}

function hi(): array
{
    return [['role' => 'user', 'content' => 'Hi']];
}

function onPaid(User $user): User
{
    app(Entitlements::class)->assignPlan($user, Plan::where('slug', 'paid')->firstOrFail(), null);

    return $user;
}

it('uses the small model on Free and the large one on Paid', function () {
    expect(gateway()->modelFor(LlmPurpose::Chat, User::factory()->create())->model_id)->toBe('claude-haiku-5-5')
        ->and(gateway()->modelFor(LlmPurpose::Chat, onPaid(User::factory()->create()))->model_id)->toBe('claude-sonnet-5-5');
});

it('starts out with Claude for texting, memory and safety, and Gemini for pictures and calls', function () {
    $chosen = fn (LlmPurpose $purpose) => gateway()->modelFor($purpose, User::factory()->create());

    expect($chosen(LlmPurpose::Chat)->provider)->toBe(LlmProvider::Anthropic)
        ->and($chosen(LlmPurpose::Memory)->provider)->toBe(LlmProvider::Anthropic)
        ->and($chosen(LlmPurpose::Moderation)->provider)->toBe(LlmProvider::Anthropic)
        ->and($chosen(LlmPurpose::Scenes)->provider)->toBe(LlmProvider::Gemini)
        ->and($chosen(LlmPurpose::Voice)->only(['provider', 'model_id']))->toBe(['provider' => LlmProvider::Gemini, 'model_id' => 'gemini-3.5-flash-lite']);
});

it('uses an any-plan model when there is no tier match, and the first model with no user', function () {
    expect(gateway()->modelFor(LlmPurpose::Memory, User::factory()->create())->label)->toBe('Claude Sonnet 5.5 (memory)')
        ->and(gateway()->modelFor(LlmPurpose::Chat)->model_id)->toBe('claude-haiku-5-5');
});

it('never uses a model that is turned off', function () {
    Llm::where('plan_tier', 'large')->update(['is_active' => false]);
    gateway()->forgetModels();

    expect(gateway()->modelFor(LlmPurpose::Chat, onPaid(User::factory()->create()))->model_id)->toBe('claude-haiku-5-5');
});

it('keeps backup models for when something fails, never choosing one on its own', function () {
    Llm::whereIn('plan_tier', ['small', 'large'])->update(['is_active' => false]);
    gateway()->forgetModels();

    // With the real models off, a backup is the only chat model left, so it answers rather than nothing.
    expect(gateway()->modelFor(LlmPurpose::Chat, User::factory()->create())->plan_tier)->toBe('backup');

    Llm::whereIn('plan_tier', ['small', 'large'])->update(['is_active' => true]);
    gateway()->forgetModels();
    expect(gateway()->modelFor(LlmPurpose::Chat, User::factory()->create())->plan_tier)->toBe('small')
        ->and(Llm::where('plan_tier', 'backup')->pluck('model_id')->all())->toBe(['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'grok-4.20-0309-non-reasoning']);
});

it('explains when no model is set up for a purpose', function () {
    Llm::where('purpose', 'voice')->delete();
    gateway()->forgetModels();

    gateway()->modelFor(LlmPurpose::Voice);
})->throws(LlmException::class, 'No active model for Voice calls.');

it('picks up model changes once they are cleared from the cache', function () {
    $user = User::factory()->create();
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe('claude-haiku-5-5');

    Llm::where('plan_tier', 'small')->update(['model_id' => 'claude-haiku-4-5-20251001']);
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe('claude-haiku-5-5'); // still cached

    gateway()->forgetModels();
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe('claude-haiku-4-5-20251001');
});

it('answers, sends the model\'s answer limit and speed setting, and logs tokens and cost', function () {
    $this->llm->reply('Hey Jamie!');
    $user = onPaid(User::factory()->create());
    $contact = Contact::factory()->create();

    $reply = gateway()->chat(LlmPurpose::Chat, hi(), $user, $contact);

    expect($reply->text)->toBe('Hey Jamie!')
        ->and($this->llm->calls[0]['model'])->toBe('claude-sonnet-5-5')
        ->and($this->llm->calls[0]['options'])->toBe(['max_tokens' => 1024, 'fast' => true]);

    $log = LlmCall::sole();
    expect($log->only(['user_id', 'contact_id', 'model', 'status']))->toBe([
        'user_id' => $user->id, 'contact_id' => $contact->id, 'model' => 'claude-sonnet-5-5', 'status' => 'ok',
    ])
        ->and($log->purpose)->toBe(LlmPurpose::Chat)
        ->and($log->prompt_tokens)->toBe($reply->promptTokens)
        ->and($log->cost_micros)->toBe(Llm::where('plan_tier', 'large')->first()->costMicros($reply->promptTokens, $reply->completionTokens))
        ->and($log->llmModel->plan_tier)->toBe('large');
});

it('asks for fast answers only from models set to answer fast', function () {
    gateway()->chat(LlmPurpose::Memory, hi());      // careful: not fast
    gateway()->chat(LlmPurpose::Moderation, hi());  // fast
    gateway()->chat(LlmPurpose::Voice, hi());       // Gemini 3.5 Flash-Lite is already quick, and refuses a thinking budget

    expect($this->llm->calls[0]['options'])->toBe(['max_tokens' => 4096])
        ->and($this->llm->calls[1]['options'])->toBe(['max_tokens' => 1024, 'fast' => true])
        ->and($this->llm->calls[2]['options'])->toBe(['max_tokens' => 512]);
});

it('lets a caller ask for a shorter answer', function () {
    gateway()->chat(LlmPurpose::Chat, hi(), options: ['max_tokens' => 40]);

    expect($this->llm->calls[0]['options']['max_tokens'])->toBe(40);
});

it('falls back to the backup model when the main one fails, logging both', function () {
    $this->llm->failFor('claude-sonnet-5-5')->reply('From the backup');

    $reply = gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create()));

    expect($reply->text)->toBe('From the backup')
        ->and($this->llm->calls[1]['model'])->toBe('gemini-3.8-flash')
        ->and(LlmCall::orderBy('id')->pluck('status')->all())->toBe(['error', 'fallback'])
        ->and(LlmCall::first()->error)->toBe('The fake provider is down.');
});

it('does not fall back for errors trying again won\'t fix', function () {
    $this->llm->failFor('claude-sonnet-5-5', retryable: false, message: 'Invalid API Key');

    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create())))->toThrow(LlmException::class, 'Invalid API Key')
        ->and(LlmCall::count())->toBe(1);
});

it('gives up when the backup fails too, or is turned off, or there is none', function () {
    $this->llm->failAll();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create())))->toThrow(LlmException::class);
    expect(LlmCall::where('status', 'error')->count())->toBe(2);

    Llm::where('model_id', 'gemini-3.8-flash')->update(['is_active' => false]);
    gateway()->forgetModels();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create())))->toThrow(LlmException::class);

    Llm::where('plan_tier', 'small')->update(['fallback_id' => null]);
    gateway()->forgetModels();
    LlmCall::query()->delete();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), User::factory()->create()))->toThrow(LlmException::class)
        ->and(LlmCall::count())->toBe(1);
});

it('moves on to the fallback on another provider when a provider has no key', function () {
    app(LlmProviders::class)->fake(null); // the real clients, with whatever keys .env gives them
    config(['services.anthropic.key' => '', 'services.gemini.key' => 'g-key', 'services.gemini.base_url' => 'https://gemini.test/v1beta']);
    Http::fake(['gemini.test/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Hello from Gemini']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4]])]);

    $reply = gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create()));

    expect($reply->text)->toBe('Hello from Gemini')
        ->and(LlmCall::orderBy('id')->get()->map->only(['model', 'status', 'error'])->all())->toBe([
            ['model' => 'claude-sonnet-5-5', 'status' => 'error', 'error' => 'No Claude API key is set (ANTHROPIC_API_KEY).'],
            ['model' => 'gemini-3.8-flash', 'status' => 'fallback', 'error' => null],
        ]);
});

it('says so when no provider has a key', function () {
    app(LlmProviders::class)->fake(null);
    config(['services.anthropic.key' => '', 'services.gemini.key' => '', 'services.xai.key' => '', 'services.groq.key' => '']);

    expect(fn () => gateway()->chat(LlmPurpose::Voice, hi()))->toThrow(LlmException::class, 'No Grok API key is set (XAI_API_KEY).');
});

it('works out cost in millionths of a dollar', function () {
    $model = new Llm(['input_cents_per_mtok' => 59, 'output_cents_per_mtok' => 79]);

    expect($model->costMicros(1_000_000, 0))->toBe(590_000)       // $0.59
        ->and($model->costMicros(0, 1_000_000))->toBe(790_000)
        ->and($model->costMicros(1200, 300))->toBe(945);          // about $0.0009
});

it('labels every purpose', function () {
    expect(collect(LlmPurpose::cases())->map->label()->all())->toBe(['Texting', 'Memory notes', 'Safety checks', 'Picture scenes', 'Voice calls']);
});

it('remembers every fake call', function () {
    $this->llm->reply('one');
    $first = $this->llm->chat('m', hi());
    $second = $this->llm->chat('m', hi());

    expect($first->text)->toBe('one')->and($second->text)->toBe('Fake reply 2')->and($this->llm->calls)->toHaveCount(2);
});
