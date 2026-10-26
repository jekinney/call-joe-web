<?php

namespace App\Services;

use Anthropic\Client as AnthropicSdk;
use App\Contracts\LlmClient;
use App\Enums\LlmProvider;
use App\Exceptions\LlmException;
use App\Support\Llm\AnthropicClient;
use App\Support\Llm\GeminiClient;
use App\Support\Llm\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The AI providers the app can use, each with its own key in .env (config/services.php). The gateway asks here for
 * the client that talks to a model's provider. A provider with no key is "not set up": its models fail with a
 * retryable error, so the gateway moves on to the model's fallback.
 */
class LlmProviders
{
    /** How long a provider's list of models is remembered. */
    public const MODELS_CACHE_SECONDS = 600;

    /** Claude's models (the list is on the Claude API too, but this works with no key to ask). */
    private const CLAUDE_MODELS = ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-1', 'claude-opus-5', 'claude-sonnet-5'];

    /** @var array<string, LlmClient> */
    private array $clients = [];

    private ?LlmClient $fake = null;

    /** Tests answer every provider with one fake client. */
    public function fake(?LlmClient $client): void
    {
        $this->fake = $client;
    }

    public function isConfigured(LlmProvider $provider): bool
    {
        return $this->fake !== null || $provider->hasKey();
    }

    /**
     * @throws LlmException when the provider has no key
     */
    public function client(LlmProvider $provider): LlmClient
    {
        if ($this->fake) {
            return $this->fake;
        }
        if (! $provider->hasKey()) {
            throw new LlmException("No {$provider->shortName()} API key is set ({$provider->envKey()}).", retryable: true);
        }

        return $this->clients[$provider->value] ??= $this->build($provider);
    }

    /**
     * Model ids the provider says it offers, for the admin form to suggest (empty if it can't be asked).
     *
     * @return list<string>
     */
    public function modelIds(LlmProvider $provider): array
    {
        if ($provider === LlmProvider::Anthropic) {
            return self::CLAUDE_MODELS;
        }
        if (! $provider->hasKey()) {
            return [];
        }

        return Cache::remember("llm-provider-models:{$provider->value}", self::MODELS_CACHE_SECONDS, fn () => $this->fetchModelIds($provider));
    }

    private function build(LlmProvider $provider): LlmClient
    {
        $key = $provider->key();
        $timeout = (int) config("services.{$provider->value}.timeout", 30);
        $base = (string) config("services.{$provider->value}.base_url");

        return match ($provider) {
            LlmProvider::Anthropic => new AnthropicClient(new AnthropicSdk(
                apiKey: $key,
                baseUrl: $base !== '' ? $base : null,
                requestOptions: ['timeout' => $timeout, 'maxRetries' => 2],
            )),
            LlmProvider::Gemini => new GeminiClient($key, $base, $timeout),
            LlmProvider::Xai, LlmProvider::Groq => new OpenAiCompatibleClient($provider, $key, $base, $timeout),
        };
    }

    /** @return list<string> */
    private function fetchModelIds(LlmProvider $provider): array
    {
        $base = rtrim((string) config("services.{$provider->value}.base_url"), '/');

        try {
            if ($provider === LlmProvider::Gemini) {
                $models = (array) Http::withHeaders(['x-goog-api-key' => $provider->key()])->timeout(15)->get("{$base}/models", ['pageSize' => 300])->throw()->json('models', []);
                $ids = collect($models)->filter(fn ($m) => in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true))
                    ->map(fn ($m) => str_replace('models/', '', (string) $m['name']));
            } else {
                $models = (array) Http::withToken($provider->key())->timeout(15)->get("{$base}/models")->throw()->json('data', []);
                $ids = collect($models)->map(fn ($m) => (string) $m['id']);
            }
        } catch (Throwable) {
            return [];
        }

        return array_values($ids->filter()->sort()->all());
    }
}
