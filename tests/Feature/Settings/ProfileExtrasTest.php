<?php

use App\Models\User;
use Livewire\Volt\Volt;

// The profile page's Call Joe fields: what contacts call you, and your timezone.

it('shows the current display name and timezone', function () {
    $user = User::factory()->create(['display_name' => 'J', 'timezone' => 'America/Denver']);

    Volt::actingAs($user)->test('settings.profile')
        ->assertSet('display_name', 'J')
        ->assertSet('timezone', 'America/Denver');
});

it('saves a display name and timezone', function () {
    $user = User::factory()->create();

    Volt::actingAs($user)->test('settings.profile')
        ->set('display_name', 'Jay')
        ->set('timezone', 'Europe/London')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->display_name)->toBe('Jay')
        ->and($user->timezone)->toBe('Europe/London')
        ->and($user->preferredName())->toBe('Jay');
});

it('goes back to the first name when the display name is cleared', function () {
    $user = User::factory()->create(['name' => 'Jamie Rivera', 'display_name' => 'Jay']);

    Volt::actingAs($user)->test('settings.profile')
        ->set('display_name', '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->display_name)->toBeNull()
        ->and($user->preferredName())->toBe('Jamie');
});

it('rejects a bad timezone or a too-long display name', function () {
    Volt::actingAs(User::factory()->create())->test('settings.profile')
        ->set('timezone', 'Nowhere/Land')
        ->set('display_name', str_repeat('x', 61))
        ->call('updateProfileInformation')
        ->assertHasErrors(['timezone', 'display_name']);
});

it('never lets the birthday or admin flag be changed from the profile page', function () {
    $user = User::factory()->create(['date_of_birth' => '1990-05-01']);

    Volt::actingAs($user)->test('settings.profile')
        ->set('name', 'Jamie Rivera')
        ->call('updateProfileInformation');

    $user->refresh();
    expect($user->date_of_birth->toDateString())->toBe('1990-05-01')
        ->and($user->is_admin)->toBeFalse();
});
