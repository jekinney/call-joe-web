<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

it('sends an already verified user home without verifying again', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    Event::assertNotDispatched(Verified::class);
});
