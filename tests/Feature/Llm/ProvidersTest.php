<?php

use App\Enums\LlmProvider;
use App\Exceptions\LlmException;
use App\Services\LlmProviders;
use App\Support\Llm\AnthropicClient;
use App\Support\Llm\ChatMessages;
use App\Support\Llm\GeminiClient;
use App\Support\Llm\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Http;

// Which providers the app can use, their keys, the clients behind them, and how messages are shaped for them.

beforeEach(function () {
    config(['services.anthropic.key' => '', 'services.gemini.key' => '', 'services.xai.key' => '', 'services.groq.key' => '']);
    $this->providers = new LlmProviders; // a fresh one, not the tests' faked one
});

it('names each provider and the setting that holds its key', function () {
    expect(collect(LlmProvider::cases())->map(fn (LlmProvider $p) => [$p->value, $p->label(), $p->shortName(), $p->envKey(), $p->exampleModel()])->all())->toBe([
        ['anthropic', 'Claude (Anthropic)', 'Claude', 'ANTHROPIC_API_KEY', 'claude-haiku-5-5'],
        ['gemini', 'Gemini (Google)', 'Gemini', 'GEMINI_API_KEY', 'gemini-3.5-flash-lite'],
        ['xai', 'Grok (xAI)', 'Grok', 'XAI_API_KEY', 'grok-4.20-0309-non-reasoning'],
        ['groq', 'Groq', 'Groq', 'GROQ_API_KEY', 'llama-3.3-70b-versatile'],
    ]);
});

it('knows which providers have a key', function () {
    config(['services.xai.key' => 'xai-test']);

    expect(LlmProvider::Xai->hasKey())->toBeTrue()->and(LlmProvider::Xai->key())->toBe('xai-test')
        ->and(LlmProvider::Gemini->hasKey())->toBeFalse()
        ->and($this->providers->isConfigured(LlmProvider::Xai))->toBeTrue()
        ->and($this->providers->isConfigured(LlmProvider::Gemini))->toBeFalse();

    $this->providers->fake($this->llm); // tests: one fake answers for every provider
    expect($this->providers->isConfigured(LlmProvider::Gemini))->toBeTrue()->and($this->providers->client(LlmProvider::Gemini))->toBe($this->llm);
});

it('builds the right client for each provider, once', function () {
    config(['services.anthropic.key' => 'a', 'services.gemini.key' => 'g', 'services.xai.key' => 'x', 'services.groq.key' => 'q', 'services.anthropic.base_url' => 'https://claude.test']);

    expect($this->providers->client(LlmProvider::Anthropic))->toBeInstanceOf(AnthropicClient::class)
        ->and($this->providers->client(LlmProvider::Gemini))->toBeInstanceOf(GeminiClient::class)
        ->and($this->providers->client(LlmProvider::Xai))->toBeInstanceOf(OpenAiCompatibleClient::class)
        ->and($this->providers->client(LlmProvider::Groq))->toBeInstanceOf(OpenAiCompatibleClient::class)
        ->and($this->providers->client(LlmProvider::Xai))->toBe($this->providers->client(LlmProvider::Xai));
    expect($this->providers->client(LlmProvider::Xai))->not->toBe($this->providers->client(LlmProvider::Groq));

    config(['services.anthropic.base_url' => '']);
    expect((new LlmProviders)->client(LlmProvider::Anthropic))->toBeInstanceOf(AnthropicClient::class);
});

it('refuses a provider with no key, in a way the gateway can answer with a fallback', function () {
    $error = null;
    try {
        $this->providers->client(LlmProvider::Anthropic);
    } catch (LlmException $e) {
        $error = $e;
    }

    expect($error?->getMessage())->toBe('No Claude API key is set (ANTHROPIC_API_KEY).')->and($error?->retryable)->toBeTrue();
});

