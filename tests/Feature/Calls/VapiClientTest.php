<?php

use App\Contracts\VoiceProvider;
use App\Exceptions\VoiceException;
use App\Providers\AppServiceProvider;
use App\Support\Voice\VapiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// The Vapi wire: creating and deleting a call's assistant. No real network.

function vapi(string $private = 'sk_test'): VapiClient
{
    return new VapiClient($private, 'pk_test', 'https://api.vapi.test');
}

it('creates an assistant with the private key and returns its id', function () {
    Http::fake(['https://api.vapi.test/assistant' => Http::response(['id' => 'asst_123', 'orgId' => 'o'], 201)]);

    expect(vapi()->createAssistant(['name' => 'Test']))->toBe('asst_123');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer sk_test') && $r['name'] === 'Test');
});

it('explains a refusal', function () {
    Http::fake(['*' => Http::response(['message' => ['voice.voiceId must be a string']], 400)]);

    vapi()->createAssistant([]);
})->throws(VoiceException::class, 'Vapi answered 400: voice.voiceId must be a string');

it('explains when Vapi does not answer', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    vapi()->createAssistant([]);
})->throws(VoiceException::class, 'timed out');

it('refuses to call Vapi without keys', function () {
    vapi('')->createAssistant([]);
})->throws(VoiceException::class, 'Vapi keys are not set');

it('deletes an assistant, and never fails because of it', function () {
    Http::fake(['https://api.vapi.test/assistant/asst_1' => Http::response(null, 200)]);
    vapi()->deleteAssistant('asst_1');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE');

    Http::fake(fn () => throw new ConnectionException('down'));
    vapi()->deleteAssistant('asst_2');
    expect(true)->toBeTrue();
});

it('gives the browser only the public key', function () {
    expect(vapi()->publicKey())->toBe('pk_test');
});

it('is the voice provider the app uses outside tests', function () {
    $this->app->offsetUnset(VoiceProvider::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(VoiceProvider::class))->toBeInstanceOf(VapiClient::class);
});
