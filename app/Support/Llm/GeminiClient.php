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
 * Google's Gemini through its own generateContent API (the OpenAI-style one hides how much the model thinks).
 *
 * Gemini counts its thinking toward the answer's token limit, so the limit never goes below MIN_OUTPUT_TOKENS: a short
 * answer still has room to think first. With the "fast" option on, thinking is turned off (a thinking budget of zero),
 * which takes a Gemini 3.8 Flash answer from about 6 seconds to under 2. A few models, like Gemini 3.5 Flash-Lite,
 * already skip thinking and refuse a budget, so leave "fast" off for those.
 */
class GeminiClient implements LlmClient
{
    /** The least room Gemini gets for thinking and answering together. */
    public const MIN_OUTPUT_TOKENS = 2048;

    public function __construct(private string $apiKey, private string $baseUrl, private int $timeoutSeconds = 30) {}

    public function chat(string $model, array $messages, array $options = []): LlmResponse
    {
        if ($this->apiKey === '') {
            throw new LlmException('No Gemini API key is set ('.LlmProvider::Gemini->envKey().').', retryable: true);
        }

        ['system' => $system, 'turns' => $turns] = ChatMessages::split($messages);
        $config = [
            'maxOutputTokens' => max(self::MIN_OUTPUT_TOKENS, (int) ($options['max_tokens'] ?? 1024)),
            'temperature' => $options['temperature'] ?? 0.8,
        ];
        if ($options['fast'] ?? false) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $body = [
            'contents' => array_map(fn (array $turn) => ['role' => $turn['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $turn['content']]]], $turns),
            'generationConfig' => $config,
        ];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->retry(3, fn (int $attempt) => 300 * $attempt, fn ($e) => $e instanceof RequestException && $this->retryable($e->response), throw: false)
                ->post(rtrim($this->baseUrl, '/')."/models/{$model}:generateContent", $body);
        } catch (ConnectionException $e) {
            throw new LlmException("Gemini didn't answer in time: ".$e->getMessage(), retryable: true);
        }

        if ($response->failed()) {
            throw new LlmException("Gemini answered {$response->status()}: ".mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 200), retryable: $this->retryable($response));
        }

        $text = trim($this->textOf($response));
        if ($text === '') {
            $why = (string) ($response->json('promptFeedback.blockReason') ?? $response->json('candidates.0.finishReason') ?? '');

            throw new LlmException('Gemini sent no answer'.($why !== '' && $why !== 'STOP' ? " ({$why})" : '').'.', retryable: true);
        }

        $usage = (array) $response->json('usageMetadata', []);

        return new LlmResponse(
            text: $text,
            promptTokens: (int) ($usage['promptTokenCount'] ?? 0),
            // Thinking is billed as output.
            completionTokens: (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            model: (string) $response->json('modelVersion', $model),
        );
    }

    /** What the model said, without its thinking. */
    private function textOf(Response $response): string
    {
        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (is_array($part) && ! ($part['thought'] ?? false) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    private function retryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }
}
