<?php

use App\Contracts\LlmClient;
use App\Exceptions\LlmException;
use App\Providers\AppServiceProvider;
use App\Support\Llm\GroqClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// The Groq wire: request shape, answers, retries and errors. No real network.

const GROQ = 'https://api.groq.test/openai/v1/chat/completions';

function groq(): GroqClient
{
    return new GroqClient('test-key', 'https://api.groq.test/openai/v1', 5);
}

function groqAnswer(string $text = 'Hey there!'): array
{
    return [
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['role' => 'assistant', 'content' => "  {$text}  "]]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 8],
    ];
}

it('sends the model, messages and limits, and reads the answer', function () {
    Http::fake([GROQ => Http::response(groqAnswer())]);

    $reply = groq()->chat('llama-3.3-70b-versatile', [['role' => 'user', 'content' => 'Hi']], ['max_tokens' => 50, 'temperature' => 0.5]);

    expect($reply->text)->toBe('Hey there!')
        ->and($reply->promptTokens)->toBe(120)
        ->and($reply->completionTokens)->toBe(8)
        ->and($reply->model)->toBe('llama-3.3-70b-versatile');

    Http::assertSent(fn ($request) => $request->url() === GROQ
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['model'] === 'llama-3.3-70b-versatile'
        && $request['max_tokens'] === 50
        && $request['temperature'] === 0.5
        && $request['messages'] === [['role' => 'user', 'content' => 'Hi']]);
});

it('retries a rate limit, then succeeds', function () {
    Http::fake([GROQ => Http::sequence()->push(['error' => ['message' => 'slow down']], 429)->push(groqAnswer('Second try'))]);

    expect(groq()->chat('m', [['role' => 'user', 'content' => 'Hi']])->text)->toBe('Second try');
    Http::assertSentCount(2);
});

it('gives up after repeated server errors, as retryable', function () {
    Http::fake([GROQ => Http::response(['error' => ['message' => 'overloaded']], 503)]);

    try {
        groq()->chat('m', [['role' => 'user', 'content' => 'Hi']]);
        $this->fail('Expected an exception');
    } catch (LlmException $e) {
        expect($e->retryable)->toBeTrue()->and($e->getMessage())->toBe('Groq answered 503: overloaded');
    }
    Http::assertSentCount(3);
});

it('does not retry a bad key or a bad request', function () {
    Http::fake([GROQ => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

    try {
        groq()->chat('m', [['role' => 'user', 'content' => 'Hi']]);
        $this->fail('Expected an exception');
    } catch (LlmException $e) {
        expect($e->retryable)->toBeFalse()->and($e->getMessage())->toContain('401: Invalid API Key');
    }
    Http::assertSentCount(1);
});

it('treats a timeout as retryable', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => groq()->chat('m', [['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(fn (LlmException $e) => expect($e->retryable)->toBeTrue()->and($e->getMessage())->toContain('timed out'));
});

it('treats an empty answer as retryable', function () {
    Http::fake([GROQ => Http::response(['choices' => []])]);

    expect(fn () => groq()->chat('m', [['role' => 'user', 'content' => 'Hi']]))->toThrow(LlmException::class, 'Groq sent no answer.');
});

it('refuses to call without a key', function () {
    (new GroqClient(''))->chat('m', [['role' => 'user', 'content' => 'Hi']]);
})->throws(LlmException::class, 'No Groq API key');

it('is the provider the app uses outside tests', function () {
    $this->app->forgetInstance(LlmClient::class);
    $this->app->offsetUnset(LlmClient::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(LlmClient::class))->toBeInstanceOf(GroqClient::class);
});
