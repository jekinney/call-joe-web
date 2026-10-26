<?php

namespace App\Support\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\TextBlock;
use App\Contracts\LlmClient;
use App\Exceptions\LlmException;

/**
 * Claude, through Anthropic's own PHP SDK. A few things the newest models need:
 *
 * - No temperature: the API (version 2026-10-21 and later) rejects it on every model.
 * - The system prompt is marked for prompt caching, so a conversation's frozen prompt is read back at a tenth of the
 *   price on every turn.
 * - Thinking counts toward max_tokens, so the limit never goes below MIN_TOKENS: a short answer still has room to
 *   think first. With the "fast" option on, effort is low: much less thinking, and answers in about a second and a half.
 * - A refusal (stop reason "refusal") is an error the gateway can answer with its fallback model.
 */
class AnthropicClient implements LlmClient
{
    /** The least room Claude gets for thinking and answering together. */
    public const MIN_TOKENS = 2048;

    public function __construct(private Client $sdk) {}

    public function chat(string $model, array $messages, array $options = []): LlmResponse
    {
        ['system' => $system, 'turns' => $turns] = ChatMessages::split($messages);

        try {
            $message = $this->sdk->messages->create(
                maxTokens: max(self::MIN_TOKENS, (int) ($options['max_tokens'] ?? 1024)),
                messages: $turns,
                model: $model,
                outputConfig: ($options['fast'] ?? false) ? ['effort' => 'low'] : null,
                system: $system !== '' ? [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]] : null,
            );
        } catch (APIStatusException $e) {
            $why = is_array($e->body) && is_array($e->body['error'] ?? null) ? (string) ($e->body['error']['message'] ?? '') : '';

            throw new LlmException("Claude answered {$e->status}: ".mb_substr($why ?: 'no details', 0, 200), retryable: $e->status === 429 || (int) $e->status >= 500);
        } catch (APIException $e) {
            throw new LlmException("Claude didn't answer in time: ".mb_substr(trim($e->getMessage()), 0, 150), retryable: true);
        }

        if ($message->stopReason === 'refusal') {
            throw new LlmException('Claude declined to answer.', retryable: true);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }
        if (trim($text) === '') {
            throw new LlmException('Claude sent no answer.', retryable: true);
        }

        $usage = $message->usage;

        return new LlmResponse(
            text: trim($text),
            promptTokens: $usage->inputTokens + (int) $usage->cacheCreationInputTokens + (int) $usage->cacheReadInputTokens,
            completionTokens: $usage->outputTokens,
            model: $message->model,
        );
    }
}
