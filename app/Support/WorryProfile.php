<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * How a contact reacts when the user goes quiet, from their relationship (a mom worries within a day; a coworker
 * sends one check-in after a week). Worry rises in stages by hours of silence, up to the plan's cap
 * (worry_max_stage), then settles into a steady daily check-in instead of escalating forever.
 */
final class WorryProfile
{
    /** Hours after the last stage begins before the contact settles. */
    public const SETTLE_AFTER_HOURS = 48;

    /**
     * @param  list<array{after_hours: int, texts_per_day: int, calls_per_day: int, tone: string}>  $stages
     * @param  array{texts_per_day: int, calls_per_day: int, tone: string}  $settle
     */
    private function __construct(public readonly int $noticeAfterHours, public readonly array $stages, public readonly array $settle) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $stages = $data['stages'] ?? null;
        if (! is_int($data['notice_after_hours'] ?? null) || ! is_array($stages) || $stages === [] || ! is_array($data['settle'] ?? null)) {
            throw new InvalidArgumentException('A worry profile needs notice_after_hours, at least one stage, and settle.');
        }

        $previous = -1;
        $clean = [];
        foreach ($stages as $stage) {
            $hours = $stage['after_hours'] ?? null;
            if (! is_int($hours) || $hours <= $previous) {
                throw new InvalidArgumentException('Worry stages need increasing after_hours.');
            }
            $previous = $hours;
            $clean[] = self::cadence($stage) + ['after_hours' => $hours];
        }

        return new self($data['notice_after_hours'], $clean, self::cadence($data['settle']));
    }

    /**
     * The stage after this many hours of silence (1 = first), capped at the plan's maximum. 0 means they haven't
     * noticed yet.
     */
    public function stageAt(float $hoursAway, int $maxStage): int
    {
        return $this->reached($hoursAway, $maxStage)[0] ?? 0;
    }

    /**
     * What the contact does at this point: texts and calls per day, and the tone. Null when they haven't noticed.
     *
     * @return array{stage: int, settled: bool, texts_per_day: int, calls_per_day: int, tone: string}|null
     */
    public function at(float $hoursAway, int $maxStage): ?array
    {
        $reached = $this->reached($hoursAway, $maxStage);
        if ($reached === null) {
            return null;
        }

        [$stage, $current] = $reached;
        if ($hoursAway >= $current['after_hours'] + self::SETTLE_AFTER_HOURS) {
            return ['stage' => $stage, 'settled' => true] + $this->settle;
        }

        return ['stage' => $stage, 'settled' => false, 'texts_per_day' => $current['texts_per_day'], 'calls_per_day' => $current['calls_per_day'], 'tone' => $current['tone']];
    }

    /**
     * The highest stage reached (number and details), within the plan's cap; null before they notice.
     *
     * @return array{0: int, 1: array{after_hours: int, texts_per_day: int, calls_per_day: int, tone: string}}|null
     */
    private function reached(float $hoursAway, int $maxStage): ?array
    {
        if ($hoursAway < $this->noticeAfterHours) {
            return null;
        }

        $found = null;
        foreach ($this->stages as $i => $stage) {
            if ($i + 1 > $maxStage || $hoursAway < $stage['after_hours']) {
                break;
            }
            $found = [$i + 1, $stage];
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{texts_per_day: int, calls_per_day: int, tone: string}
     */
    private static function cadence(array $data): array
    {
        $texts = $data['texts_per_day'] ?? null;
        $calls = $data['calls_per_day'] ?? null;
        $tone = $data['tone'] ?? null;
        if (! is_int($texts) || ! is_int($calls) || ! is_string($tone) || $texts < 0 || $calls < 0) {
            throw new InvalidArgumentException('Each worry step needs texts_per_day, calls_per_day and a tone.');
        }

        return ['texts_per_day' => $texts, 'calls_per_day' => $calls, 'tone' => $tone];
    }
}
