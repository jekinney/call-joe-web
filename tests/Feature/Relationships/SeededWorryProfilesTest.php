<?php

use App\Models\Relationship;

// Every seeded relationship has a valid worry profile, and they worry in a sensible order.

it('has a valid worry profile for every relationship', function () {
    Relationship::all()->each(fn (Relationship $r) => expect($r->worry())->toBeObject());
});

it('notices silence soonest for a partner, then a parent, and last for a casual friend', function () {
    $notice = fn (string $slug) => Relationship::bySlug($slug)->worry()->noticeAfterHours;

    expect($notice('partner'))->toBeLessThan($notice('mom'))
        ->and($notice('mom'))->toBeLessThan($notice('sister'))
        ->and($notice('sister'))->toBeLessThan($notice('best-friend'))
        ->and($notice('best-friend'))->toBeLessThan($notice('coworker'));
});

it('lets a mom escalate to calls and asking family on Paid, but only texts on Free', function () {
    $mom = Relationship::bySlug('mom')->worry();

    expect($mom->at(100, 6))->toMatchArray(['stage' => 5, 'calls_per_day' => 3])
        ->and($mom->at(100, 2))->toMatchArray(['stage' => 2, 'calls_per_day' => 0]);
});
