<?php

use App\Enums\Feature;
use App\Enums\Metric;
use App\Enums\Period;
use App\Models\Plan;
use Carbon\CarbonImmutable;

// The small pieces: periods, metric labels, plan prices.

it('starts and ends periods', function () {
    $at = CarbonImmutable::parse('2026-01-31 15:20');

    expect(Period::Day->start($at)->toDateTimeString())->toBe('2026-01-31 00:00:00')
        ->and(Period::Day->end($at)->toDateTimeString())->toBe('2026-02-01 00:00:00')
        ->and(Period::Month->start($at)->toDateString())->toBe('2026-01-01')
        ->and(Period::Month->end($at)->toDateString())->toBe('2026-02-01');
});

it('resets texts daily and call time and pictures monthly', function () {
    expect(Metric::TextMessages->period())->toBe(Period::Day)
        ->and(Metric::VoiceSeconds->period())->toBe(Period::Month)
        ->and(Metric::Images->period())->toBe(Period::Month);
});

it('labels and formats metrics as people say them', function () {
    expect(Metric::VoiceSeconds->label())->toBe('Call time')
        ->and(Metric::VoiceSeconds->format(605))->toBe('10 min')
        ->and(Metric::TextMessages->format(12))->toBe('12')
        ->and(Metric::Images->label())->toBe('Pictures')
        ->and(Metric::TextMessages->label())->toBe('Texts');
});

it('labels features', function () {
    expect(collect(Feature::cases())->map->label()->all())->toBe(['Rated R flirting', 'Voicemail', 'Short videos']);
});

it('shows prices', function () {
    expect((new Plan(['price_cents' => 0]))->priceLabel())->toBe('Free')
        ->and((new Plan(['price_cents' => 1499]))->priceLabel())->toBe('$14.99/month');
});
