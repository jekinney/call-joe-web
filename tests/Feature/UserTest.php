<?php

use App\Models\User;
use Illuminate\Support\Carbon;

// The User model's age, name and safety rules.

it('works out age from the birthday, in the user\'s own timezone', function () {
    $this->travelTo(Carbon::parse('2026-06-15 03:00:00', 'UTC')); // still June 14 in Chicago

    $user = new User(['date_of_birth' => '2008-06-15', 'timezone' => 'America/Chicago']);
    expect($user->age())->toBe(17)->and($user->isAdult())->toBeFalse();

    $user->timezone = 'UTC';
    expect($user->age())->toBe(18)->and($user->isAdult())->toBeTrue();
});

it('has no age without a birthday, and is not treated as an adult', function () {
    $user = new User(['timezone' => 'UTC']);

    expect($user->age())->toBeNull()->and($user->isAdult())->toBeFalse();
});

it('uses the display name, or else the first name', function () {
    expect((new User(['name' => 'Jamie Rivera']))->preferredName())->toBe('Jamie')
        ->and((new User(['name' => 'Jamie Rivera', 'display_name' => 'J']))->preferredName())->toBe('J')
        ->and((new User(['name' => 'Cher']))->preferredName())->toBe('Cher');
});

it('never makes someone an admin through mass assignment', function () {
    $user = User::create([
        'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'password' => 'password',
        'date_of_birth' => '1990-01-01', 'is_admin' => true,
    ]);

    expect($user->refresh()->is_admin)->toBeFalse();
});

it('keeps the birthday out of serialized output', function () {
    expect(User::factory()->make()->toArray())->not->toHaveKey('date_of_birth');
});

it('gives initials from the full name', function () {
    expect((new User(['name' => 'Jamie Lee Rivera']))->initials())->toBe('JLR');
});
