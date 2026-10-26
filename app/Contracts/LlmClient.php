<?php

namespace App\Contracts;

use App\Exceptions\LlmException;
use App\Support\Llm\LlmResponse;

/**
 * Talks to one AI provider (Claude, Gemini, Grok or Groq). App\Services\LlmGateway is what the rest of the app uses;
 * this is just the wire. Tests use App\Support\Llm\FakeLlmClient and never reach a real provider.
 */
interface LlmClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages  system, user and assistant turns
     * @param  array{max_tokens?: int, temperature?: float, fast?: bool}  $options  fast: think as little as the model allows
     *
     * @throws LlmException when the provider fails (retryable or not)
     */
    public function chat(string $model, array $messages, array $options = []): LlmResponse;
}
