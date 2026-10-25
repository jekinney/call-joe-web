<?php

use App\Events\NoticeSent;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

// Private channels and live events. Tests use the Reverb broadcaster for authorization only; nothing connects.

beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php'); // register the channels on the Reverb broadcaster
});

function authorizeChannel(string $channel)
{
    return test()->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('lets users listen on their own private channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    authorizeChannel('private-'.NoticeSent::channelFor($user))->assertOk()->assertJsonStructure(['auth']);
});

it('never lets anyone listen on someone else\'s channel', function () {
    $me = User::factory()->create();
    $someoneElse = User::factory()->create();

    $this->actingAs($me);
    authorizeChannel('private-'.NoticeSent::channelFor($someoneElse))->assertForbidden();
});

it('refuses guests', function () {
    authorizeChannel('private-App.Models.User.1')->assertForbidden();
});

it('sends a notice on the user\'s private channel, as "notice", from the queue', function () {
    $user = User::factory()->create();
    $event = new NoticeSent($user, 'Elena texted you');

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and(collect($event->broadcastOn())->map->name->all())->toBe(['private-App.Models.User.'.$user->id])
        ->and($event->broadcastAs())->toBe('notice')
        ->and($event->broadcastWith())->toMatchArray(['message' => 'Elena texted you'])
        ->and($event->broadcastWith())->toHaveKey('at');
});

it('sends a notice from the command line', function () {
    Event::fake([NoticeSent::class]);
    $user = User::factory()->create(['email' => 'jamie@example.com']);

    $this->artisan('app:notify', ['email' => 'Jamie@Example.com', 'message' => 'Hello from the server'])
        ->expectsOutput('Sent to jamie@example.com.')
        ->assertSuccessful();

    Event::assertDispatched(NoticeSent::class, fn (NoticeSent $e) => $e->user->is($user) && $e->message === 'Hello from the server');
});

it('fails to notify an email with no account', function () {
    $this->artisan('app:notify', ['email' => 'nobody@example.com', 'message' => 'hi'])
        ->expectsOutput('No account with that email.')
        ->assertFailed();
});
