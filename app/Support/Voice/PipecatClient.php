<?php

namespace App\Support\Voice;

use App\Contracts\VoiceProvider;
use App\Exceptions\VoiceException;
use App\Services\Calling;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The voice service's REST API (voice/app.py), with the shared secret. The browser connects to $publicUrl.
 */
class PipecatClient implements VoiceProvider
{
    public function __construct(private string $url, private string $publicUrl, private string $secret) {}

    public function createSession(array $config): string
    {
        if ($this->secret === '') {
            throw new VoiceException('The voice service secret is not set (VOICE_SECRET).');
        }

        try {
            $response = Http::withHeaders([Calling::SECRET_HEADER => $this->secret])->acceptJson()->timeout(10)
                ->post(rtrim($this->url, '/').'/sessions', $config);
        } catch (ConnectionException $e) {
            throw new VoiceException('The voice service didn\'t answer: '.$e->getMessage());
        }

        $id = $response->json('id');
        if ($response->failed() || ! is_string($id)) {
            throw new VoiceException("The voice service answered {$response->status()}: ".mb_substr((string) ($response->json('detail') ?? $response->body()), 0, 200));
        }

        return $id;
    }

    public function deleteSession(string $id): void
    {
        try {
            Http::withHeaders([Calling::SECRET_HEADER => $this->secret])->timeout(5)->delete(rtrim($this->url, '/').'/sessions/'.rawurlencode($id));
        } catch (Throwable) {
            // A leftover session expires on its own after a few minutes.
        }
    }

    public function connectUrl(string $sessionId): string
    {
        return rtrim($this->publicUrl, '/').'/api/offer?session='.rawurlencode($sessionId);
    }
}
