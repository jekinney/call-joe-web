<?php

namespace App\Enums;

/**
 * Yes/no features a plan turns on.
 */
enum Feature: string
{
    case RatedR = 'rated_r';
    case Voicemail = 'voicemail';
    case Videos = 'videos';

    /**
     * Whether the web app can deliver it. Plans and the Plan page only promise these: Rated R stays off (the web app is
     * suggestive, never explicit) and short videos are held back for cost.
     */
    public function isOffered(): bool
    {
        return match ($this) {
            self::Voicemail => true,
            self::RatedR, self::Videos => false,
        };
    }

    /** @return list<self> */
    public static function offered(): array
    {
        return array_values(array_filter(self::cases(), fn (self $feature) => $feature->isOffered()));
    }

    public function label(): string
    {
        return match ($this) {
            self::RatedR => 'Rated R flirting',
            self::Voicemail => 'Voicemail',
            self::Videos => 'Short videos',
        };
    }
}
