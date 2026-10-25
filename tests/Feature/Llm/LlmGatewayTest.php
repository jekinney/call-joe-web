<?php

use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Contact;
use App\Models\Llm;
use App\Models\LlmCall;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\LlmGateway;

// Choosing the model, logging every call, and falling back when a model fails.

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
    expect(gateway()->modelFor(LlmPurpose::Chat, User::factory()->create())->model_id)->toBe('llama-3.1-8b-instant')
        ->and(gateway()->modelFor(LlmPurpose::Chat, onPaid(User::factory()->create()))->model_id)->toBe('llama-3.3-70b-versatile');
});

it('uses an any-plan model when there is no tier match, and the first model with no user', function () {
    expect(gateway()->modelFor(LlmPurpose::Memory, User::factory()->create())->label)->toBe('Llama 3.3 70B (memory)')
        ->and(gateway()->modelFor(LlmPurpose::Chat)->model_id)->toBe('llama-3.1-8b-instant');
});

it('never uses a model that is turned off', function () {
    Llm::where('plan_tier', 'large')->update(['is_active' => false]);
    gateway()->forgetModels();

    expect(gateway()->modelFor(LlmPurpose::Chat, onPaid(User::factory()->create()))->model_id)->toBe('llama-3.1-8b-instant');
});

it('explains when no model is set up for a purpose', function () {
    gateway()->modelFor(LlmPurpose::Voice);
})->throws(LlmException::class, 'No active model for Voice calls.');

it('picks up model changes once they are cleared from the cache', function () {
    $user = User::factory()->create();
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe('llama-3.1-8b-instant');

    Llm::where('model_id', 'llama-3.1-8b-instant')->update(['model_id' => 'llama-4-scout']);
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe('llama-3.1-8b-instant'); // still cached

    gateway()->forgetModels();
    expect(gateway()->modelFor(LlmPurpose::Chat, $user)->model_id)->toBe('llama-4-scout');
});

it('answers, sends the model\'s answer limit, and logs tokens and cost', function () {
    $this->llm->reply('Hey Jamie!');
    $user = onPaid(User::factory()->create());
    $contact = Contact::factory()->create();

    $reply = gateway()->chat(LlmPurpose::Chat, hi(), $user, $contact);

    expect($reply->text)->toBe('Hey Jamie!')
        ->and($this->llm->calls[0]['model'])->toBe('llama-3.3-70b-versatile')
        ->and($this->llm->calls[0]['options']['max_tokens'])->toBe(1024);

    $log = LlmCall::sole();
    expect($log->only(['user_id', 'contact_id', 'model', 'status']))->toBe([
        'user_id' => $user->id, 'contact_id' => $contact->id, 'model' => 'llama-3.3-70b-versatile', 'status' => 'ok',
    ])
        ->and($log->purpose)->toBe(LlmPurpose::Chat)
        ->and($log->prompt_tokens)->toBe($reply->promptTokens)
        ->and($log->cost_micros)->toBe(Llm::where('plan_tier', 'large')->first()->costMicros($reply->promptTokens, $reply->completionTokens))
        ->and($log->llmModel->plan_tier)->toBe('large');
});

it('lets a caller ask for a shorter answer', function () {
    gateway()->chat(LlmPurpose::Chat, hi(), options: ['max_tokens' => 40]);

    expect($this->llm->calls[0]['options']['max_tokens'])->toBe(40);
});

it('falls back to the backup model when the main one fails, logging both', function () {
    $this->llm->failFor('llama-3.3-70b-versatile')->reply('From the backup');

    $reply = gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create()));

    expect($reply->text)->toBe('From the backup')
        ->and(LlmCall::orderBy('id')->pluck('status')->all())->toBe(['error', 'fallback'])
        ->and(LlmCall::first()->error)->toBe('The fake provider is down.');
});

it('does not fall back for errors trying again won\'t fix', function () {
    $this->llm->failFor('llama-3.3-70b-versatile', retryable: false, message: 'Invalid API Key');

    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create())))->toThrow(LlmException::class, 'Invalid API Key')
        ->and(LlmCall::count())->toBe(1);
});

it('gives up when the backup fails too, or is turned off, or there is none', function () {
    $this->llm->failFor('llama-3.3-70b-versatile')->failFor('llama-3.1-8b-instant');
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create())))->toThrow(LlmException::class);
    expect(LlmCall::where('status', 'error')->count())->toBe(2);

    Llm::where('model_id', 'llama-3.1-8b-instant')->update(['is_active' => false]);
    gateway()->forgetModels();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), onPaid(User::factory()->create())))->toThrow(LlmException::class);

    // Free's small model has no backup at all.
    Llm::where('model_id', 'llama-3.1-8b-instant')->update(['is_active' => true]);
    gateway()->forgetModels();
    expect(fn () => gateway()->chat(LlmPurpose::Chat, hi(), User::factory()->create()))->toThrow(LlmException::class);
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
