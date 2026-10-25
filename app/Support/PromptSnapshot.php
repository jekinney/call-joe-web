<?php

namespace App\Support;

/**
 * A built prompt: its full text, a hash of it, and its sections (for the admin preview). A conversation or call keeps
 * one snapshot from start to end, so the AI provider's prompt cache keeps hitting.
 */
final class PromptSnapshot
{
    /**
     * @param  array<string, string>  $sections
     */
    public function __construct(public readonly string $text, public readonly string $hash, public readonly array $sections) {}
}
