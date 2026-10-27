<?php

use App\Models\Contact;
use App\Models\User;
use App\Models\UserContact;
use App\Services\Calling;
use App\Services\Texting;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

// Browser protections and limits on things that cost money or can be abused.

it('sends browser protection headers', function () {
    $response = $this->get('/')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Permissions-Policy'))->toContain('microphone=(self)')
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('adds HSTS over https only', function () {
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
});

it('limits texts sent in a minute', function () {
    $user = User::factory()->create();
    $entry = UserContact::create(['user_id' => $user->id, 'contact_id' => Contact::factory()->create()->id, 'is_active' => true, 'added_at' => now()]);
    RateLimiter::clear("text:{$user->id}");
    for ($i = 0; $i < Texting::BURST_PER_MINUTE; $i++) {
        RateLimiter::hit("text:{$user->id}", 60);
    }

    expect(fn () => app(Texting::class)->send($user, $entry, 'hi'))
        ->toThrow(DomainException::class, 'Slow down');
});

it('limits calls started in a minute', function () {
    $user = User::factory()->create();
    $entry = UserContact::create(['user_id' => $user->id, 'contact_id' => Contact::factory()->create(['voice' => 'Puck'])->id, 'is_active' => true, 'added_at' => now()]);
    for ($i = 0; $i < Calling::STARTS_PER_MINUTE; $i++) {
        RateLimiter::hit("call:{$user->id}", 60);
    }

    expect(fn () => app(Calling::class)->start($user, $entry))
        ->toThrow(DomainException::class, 'Too many calls');
});

it('limits sign-ups from one address', function () {
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit('register:127.0.0.1', 3600);
    }

    Volt::test('auth.register')->call('register')->assertHasErrors(['email']);
});

it('stops sending reset links after five from one address', function () {
    Notification::fake();
    $user = User::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit('reset:127.0.0.1', 3600);
    }

    Volt::test('auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink');

    Notification::assertNothingSent();
});

it('sends a reset link while under the limit', function () {
    Notification::fake();
    $user = User::factory()->create();

    Volt::test('auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink');

    Notification::assertSentTo($user, ResetPassword::class);
});
