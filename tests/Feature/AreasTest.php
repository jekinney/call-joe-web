<?php

use App\Models\User;

// Who can reach /app (signed in, verified, adult) and /admin (admins), and where "home" sends them.

it('sends guests to the login page from every area', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/home', '/app', '/admin', '/app/settings/profile']);

it('lets a verified adult into /app', function () {
    $this->actingAs(User::factory()->create(['name' => 'Jamie Rivera']))
        ->get('/app')
        ->assertOk()
        ->assertSee('Hi, Jamie');
});

it('greets them by the name they chose', function () {
    $this->actingAs(User::factory()->create(['name' => 'Jamie Rivera', 'display_name' => 'J']))
        ->get('/app')
        ->assertSee('Hi, J');
});

it('asks unverified users to verify their email first', function (string $url) {
    $this->actingAs(User::factory()->unverified()->create())
        ->get($url)
        ->assertRedirect(route('verification.notice'));
})->with(['/app', '/admin', '/home']);

it('keeps settings open before verifying, so a mistyped email can be fixed', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/app/settings/profile')
        ->assertOk();
});

it('blocks anyone under 18, or with no birthday on file, from /app', function (User $user) {
    $this->actingAs($user)->get('/app')->assertForbidden()->assertSee('only for adults');
})->with([
    'under 18' => fn () => User::factory()->minor()->create(),
    'no birthday' => fn () => User::factory()->withoutBirthDate()->create(),
]);

it('keeps regular users out of /admin', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('lets admins into /admin and /app', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Admin');
    $this->actingAs($admin)->get('/app')->assertOk()->assertSee(route('admin.home'));
});

it('shows the Admin link only to admins', function () {
    $this->actingAs(User::factory()->create())->get('/app')->assertDontSee(route('admin.home'));
});

it('sends users home to /app and admins to /admin after login', function () {
    $this->actingAs(User::factory()->create())->get('/home')->assertRedirect(route('app.home'));
    $this->actingAs(User::factory()->admin()->create())->get('/home')->assertRedirect(route('admin.home'));
});

it('sends /app/settings to the profile page', function () {
    $this->actingAs(User::factory()->create())->get('/app/settings')->assertRedirect('/app/settings/profile');
});
