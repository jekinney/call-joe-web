<?php

use App\Models\User;
use App\Services\Presence;

// Online or not: the open page checks in, and the server decides from the last check-in.

it('records a check-in from the open page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('app.presence'))->assertNoContent();

    expect($user->refresh()->last_seen_at)->not->toBeNull()
        ->and(app(Presence::class)->isOnline($user))->toBeTrue();
});

it('only accepts check-ins from signed-in, verified adults', function () {
    $this->post(route('app.presence'))->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->post(route('app.presence'))->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->minor()->create())->post(route('app.presence'))->assertForbidden();
});

it('limits how often a page can check in', function () {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 10) as $i) {
        $this->post(route('app.presence'))->assertNoContent();
    }
    $this->post(route('app.presence'))->assertTooManyRequests();
});

it('does not write again for check-ins close together', function () {
    $user = User::factory()->create(['last_seen_at' => now()->subSeconds(10)]);
    $before = $user->last_seen_at->toIso8601String();

    app(Presence::class)->touch($user);

    expect($user->refresh()->last_seen_at->toIso8601String())->toBe($before);
});

it('writes again once the last check-in is older than the write interval', function () {
    $user = User::factory()->create(['last_seen_at' => now()->subSeconds(Presence::WRITE_EVERY_SECONDS + 1)]);

    app(Presence::class)->touch($user);

    expect($user->refresh()->last_seen_at->diffInSeconds(now()))->toBeLessThan(2);
});

it('counts as online until the check-ins stop', function () {
    $user = User::factory()->create();
    app(Presence::class)->touch($user);

    $this->travel(Presence::ONLINE_SECONDS - 5)->seconds();
    expect(app(Presence::class)->isOnline($user))->toBeTrue();

    $this->travel(10)->seconds();
    expect(app(Presence::class)->isOnline($user))->toBeFalse();
});

it('is offline before ever checking in', function () {
    expect(app(Presence::class)->isOnline(User::factory()->create()))->toBeFalse();
});

it('gives the page what it needs to check in and listen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/app')
        ->assertSee('<meta name="user-id" content="'.$user->id.'"', false)
        ->assertSee(route('app.presence'));
});

it('does not ask unverified users to check in', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/app/settings/profile')
        ->assertDontSee('name="presence-url"', false);
});
