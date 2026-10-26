<?php

use Anthropic\Client as AnthropicSdk;
use App\Enums\LlmProvider;
use App\Exceptions\LlmException;
use App\Support\Llm\AnthropicClient;
use App\Support\Llm\GeminiClient;
use App\Support\Llm\OpenAiCompatibleClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// The wires to Grok and Groq (OpenAI-style), Gemini, and Claude (Anthropic's own SDK). No real network.

const CHAT = [['role' => 'system', 'content' => 'You are Joe.'], ['role' => 'user', 'content' => 'Hi']];

function whyFails(callable $call): LlmException
{
    try {
        $call();
    } catch (LlmException $e) {
        return $e;
    }

    throw new RuntimeException('Expected an LlmException.');
}

// ---------------------------------------------------------------- Grok and Groq

function grok(string $key = 'xai-test'): OpenAiCompatibleClient
{
    return new OpenAiCompatibleClient(LlmProvider::Xai, $key, 'https://xai.test/v1/', 5);
}

function openAiAnswer(string $text = 'Hey there!'): array
{
    return ['model' => 'grok-4.20', 'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 15]];
}

it('asks an OpenAI-style provider and reads the answer, tokens and model', function () {
    Http::fake(['xai.test/*' => Http::response(openAiAnswer(' Hey there! '))]);

    $reply = grok()->chat('grok-4.20', CHAT, ['max_tokens' => 50, 'temperature' => 0.2]);

    expect($reply->text)->toBe('Hey there!')->and($reply->promptTokens)->toBe(120)->and($reply->completionTokens)->toBe(15)->and($reply->model)->toBe('grok-4.20');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://xai.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer xai-test')
        && $request->data() === ['model' => 'grok-4.20', 'messages' => CHAT, 'max_tokens' => 50, 'temperature' => 0.2]);
});

it('defaults to a roomy, lively answer, and says "fast" means nothing to it', function () {
    Http::fake(['*' => Http::response(openAiAnswer())]);

    grok()->chat('grok-4.20', CHAT, ['fast' => true]);

    Http::assertSent(fn (Request $request) => $request['max_tokens'] === 1024 && $request['temperature'] === 0.8 && ! isset($request['fast']));
});

it('names the provider in what goes wrong', function (Closure $fake, string $message, bool $retryable) {
    Sleep::fake();
    $fake();

    $error = whyFails(fn () => grok()->chat('grok-4.20', CHAT));

    expect($error->getMessage())->toBe($message)->and($error->retryable)->toBe($retryable);
})->with([
    'rate limited, even after retrying' => [fn () => Http::fake(['*' => Http::response(['error' => ['message' => 'Slow down']], 429)]), 'Grok answered 429: Slow down', true],
    'their server broke' => [fn () => Http::fake(['*' => Http::response('Bad gateway', 502)]), 'Grok answered 502: Bad gateway', true],
    'a bad request' => [fn () => Http::fake(['*' => Http::response(['error' => 'Unknown model'], 400)]), 'Grok answered 400: Unknown model', false],
    'no answer at all' => [fn () => Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '  ']]]])]), 'Grok sent no answer.', true],
    'a filtered answer' => [fn () => Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => null], 'finish_reason' => 'content_filter']]])]), 'Grok sent no answer (Grok declined).', true],
    'no connection' => [fn () => Http::fake(fn () => throw new ConnectionException('timed out')), "Grok didn't answer in time: timed out", true],
]);

it('tries again after a rate limit and then answers', function () {
    Sleep::fake();
    Http::fake(['*' => Http::sequence()->push(['error' => ['message' => 'Slow down']], 429)->push(openAiAnswer('Second try'))]);

    expect(grok()->chat('grok-4.20', CHAT)->text)->toBe('Second try');
    Http::assertSentCount(2);
});

it('does not retry what trying again won\'t fix', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

    whyFails(fn () => grok()->chat('grok-4.20', CHAT));

    Http::assertSentCount(1);
});

it('says which key is missing, so the gateway tries its fallback', function () {
    $error = whyFails(fn () => grok('')->chat('grok-4.20', CHAT));

    expect($error->getMessage())->toBe('No Grok API key is set (XAI_API_KEY).')->and($error->retryable)->toBeTrue();
    Http::assertNothingSent();
});

// ---------------------------------------------------------------- Gemini

function gemini(string $key = 'g-test'): GeminiClient
{
    return new GeminiClient($key, 'https://gemini.test/v1beta/', 5);
}

