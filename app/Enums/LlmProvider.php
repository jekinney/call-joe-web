<?php

namespace App\Enums;

/**
 * Who runs an AI model. Each model in /admin/models belongs to one provider, and each provider has its own key in
 * .env (see config/services.php).
 */
enum LlmProvider: string
{
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case Xai = 'xai';
    case Groq = 'groq';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Claude (Anthropic)',
            self::Gemini => 'Gemini (Google)',
            self::Xai => 'Grok (xAI)',
            self::Groq => 'Groq',
        };
    }

    /** The name used in messages like "No Claude API key is set". */
    public function shortName(): string
    {
        return match ($this) {
            self::Anthropic => 'Claude',
            self::Gemini => 'Gemini',
            self::Xai => 'Grok',
            self::Groq => 'Groq',
        };
    }

    /** The setting in .env that holds this provider's key. */
    public function envKey(): string
    {
        return match ($this) {
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::Gemini => 'GEMINI_API_KEY',
            self::Xai => 'XAI_API_KEY',
            self::Groq => 'GROQ_API_KEY',
        };
    }

    public function key(): string
    {
        return (string) config("services.{$this->value}.key");
    }

    public function hasKey(): bool
    {
        return $this->key() !== '';
    }

    /** What Vapi calls this provider (calls still run on Vapi until Pipecat replaces it). */
    public function vapiName(): string
    {
        return match ($this) {
            self::Gemini => 'google',
            default => $this->value,
        };
    }

    /** An example of a model id, for the admin form. */
    public function exampleModel(): string
    {
        return match ($this) {
            self::Anthropic => 'claude-haiku-5-5',
            self::Gemini => 'gemini-3.5-flash-lite',
            self::Xai => 'grok-4.20-0309-non-reasoning',
            self::Groq => 'llama-3.3-70b-versatile',
        };
    }
}
