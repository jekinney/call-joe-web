<?php

namespace App\Enums;

/**
 * What a safety check found (App\Services\Moderator).
 */
enum ModerationCategory: string
{
    /** Anything sexual involving someone under 18, or playing a minor or a teen. Always blocked. */
    case Minors = 'minors';

    /** The user may hurt themselves: the crisis path (988). */
    case SelfHarm = 'self_harm';

    /** A real threat or plan to hurt someone else: the crisis path (911). */
    case Violence = 'violence';

    /** A real, identifiable person's likeness (image prompts): no deepfakes. */
    case RealPerson = 'real_person';

    /** Graphic sexual content (image prompts, unless the plan allows Rated R). */
    case Explicit = 'explicit';

    public function label(): string
    {
        return match ($this) {
            self::Minors => 'Minors',
            self::SelfHarm => 'Self-harm',
            self::Violence => 'Violence',
            self::RealPerson => 'Real person',
            self::Explicit => 'Explicit',
        };
    }

    /** Someone may be in danger: the contact drops the jokes and points to real help. */
    public function isCrisis(): bool
    {
        return $this === self::SelfHarm || $this === self::Violence;
    }
}
