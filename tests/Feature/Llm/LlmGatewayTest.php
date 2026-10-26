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

// Choosing the model (a contact's own brain for texts), sending it to the right provider, logging every call, and
// falling back when a model fails.

const GROK = 'grok-4.20-0309-non-reasoning';

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

function brain(string $modelId): Llm
{
    return Llm::where('purpose', 'chat')->where('model_id', $modelId)->firstOrFail();
}

it('texts on Grok by default, whatever the plan', function () {
    expect(gateway()->modelFor(LlmPurpose::Chat, User::factory()->create())->model_id)->toBe(GROK)
        ->and(gateway()->modelFor(LlmPurpose::Chat, onPaid(User::factory()->create()))->model_id)->toBe(GROK)
        ->and(gateway()->modelFor(LlmPurpose::Chat)->model_id)->toBe(GROK);
});

it('starts out with Grok for texting, Claude for the careful jobs, and Gemini for calls', function () {
    $chosen = fn (LlmPurpose $purpose) => gateway()->modelFor($purpose, User::factory()->create());

    expect($chosen(LlmPurpose::Chat)->provider)->toBe(LlmProvider::Xai)
        ->and($chosen(LlmPurpose::Memory)->only(['provider', 'model_id']))->toBe(['provider' => LlmProvider::Anthropic, 'model_id' => 'claude-sonnet-5-5'])
        ->and($chosen(LlmPurpose::Moderation)->only(['provider', 'model_id']))->toBe(['provider' => LlmProvider::Anthropic, 'model_id' => 'claude-haiku-5-5'])
        ->and($chosen(LlmPurpose::Scenes)->provider)->toBe(LlmProvider::Anthropic)
        ->and($chosen(LlmPurpose::Voice)->only(['provider', 'model_id']))->toBe(['provider' => LlmProvider::Gemini, 'model_id' => 'gemini-3.5-flash-lite']);
});

it('uses a contact\'s own brain for their texts', function () {
    $joe = Contact::factory()->create(['brain_id' => brain('gemini-3.8-flash')->id]);
    $maya = Contact::factory()->create(['brain_id' => brain('claude-sonnet-5-5')->id]);
    $user = User::factory()->create();

    expect(gateway()->modelFor(LlmPurpose::Chat, $user, $joe)->model_id)->toBe('gemini-3.8-flash')
        ->and(gateway()->modelFor(LlmPurpose::Chat, onPaid(User::factory()->create()), $maya)->model_id)->toBe('claude-sonnet-5-5'); // the plan doesn't matter

    gateway()->chat(LlmPurpose::Chat, hi(), $user, $joe);
    expect($this->llm->calls[0]['model'])->toBe('gemini-3.8-flash')->and($this->llm->calls[0]['options'])->toBe(['max_tokens' => 1024, 'fast' => true]);
});

it('uses a contact\'s brain for texts only, not for memory, safety checks or pictures', function () {
    $joe = Contact::factory()->create(['brain_id' => brain('gemini-3.8-flash')->id]);

    expect(gateway()->modelFor(LlmPurpose::Memory, null, $joe)->model_id)->toBe('claude-sonnet-5-5')
        ->and(gateway()->modelFor(LlmPurpose::Moderation, null, $joe)->model_id)->toBe('claude-haiku-5-5')
        ->and(gateway()->modelFor(LlmPurpose::Scenes, null, $joe)->model_id)->toBe('claude-haiku-5-5')
        ->and(gateway()->modelFor(LlmPurpose::Voice, null, $joe)->model_id)->toBe('gemini-3.5-flash-lite');
});

it('falls back to the default model for a contact whose brain is missing or turned off', function () {
    $joe = Contact::factory()->withoutBrain()->create();
    expect(gateway()->modelFor(LlmPurpose::Chat, null, $joe)->model_id)->toBe(GROK);

    $maya = Contact::factory()->create(['brain_id' => brain('gemini-3.8-flash')->id]);
    brain('gemini-3.8-flash')->update(['is_active' => false]);
    expect(gateway()->modelFor(LlmPurpose::Chat, null, $maya->refresh())->model_id)->toBe(GROK);
});

it('uses an any-plan model when there is no tier match, and the first model with no user', function () {
    expect(gateway()->modelFor(LlmPurpose::Memory, User::factory()->create())->label)->toBe('Claude Sonnet 5.5 (memory)');
});

it('never uses a model that is turned off', function () {
    brain(GROK)->update(['is_active' => false]);
    gateway()->forgetModels();

    // Only assigned models are left for texting, so the first of them answers rather than nothing.
    expect(gateway()->modelFor(LlmPurpose::Chat, onPaid(User::factory()->create()))->model_id)->toBe('claude-haiku-5-5');
});