it('lists the models a provider offers, for the admin form', function () {
    config(['services.gemini.key' => 'g', 'services.gemini.base_url' => 'https://gemini.test/v1beta', 'services.xai.key' => 'x', 'services.xai.base_url' => 'https://xai.test/v1']);
    Http::fake([
        'gemini.test/*' => Http::response(['models' => [
            ['name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
            ['name' => 'models/gemini-3.5-transcribe', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/gemini-3.5-flash-lite', 'supportedGenerationMethods' => ['generateContent']],
        ]]),
        'xai.test/*' => Http::response(['data' => [['id' => 'grok-4.7'], ['id' => 'grok-4.20-0309-non-reasoning']]]),
    ]);

    expect($this->providers->modelIds(LlmProvider::Gemini))->toBe(['gemini-3.5-flash-lite', 'gemini-3.8-flash'])
        ->and($this->providers->modelIds(LlmProvider::Xai))->toBe(['grok-4.20-0309-non-reasoning', 'grok-4.7'])
        ->and($this->providers->modelIds(LlmProvider::Anthropic))->toContain('claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5')
        ->and($this->providers->modelIds(LlmProvider::Groq))->toBe([]); // no key to ask with
    Http::assertSent(fn ($request) => $request->url() === 'https://gemini.test/v1beta/models?pageSize=300' && $request->hasHeader('x-goog-api-key', 'g'));
    Http::assertSent(fn ($request) => $request->url() === 'https://xai.test/v1/models' && $request->hasHeader('Authorization', 'Bearer x'));

    $this->providers->modelIds(LlmProvider::Gemini);
    Http::assertSentCount(2); // the second look came from memory
});

it('lists no models when the provider can\'t be asked', function () {
    config(['services.xai.key' => 'x', 'services.xai.base_url' => 'https://xai.test/v1']);
    Http::fake(['xai.test/*' => Http::response('Nope', 500)]);

    expect($this->providers->modelIds(LlmProvider::Xai))->toBe([]);
});

// ---------------------------------------------------------------- shaping messages

it('splits messages into a system prompt and alternating turns', function (array $messages, string $system, array $turns) {
    expect(ChatMessages::split($messages))->toBe(['system' => $system, 'turns' => $turns]);
})->with([
    'a normal chat' => [
        [['role' => 'system', 'content' => 'Be Joe.'], ['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hey'], ['role' => 'user', 'content' => 'You there?']],
        'Be Joe.',
        [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hey'], ['role' => 'user', 'content' => 'You there?']],
    ],
    'several leading system messages' => [
        [['role' => 'system', 'content' => 'One.'], ['role' => 'system', 'content' => 'Two.'], ['role' => 'user', 'content' => 'Hi']],
        "One.\n\nTwo.",
        [['role' => 'user', 'content' => 'Hi']],
    ],
    'notes later on join the user\'s turn' => [
        [['role' => 'system', 'content' => 'Be Joe.'], ['role' => 'user', 'content' => 'Hi'], ['role' => 'system', 'content' => '(Be relieved.)'], ['role' => 'system', 'content' => '(Be brief.)']],
        'Be Joe.',
        [['role' => 'user', 'content' => "Hi\n\n(Be relieved.)\n\n(Be brief.)"]],
    ],
    'two texts in a row' => [
        [['role' => 'user', 'content' => 'Hi'], ['role' => 'user', 'content' => 'Are you up?']],
        '',
        [['role' => 'user', 'content' => "Hi\n\nAre you up?"]],
    ],
    'starting with the contact\'s text' => [
        [['role' => 'system', 'content' => 'Be Joe.'], ['role' => 'assistant', 'content' => 'Morning!'], ['role' => 'system', 'content' => '(Text them first.)']],
        'Be Joe.',
        [['role' => 'user', 'content' => '(The conversation so far.)'], ['role' => 'assistant', 'content' => 'Morning!'], ['role' => 'user', 'content' => '(Text them first.)']],
    ],
    'ending with the contact\'s text' => [
        [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hey']],
        '',
        [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hey'], ['role' => 'user', 'content' => '(Go on.)']],
    ],
    'nothing to say' => [
        [['role' => 'system', 'content' => 'Be Joe.'], ['role' => 'user', 'content' => '  ']],
        'Be Joe.',
        [['role' => 'user', 'content' => '(Go ahead.)']],
    ],
]);
