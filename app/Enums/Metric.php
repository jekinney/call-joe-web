<?php

namespace App\Enums;

/**
 * Things a plan meters, and how often each count resets (in the user's own timezone).
 */
enum Metric: string
{
    case TextMessages = 'text_messages';
    case VoiceSeconds = 'voice_seconds';
    case Images = 'images';

    public function period(): Period
    {
        return match ($this) {
            self::TextMessages => Period::Day,
            self::VoiceSeconds, self::Images => Period::Month,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TextMessages => 'Texts',
            self::VoiceSeconds => 'Call time',
            self::Images => 'Pictures',
        };
    }

    /** A count as people say it ("12 min" for voice seconds). */
    public function format(int $amount): string
    {
        return match ($this) {
            self::VoiceSeconds => intdiv($amount, 60).' min',
            default => (string) $amount,
        };
    }
}
