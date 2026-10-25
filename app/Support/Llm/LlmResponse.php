<?php

namespace App\Support\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $model,
    ) {}
}
