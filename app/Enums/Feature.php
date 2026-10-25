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

    public function label(): string
    {
        return match ($this) {
            self::RatedR => 'Rated R flirting',
            self::Voicemail => 'Voicemail',
            self::Videos => 'Short videos',
        };
    }
}