function geminiAnswer(array $parts = [['text' => 'Hey there!']], array $extra = []): array
{
    return $extra + [
        'candidates' => [['content' => ['parts' => $parts], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 12, 'thoughtsTokenCount' => 40],
        'modelVersion' => 'gemini-3.5-flash-lite-001',
    ];
}

it('asks Gemini with a system instruction and turns, and reads the answer', function () {
    Http::fake(['gemini.test/*' => Http::response(geminiAnswer([['text' => 'Hey '], ['text' => 'there!']]))]);

    $reply = gemini()->chat('gemini-3.5-flash-lite', [
        ['role' => 'system', 'content' => 'You are Joe.'],
        ['role' => 'user', 'content' => 'Hi'],
        ['role' => 'assistant', 'content' => 'Hello!'],
        ['role' => 'user', 'content' => 'Guess what'],
        ['role' => 'system', 'content' => '(Jamie sounds tired.)'],
    ], ['max_tokens' => 300, 'temperature' => 0.3]);

    expect($reply->text)->toBe('Hey there!')->and($reply->promptTokens)->toBe(200)->and($reply->completionTokens)->toBe(52) // answer + thinking
        ->and($reply->model)->toBe('gemini-3.5-flash-lite-001');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://gemini.test/v1beta/models/gemini-3.5-flash-lite:generateContent'
        && $request->hasHeader('x-goog-api-key', 'g-test')
        && $request->data() === [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Hi']]],
                ['role' => 'model', 'parts' => [['text' => 'Hello!']]],
                ['role' => 'user', 'parts' => [['text' => "Guess what\n\n(Jamie sounds tired.)"]]],
            ],
            'generationConfig' => ['maxOutputTokens' => 2048, 'temperature' => 0.3], // room to think, whatever was asked for
            'systemInstruction' => ['parts' => [['text' => 'You are Joe.']]],
        ]);
});

it('turns thinking off for fast Gemini models, and keeps a bigger limit', function () {
    Http::fake(['*' => Http::response(geminiAnswer())]);

    gemini()->chat('gemini-3.8-flash', [['role' => 'user', 'content' => 'Hi']], ['fast' => true, 'max_tokens' => 4096]);

    Http::assertSent(fn (Request $request) => $request['generationConfig'] === ['maxOutputTokens' => 4096, 'temperature' => 0.8, 'thinkingConfig' => ['thinkingBudget' => 0]]
        && ! isset($request['systemInstruction']));
});

it('leaves out what Gemini thought to itself', function () {
    Http::fake(['*' => Http::response(geminiAnswer([['text' => 'Let me think...', 'thought' => true], ['text' => 'The answer.']]))]);

    expect(gemini()->chat('gemini-3.8-flash', CHAT)->text)->toBe('The answer.');
});

it('explains why Gemini said nothing', function (array $answer, string $message) {
    Http::fake(['*' => Http::response($answer)]);

    $error = whyFails(fn () => gemini()->chat('gemini-3.5-flash-lite', CHAT));

    expect($error->getMessage())->toBe($message)->and($error->retryable)->toBeTrue();
})->with([
    'blocked before answering' => [['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']], 'Gemini sent no answer (PROHIBITED_CONTENT).'],
    'stopped for safety' => [['candidates' => [['finishReason' => 'SAFETY']]], 'Gemini sent no answer (SAFETY).'],
    'just nothing' => [['candidates' => [['content' => ['parts' => [['text' => ' ']]], 'finishReason' => 'STOP']]], 'Gemini sent no answer.'],
]);

it('names Gemini in what goes wrong', function (Closure $fake, string $message, bool $retryable) {
    Sleep::fake();
    $fake();

    $error = whyFails(fn () => gemini()->chat('gemini-3.5-flash-lite', CHAT));

    expect($error->getMessage())->toBe($message)->and($error->retryable)->toBe($retryable);
})->with([
    'a bad thinking setting' => [fn () => Http::fake(['*' => Http::response(['error' => ['message' => 'Request contains an invalid argument.']], 400)]), 'Gemini answered 400: Request contains an invalid argument.', false],
    'overloaded' => [fn () => Http::fake(['*' => Http::response('Try later', 503)]), 'Gemini answered 503: Try later', true],
    'no connection' => [fn () => Http::fake(fn () => throw new ConnectionException('timed out')), "Gemini didn't answer in time: timed out", true],
]);

it('says which Gemini key is missing', function () {
    $error = whyFails(fn () => gemini('')->chat('gemini-3.5-flash-lite', CHAT));

    expect($error->getMessage())->toBe('No Gemini API key is set (GEMINI_API_KEY).')->and($error->retryable)->toBeTrue();
});

