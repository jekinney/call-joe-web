<?php

namespace App\Services;

use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Contact;
use App\Models\Llm;
use App\Models\LlmCall;
use App\Models\User;
use App\Support\Llm\LlmResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The one way the app asks an AI anything. It picks the model (by purpose, and the user's plan: small or large),
 * sends the question to that model's provider (Claude, Gemini, Grok or Groq: App\Services\LlmProviders), logs every
 * attempt with tokens, time and cost (llm_calls), and if the provider fails in a way worth retrying (or has no key),
 * asks the model's fallback once instead, usually on another provider.
 */
class LlmGateway
{
    /** How long the chosen models are remembered; admin changes clear it straight away. */
    public const CACHE_SECONDS = 60;

    public function __construct(private LlmProviders $providers, private Entitlements $entitlements) {}

    /**
     * The active model for this purpose. Texting with a contact uses the contact's own brain, chosen by whoever
     * created them (users can't change it); everything else uses the purpose's models: the user's plan tier first,
     * then any-tier models.
     */
    public function modelFor(LlmPurpose $purpose, ?User $user = null, ?Contact $contact = null): Llm
    {
        if ($purpose === LlmPurpose::Chat && $contact?->brain?->is_active) {
            return $contact->brain;
        }

        $tier = $user ? (string) $this->entitlements->setting($user, 'llm_tier', 'small') : null;

        $models = Cache::remember($this->cacheKey($purpose), self::CACHE_SECONDS, fn () => Llm::with('fallback')
            ->where('purpose', $purpose->value)->where('is_active', true)->orderBy('sort')->get());

        $model = $models->firstWhere('plan_tier', $tier) ?? $models->firstWhere('plan_tier', null) ?? $models->first();

        return $model ?? throw new LlmException("No active model for {$purpose->label()}. Add one in /admin/models.");
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{max_tokens?: int, temperature?: float}  $options
     */
    public function chat(LlmPurpose $purpose, array $messages, ?User $user = null, ?Contact $contact = null, array $options = []): LlmResponse
    {
        return $this->ask($this->modelFor($purpose, $user, $contact), $purpose, $messages, $user, $contact, $options);
    }

    /**
     * Asks one specific model (and its fallback if it fails): used by chat(), and by the admin's Test button (which
     * asks without the fallback, so a problem shows instead of being covered up).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{max_tokens?: int, temperature?: float}  $options
     */
    public function ask(Llm $model, LlmPurpose $purpose, array $messages, ?User $user = null, ?Contact $contact = null, array $options = [], bool $withFallback = true): LlmResponse
    {
        try {
            return $this->attempt($model, $purpose, $messages, $user, $contact, $options, 'ok');
        } catch (LlmException $e) {
            $fallback = $model->fallback;
            if (! $withFallback || ! $e->retryable || ! $fallback || ! $fallback->is_active || $fallback->is($model)) {
                throw $e;
            }

            return $this->attempt($fallback, $purpose, $messages, $user, $contact, $options, 'fallback');
        }
    }

    /** Clears the remembered models (after an admin edits them). */
    public function forgetModels(): void
    {
        foreach (LlmPurpose::cases() as $purpose) {
            Cache::forget($this->cacheKey($purpose));
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{max_tokens?: int, temperature?: float}  $options
     */
    private function attempt(Llm $model, LlmPurpose $purpose, array $messages, ?User $user, ?Contact $contact, array $options, string $status): LlmResponse
    {
        $started = hrtime(true);
        $log = ['user_id' => $user?->id, 'contact_id' => $contact?->id, 'llm_model_id' => $model->id, 'model' => $model->model_id, 'purpose' => $purpose];

        try {
            $response = $this->providers->client($model->provider)->chat(
                $model->model_id, $messages, $options + ['max_tokens' => $model->max_tokens] + ($model->fast_thinking ? ['fast' => true] : []),
            );
        } catch (LlmException $e) {
            LlmCall::create($log + ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 300), 'latency_ms' => $this->msSince($started)]);

            throw $e;
        }

        LlmCall::create($log + [
            'status' => $status,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $this->msSince($started),
            'cost_micros' => $model->costMicros($response->promptTokens, $response->completionTokens),
        ]);

        return $response;
    }

    private function msSince(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }

    private function cacheKey(LlmPurpose $purpose): string
    {
        return 'llm-models:'.$purpose->value;
    }
}
