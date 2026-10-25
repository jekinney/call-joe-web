<?php

use App\Models\User;

// Admin rights only come from the command line.

it('makes an account an admin', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('app:make-admin', ['email' => 'Boss@Example.com'])
        ->expectsOutput('boss@example.com is now an admin.')
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

it('takes admin rights away with --revoke', function () {
    $user = User::factory()->admin()->create(['email' => 'boss@example.com']);

    $this->artisan('app:make-admin', ['email' => 'boss@example.com', '--revoke' => true])
        ->expectsOutput('boss@example.com is no longer an admin.')
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeFalse();
});

it('fails for an email with no account', function () {
    $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])
        ->expectsOutput('No account with that email.')
        ->assertFailed();
});
