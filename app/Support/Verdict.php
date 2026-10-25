<?php

namespace App\Support;

use App\Enums\ModerationCategory;
use App\Models\ModerationEvent;

/**
 * What a safety check decided (App\Services\Moderator): allowed, blocked, or allowed but flagged, like the crisis path
 * when someone may be in danger.
 */
final class Verdict
{
    private function __construct(
        public readonly bool $blocked,
        public readonly ?ModerationCategory $category = null,
        public readonly ?ModerationEvent $event = null,
    ) {}

    public static function allow(): self
    {
        return new self(false);
    }

    public static function block(ModerationCategory $category, ModerationEvent $event): self
    {
        return new self(true, $category, $event);
    }

    /** Allowed, but noted for review. */
    public static function flag(ModerationCategory $category, ModerationEvent $event): self
    {
        return new self(false, $category, $event);
    }

    /** The safety check couldn't run, so whatever needed it doesn't happen (a picture isn't made). */
    public static function unavailable(): self
    {
        return new self(true);
    }

    /** Someone may be in danger: the contact answers on the crisis path. */
    public function isCrisis(): bool
    {
        return ! $this->blocked && $this->category?->isCrisis() === true;
    }
}