it('keeps assigned models for contacts and fallbacks, never choosing them for a plan', function () {
    $assigned = Llm::where('plan_tier', Llm::ASSIGNED)->orderBy('sort')->pluck('model_id')->all();

    expect($assigned)->toBe(['claude-haiku-5-5', 'claude-sonnet-5-5', 'gemini-3.5-flash-lite', 'gemini-3.8-flash']);
    foreach ([null, User::factory()->create(), onPaid(User::factory()->create())] as $user) {
        expect(gateway()->modelFor(LlmPurpose::Chat, $user)->plan_tier)->toBeNull();
    }
});

it('explains when no model is set up for a purpose', function () {
    Llm::where('purpose', 'voice')->delete();
    gateway()->forgetModels();

    gateway()->modelFor(LlmPurpose::Voice);
})->throws(LlmException::class, 'No active model for Voice calls.');

it('picks up model changes once they are cleared from the cache', function () {
    $user = User::factory()->create();
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe(GROK);

    brain(GROK)->update(['model_id' => 'grok-5']);
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe(GROK); // still cached

    gateway()->forgetModels();
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe('grok-5');
});

it('answers, sends the model\'s answer limit, and logs tokens and cost', function () {
    $this->llm->reply('Hey Jamie!');
    $user = User::factory()->create();
    $contact = Contact::factory()->withoutBrain()->create();

    $reply = gateway()->chat(LlmPurpose::Chat, hi(), $user, $contact);

    expect($reply->text)->toBe('Hey Jamie!')
        ->and($this->llm->calls[0]['model'])->toBe(GROK)
        ->and($this->llm->calls[0]['options'])->toBe(['max_tokens' => 1024]);

    $log = LlmCall::sole();
    expect($log->only(['user_id', 'contact_id', 'model', 'status']))->toBe([
        'user_id' => $user->id, 'contact_id' => $contact->id, 'model' => GROK, 'status' => 'ok',
    ])
        ->and($log->purpose)->toBe(LlmPurpose::Chat)
        ->and($log->prompt_tokens)->toBe($reply->promptTokens)
        ->and($log->cost_micros)->toBe(brain(GROK)->costMicros($reply->promptTokens, $reply->completionTokens))
        ->and($log->llmModel->provider)->toBe(LlmProvider::Xai);
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
    $this->llm->failFor(GROK)->reply('From the backup');

    $reply = gateway()->chat(LlmPurpose::Chat, hi(), User::factory()->create());

    expect($reply->text)->toBe('From the backup')
        ->and($this->llm->calls[1]['model'])->toBe('claude-haiku-5-5')
        ->and(LlmCall::orderBy('id')->pluck('status')->all())->toBe(['error', 'fallback'])
        ->and(LlmCall::first()->error)->toBe('The fake provider is down.');
});

it('does not fall back for errors trying again won\'t fix', function () {
    $this->llm->failFor(GROK, retryable: false, message: 'Invalid API Key');

    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), User::factory()->create()))->toThrow(LlmException::class, 'Invalid API Key')
        ->and(LlmCall::count())->toBe(1);
});

it('gives up when the backup fails too, or is turned off, or there is none', function () {
    $this->llm->failAll();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), User::factory()->create()))->toThrow(LlmException::class);
    expect(LlmCall::where('status', 'error')->count())->toBe(2);

    Llm::where('model_id', 'claude-haiku-5-5')->update(['is_active' => false]);
    gateway()->forgetModels();
    LlmCall::query()->delete();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), User::factory()->create()))->toThrow(LlmException::class)->and(LlmCall::count())->toBe(1);

    brain(GROK)->update(['fallback_id' => null]);
    gateway()->forgetModels();
    LlmCall::query()->delete();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), User::factory()->create()))->toThrow(LlmException::class)->and(LlmCall::count())->toBe(1);
});

it('moves on to the fallback on another provider when a provider has no key', function () {
    app(LlmProviders::class)->fake(null); // the real clients, with whatever keys .env gives them
    config(['services.gemini.key' => '', 'services.xai.key' => 'x-key', 'services.xai.base_url' => 'https://xai.test/v1']);
    Http::fake(['xai.test/*' => Http::response(['choices' => [['message' => ['content' => 'Hello from Grok']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]])]);

    $reply = gateway()->chat(LlmPurpose::Voice, hi()); // calls: Gemini first, then Grok

    expect($reply->text)->toBe('Hello from Grok')
        ->and(LlmCall::orderBy('id')->get()->map->only(['model', 'status', 'error'])->all())->toBe([
            ['model' => 'gemini-3.5-flash-lite', 'status' => 'error', 'error' => 'No Gemini API key is set (GEMINI_API_KEY).'],
            ['model' => GROK, 'status' => 'fallback', 'error' => null],
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
