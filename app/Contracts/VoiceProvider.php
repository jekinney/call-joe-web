<?php

namespace App\Contracts;

use App\Exceptions\VoiceException;

/**
 * The voice-call provider (Vapi). App\Services\Calling is what the app uses; this is just the wire.
 * Tests use App\Support\Voice\FakeVoiceProvider and never reach Vapi.
 */
interface VoiceProvider
{
    /**
     * Creates a short-lived assistant for one call and returns its id.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws VoiceException
     */
    public function createAssistant(array $config): string;

    /** Removes a call's assistant once the call is over (failures are ignored). */
    public function deleteAssistant(string $id): void;

    /** The public key the browser uses to start the call. */
    public function publicKey(): string;
}
