<?php

use App\Support\WorryProfile;

// How worry rises with hours of silence, capped by the plan, then settles.

function sisterLike(): WorryProfile
{
    return WorryProfile::fromArray([
        'notice_after_hours' => 36,
        'stages' => [
            ['after_hours' => 36, 'texts_per_day' => 1, 'calls_per_day' => 0, 'tone' => 'check-in'],
            ['after_hours' => 60, 'texts_per_day' => 2, 'calls_per_day' => 0, 'tone' => 'concern'],
            ['after_hours' => 72, 'texts_per_day' => 1, 'calls_per_day' => 1, 'tone' => 'calling'],
        ],
        'settle' => ['texts_per_day' => 1, 'calls_per_day' => 0, 'tone' => 'daily hope you are ok'],
    ]);
}

it('has not noticed before the notice time', function () {
    expect(sisterLike()->stageAt(35.9, 6))->toBe(0)
        ->and(sisterLike()->at(10, 6))->toBeNull();
});

it('moves through stages as the silence goes on', function (float $hours, int $stage) {
    expect(sisterLike()->stageAt($hours, 6))->toBe($stage);
})->with([[36, 1], [59, 1], [60, 2], [71.5, 2], [72, 3], [500, 3]]);

it('never goes past the plan\'s cap', function () {
    expect(sisterLike()->stageAt(100, 2))->toBe(2)
        ->and(sisterLike()->stageAt(100, 0))->toBe(0)
        ->and(sisterLike()->at(70, 1)['tone'])->toBe('check-in')
        // Held at stage 1 (reached at 36h), they settle two days later rather than escalating.
        ->and(sisterLike()->at(100, 1)['settled'])->toBeTrue();
});

it('says what they do at each point, then settles after two days at the top stage', function () {
    expect(sisterLike()->at(61, 6))->toBe(['stage' => 2, 'settled' => false, 'texts_per_day' => 2, 'calls_per_day' => 0, 'tone' => 'concern'])
        ->and(sisterLike()->at(73, 6)['calls_per_day'])->toBe(1)
        ->and(sisterLike()->at(72 + WorryProfile::SETTLE_AFTER_HOURS, 6))
        ->toBe(['stage' => 3, 'settled' => true, 'texts_per_day' => 1, 'calls_per_day' => 0, 'tone' => 'daily hope you are ok']);
});

it('rejects broken profiles', function (array $data) {
    WorryProfile::fromArray($data);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [[]],
    'no stages' => [['notice_after_hours' => 1, 'stages' => [], 'settle' => ['texts_per_day' => 0, 'calls_per_day' => 0, 'tone' => 'x']]],
    'stages out of order' => [['notice_after_hours' => 1, 'settle' => ['texts_per_day' => 0, 'calls_per_day' => 0, 'tone' => 'x'], 'stages' => [
        ['after_hours' => 5, 'texts_per_day' => 1, 'calls_per_day' => 0, 'tone' => 'a'],
        ['after_hours' => 5, 'texts_per_day' => 1, 'calls_per_day' => 0, 'tone' => 'b'],
    ]]],
    'negative cadence' => [['notice_after_hours' => 1, 'settle' => ['texts_per_day' => 0, 'calls_per_day' => 0, 'tone' => 'x'], 'stages' => [
        ['after_hours' => 5, 'texts_per_day' => -1, 'calls_per_day' => 0, 'tone' => 'a'],
    ]]],
    'no tone' => [['notice_after_hours' => 1, 'settle' => ['texts_per_day' => 0, 'calls_per_day' => 0], 'stages' => [
        ['after_hours' => 5, 'texts_per_day' => 1, 'calls_per_day' => 0, 'tone' => 'a'],
    ]]],
]);
