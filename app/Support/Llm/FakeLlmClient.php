<?php

namespace App\Support\Llm;

use App\Contracts\LlmClient;
use App\Exceptions\LlmException;

/**
 * Stands in for a real provider in tests (bound in tests/TestCase.php). Answers from a script, or "Fake reply N";
 * can be told to fail for a given model; remembers every call.
 */
class FakeLlmClient implements LlmClient
{
    /** @var list<string> */
    private array $script = [];

    /** @var array<string, LlmException> */
    private array $failures = [];

    /** @var list<array{model: string, messages: list<array{role: string, content: string}>, options: array<string, mixed>}> */
    public array $calls = [];

    /** Answers to give, in order. */
    public function reply(string ...$texts): static
    {
        array_push($this->script, ...$texts);

        return $this;
    }

    /** Make calls to this model fail. */
    public function failFor(string $model, bool $retryable = true, string $message = 'The fake provider is down.'): static
    {
        $this->failures[$model] = new LlmException($message, $retryable);

        return $this;
    }

    public function chat(string $model, array $messages, array $options = []): LlmResponse
    {
        $this->calls[] = ['model' => $model, 'messages' => $messages, 'options' => $options];

        if (isset($this->failures[$model])) {
            throw $this->failures[$model];
        }

        $text = array_shift($this->script) ?? 'Fake reply '.count($this->calls);
        $promptTokens = (int) ceil(mb_strlen(implode(' ', array_column($messages, 'content'))) / 4);

        return new LlmResponse($text, $promptTokens, (int) ceil(mb_strlen($text) / 4), $model);
    }
}