// ---------------------------------------------------------------- Claude (through Anthropic's PHP SDK)

/**
 * A Claude client whose HTTP goes through Guzzle's mock: the answers it should get, and a history of what it sent.
 *
 * @param  list<PsrResponse|Throwable>  $answers
 * @param  array<int, array{request: PsrRequest}>  $history
 */
function claude(array $answers, array &$history = []): AnthropicClient
{
    $stack = HandlerStack::create(new MockHandler($answers));
    $stack->push(Middleware::history($history));

    return new AnthropicClient(new AnthropicSdk(apiKey: 'sk-ant-test', requestOptions: ['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]));
}

function claudeAnswer(array $content = [['type' => 'text', 'text' => 'Hey there!']], array $extra = []): PsrResponse
{
    return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($extra + [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'content' => $content,
        'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 30, 'cache_creation_input_tokens' => 5, 'cache_read_input_tokens' => 912, 'output_tokens' => 21],
    ]));
}

function claudeError(int $status, string $message): PsrResponse
{
    return new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => $message]]));
}

it('asks Claude with a cached system prompt, alternating turns and no temperature', function () {
    $history = [];
    $client = claude([claudeAnswer([['type' => 'text', 'text' => 'Hey '], ['type' => 'text', 'text' => 'there!']])], $history);

    $reply = $client->chat('claude-haiku-5-5', [
        ['role' => 'system', 'content' => 'You are Joe.'],
        ['role' => 'user', 'content' => 'Hi'],
        ['role' => 'assistant', 'content' => 'Hello!'],
        ['role' => 'user', 'content' => 'Guess what'],
        ['role' => 'system', 'content' => '(Jamie sounds tired.)'],
    ], ['max_tokens' => 300, 'temperature' => 0.3]);

    expect($reply->text)->toBe('Hey there!')->and($reply->model)->toBe('claude-haiku-5-5')
        ->and($reply->promptTokens)->toBe(30 + 5 + 912)->and($reply->completionTokens)->toBe(21);

    $request = $history[0]['request'];
    expect($request->getUri()->getPath())->toBe('/v1/messages')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer sk-ant-test')
        ->and($request->getHeaderLine('anthropic-version'))->toBe('2026-10-21')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'max_tokens' => 2048, // room to think, whatever was asked for; and no temperature at all
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello!'],
                ['role' => 'user', 'content' => "Guess what\n\n(Jamie sounds tired.)"],
            ],
            'model' => 'claude-haiku-5-5',
            'system' => [['type' => 'text', 'text' => 'You are Joe.', 'cache_control' => ['type' => 'ephemeral']]],
        ]);
});

it('asks Claude for low effort when fast, and keeps a bigger limit', function () {
    $history = [];

    claude([claudeAnswer()], $history)->chat('claude-sonnet-5-5', [['role' => 'user', 'content' => 'Hi']], ['fast' => true, 'max_tokens' => 4096]);

    expect(json_decode((string) $history[0]['request']->getBody(), true))->toMatchArray(['max_tokens' => 4096, 'output_config' => ['effort' => 'low']])
        ->not->toHaveKeys(['system', 'temperature']);
});

it('passes Claude\'s trouble on, saying what is worth trying again', function (Closure $answer, string $message, bool $retryable) {
    $error = whyFails(fn () => claude([$answer()])->chat('claude-haiku-5-5', CHAT));

    expect($error->getMessage())->toBe($message)->and($error->retryable)->toBe($retryable);
})->with([
    'rate limited' => [fn () => claudeError(429, 'Slow down'), 'Claude answered 429: Slow down', true],
    'overloaded' => [fn () => claudeError(529, 'Overloaded'), 'Claude answered 529: Overloaded', true],
    'a bad request' => [fn () => claudeError(400, 'Unknown model'), 'Claude answered 400: Unknown model', false],
    'a bad key' => [fn () => claudeError(401, 'invalid x-api-key'), 'Claude answered 401: invalid x-api-key', false],
    'a refusal' => [fn () => claudeAnswer([], ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]]), 'Claude declined to answer.', true],
    'nothing said' => [fn () => claudeAnswer([['type' => 'text', 'text' => ' ']]), 'Claude sent no answer.', true],
    'no connection' => [fn () => new ConnectException('Connection refused', new PsrRequest('POST', 'https://api.anthropic.test')), "Claude didn't answer in time: Anthropic API Connection Error", true],
]);
