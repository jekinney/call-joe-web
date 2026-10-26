<?php

use App\Exceptions\VoiceException;
use App\Support\Voice\PipecatClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// The real client for the voice service (never reached in tests: Http is faked).

function pipecat(string $secret = 'shh'): PipecatClient
{
    return new PipecatClient('http://voice.internal:8787/', 'https://voice.example.test/', $secret);
}

$config = ['call_id' => 7, 'prompt' => 'You are Maya.', 'voice' => 'Kore', 'model' => 'gemini-3.8-live', 'max_seconds' => 600];

it('creates a session with the shared secret and returns its id', function () use ($config) {
    Http::fake(['voice.internal:8787/*' => Http::response(['id' => 'abc123'])]);

    expect(pipecat()->createSession($config))->toBe('abc123');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://voice.internal:8787/sessions'
        && $r->hasHeader('X-Call-Joe-Secret', 'shh')
        && $r['prompt'] === 'You are Maya.' && $r['voice'] === 'Kore' && $r['max_seconds'] === 600);
});

it('will not create a session without a secret', function () use ($config) {
    Http::fake();

    expect(fn () => pipecat('')->createSession($config))->toThrow(VoiceException::class, 'VOICE_SECRET');
    Http::assertNothingSent();
});

it('says so when the voice service is down', function () use ($config) {
    Http::fake(['voice.internal:8787/*' => fn () => throw new ConnectionException('refused')]);

    expect(fn () => pipecat()->createSession($config))->toThrow(VoiceException::class, "didn't answer");
});

it('says so when the voice service refuses', function () use ($config) {
    Http::fake(['voice.internal:8787/*' => Http::response(['detail' => 'Not allowed.'], 401)]);

    expect(fn () => pipecat()->createSession($config))->toThrow(VoiceException::class, 'answered 401: Not allowed.');
});

it('deletes a session', function () {
    Http::fake(['voice.internal:8787/*' => Http::response(['ok' => true])]);

    pipecat()->deleteSession('abc123');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://voice.internal:8787/sessions/abc123');
});

it('ignores a failure deleting a session', function () {
    Http::fake(['voice.internal:8787/*' => fn () => throw new ConnectionException('gone')]);

    pipecat()->deleteSession('abc123');

    expect(true)->toBeTrue();
});

it('gives the browser the public connect link for a session', function () {
    expect(pipecat()->connectUrl('abc 123'))->toBe('https://voice.example.test/api/offer?session=abc%20123');
});
