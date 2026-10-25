<?php

namespace App\Support\Voice;

use App\Contracts\VoiceProvider;
use App\Exceptions\VoiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Vapi's REST API (https://api.vapi.ai), with the private key. The browser only ever gets the public key.
 */
class VapiClient implements VoiceProvider
{
    public function __construct(private string $privateKey, private string $publicKey, private string $baseUrl = 'https://api.vapi.ai') {}

    public function createAssistant(array $config): string
    {
        if ($this->privateKey === '' || $this->publicKey === '') {
            throw new VoiceException('Vapi keys are not set (VAPI_PRIVATE_KEY and VAPI_PUBLIC_KEY).');
        }

        try {
            $response = Http::withToken($this->privateKey)->acceptJson()->timeout(15)
                ->post(rtrim($this->baseUrl, '/').'/assistant', $config);
        } catch (ConnectionException $e) {
            throw new VoiceException('Vapi didn\'t answer: '.$e->getMessage());
        }

        $id = $response->json('id');
        if ($response->failed() || ! is_string($id)) {
            $why = $response->json('message');
            throw new VoiceException("Vapi answered {$response->status()}: ".mb_substr(is_array($why) ? implode('; ', $why) : (string) ($why ?? $response->body()), 0, 200));
        }

        return $id;
    }

    public function deleteAssistant(string $id): void
    {
        try {
            Http::withToken($this->privateKey)->timeout(10)->delete(rtrim($this->baseUrl, '/').'/assistant/'.rawurlencode($id));
        } catch (Throwable) {
            // A leftover assistant does no harm; it's only ever used for its one call.
        }
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }
}
