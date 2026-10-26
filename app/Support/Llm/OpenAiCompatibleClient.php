<?php

namespace App\Support\Llm;

use App\Contracts\LlmClient;
use App\Enums\LlmProvider;
use App\Exceptions\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Chat completions in the OpenAI format, which Grok (xAI) and Groq both speak. Rate limits and provider errors are
 * retried twice with a short backoff before giving up (then the gateway can try a fallback model).
 */
class OpenAiCompatibleClient implements LlmClient
{
    public function __construct(private LlmProvider $provider, private string $apiKey, private string $baseUrl, private int $timeoutSeconds = 30) {}

    public function chat(string $model, array $messages, array $options = []): LlmResponse
    {
        $name = $this->provider->shortName();
        if ($this->apiKey === '') {
            throw new LlmException("No {$name} API key is set ({$this->provider->envKey()}).", retryable: true);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->retry(3, fn (int $attempt) => 300 * $attempt, fn ($e) => $e instanceof RequestException && $this->retryable($e->response), throw: false)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $options['max_tokens'] ?? 1024,
                    'temperature' => $options['temperature'] ?? 0.8,
                ]);
        } catch (ConnectionException $e) {
            throw new LlmException("{$name} didn't answer in time: ".$e->getMessage(), retryable: true);
        }

        if ($response->failed()) {
            $why = (string) ($response->json('error.message') ?? $response->json('error') ?? $response->body());
            throw new LlmException("{$name} answered {$response->status()}: ".mb_substr($why, 0, 200), retryable: $this->retryable($response));
        }

        $text = $response->json('choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            $why = $response->json('choices.0.finish_reason') === 'content_filter' ? " ({$name} declined)" : '';

            throw new LlmException("{$name} sent no answer{$why}.", retryable: true);
        }

        return new LlmResponse(
            text: trim($text),
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
            model: (string) $response->json('model', $model),
        );
    }

    private function retryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }
}
