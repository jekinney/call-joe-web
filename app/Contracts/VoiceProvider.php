<?php

namespace App\Contracts;

use App\Exceptions\VoiceException;

/**
 * Our voice service (voice/, Pipecat speaking with Gemini Live). App\Services\Calling is what the app uses; this is
 * just the wire. Tests use App\Support\Voice\FakeVoiceProvider and never reach the service.
 */
interface VoiceProvider
{
    /**
     * Gets one call ready on the voice service and returns its session id. The prompt stays on the server: the browser
     * only ever gets the session id.
     *
     * @param  array{call_id: int, prompt: string, voice: string, model: string, max_seconds: int}  $config
     *
     * @throws VoiceException
     */
    public function createSession(array $config): string;

    /** Removes a call's session once the call is over (failures are ignored). */
    public function deleteSession(string $id): void;

    /** Where the browser connects (WebRTC) for that session. */
    public function connectUrl(string $sessionId): string;
}
