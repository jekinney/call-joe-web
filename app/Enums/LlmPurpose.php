<?php

namespace App\Enums;

/**
 * What an AI call is for; each purpose has its own models in /admin/models.
 */
enum LlmPurpose: string
{
    case Chat = 'chat';
    case Memory = 'memory';
    case Moderation = 'moderation';
    case Voice = 'voice';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Texting',
            self::Memory => 'Memory notes',
            self::Moderation => 'Safety checks',
            self::Voice => 'Voice calls',
        };
    }
}
