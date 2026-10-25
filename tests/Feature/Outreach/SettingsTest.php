<?php

use App\Models\Contact;
use App\Models\Message;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Phonebook;
use App\Services\PromptBuilder;
use Carbon\CarbonImmutable;
use Livewire\Volt\Volt;

// What the user controls: away mode and each contact's worry; and how reach-outs look in the thread.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00', 'UTC'));
    $this->user = User::factory()->create(['timezone' => 'America/Chicago', 'last_seen_at' => now()]);
    $this->entry = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['name' => 'Linda Rivera', 'gender' => 'woman']), Relationship::bySlug('mom'));
});

it('turns away mode on through the end of the chosen day, and off again', function () {
    $this->actingAs($this->user)->get(route('settings.away'))->assertOk()->assertSee('Away mode')->assertDontSee('data-away', false);

    Volt::actingAs($this->user)->test('settings.away')
        ->set('until', '2026-11-01')->set('note', ' Camping up north ')
        ->call('save')->assertHasNoErrors()->assertDispatched('away-saved');

    $user = $this->user->fresh();
    expect($user->away_until->setTimezone('America/Chicago')->format('Y-m-d H:i:s'))->toBe('2026-11-01 23:59:59')
        ->and($user->away_note)->toBe('Camping up north')
        ->and($user->isAway())->toBeTrue();

    Volt::actingAs($user)->test('settings.away')
        ->assertSet('until', '2026-11-01')->assertSet('note', 'Camping up north')
        ->assertSee("You're away until Sunday, Nov 1.")
        ->call('back')->assertSet('until', '');
    expect($user->fresh()->away_until)->toBeNull()->and($user->fresh()->isAway())->toBeFalse();
});

it('needs a day from today to 60 days out', function (string $until) {
    Volt::actingAs($this->user)->test('settings.away')->set('until', $until)->call('save')->assertHasErrors('until');
})->with(['yesterday' => ['2026-10-27'], 'too far' => ['2027-01-15'], 'not a date' => ['soon']]);

it('tells contacts about the trip while it lasts, and to ask about it for a week after', function () {
    $prompt = fn () => app(PromptBuilder::class)->build($this->entry->fresh())->text;
    expect($prompt())->not->toContain('away');

    $this->user->forceFill(['away_until' => now()->addDays(3), 'away_note' => 'Camping up north'])->save();
    expect($prompt())->toContain("They told you they're away until Saturday, October 31 (Camping up north). Don't worry about them");

    $this->travel(5)->days();
    expect($prompt())->toContain('They were just away (Camping up north). Ask how it went.');

    $this->travel(7)->days();
    expect($prompt())->not->toContain('just away');

    $this->user->forceFill(['away_until' => now()->addDay(), 'away_note' => null])->save();
    expect($prompt())->toContain("away until Tuesday, November 10. Don't worry");
});

it('lets the user choose how much each contact worries', function () {
    $this->actingAs($this->user)->get(route('app.contacts'))->assertSee('Checks on you less')->assertSee('Doesn&#039;t worry', false);

    Volt::actingAs($this->user)->test('app.contacts')->call('setWorry', $this->entry->id, 'chill');
    expect($this->entry->fresh()->worry_setting)->toBe('chill');

    Volt::actingAs($this->user)->test('app.contacts')->call('setWorry', $this->entry->id, 'panic')->assertStatus(422);

    $stranger = User::factory()->create();
    Volt::actingAs($stranger)->test('app.contacts')->call('setWorry', $this->entry->id, 'off')->assertNotFound();
    expect($this->entry->fresh()->worry_setting)->toBe('chill');
});

it('shows what a contact sent on their own with its time, and marks voicemails', function () {
    $sent = function (string $body, array $meta, int $hoursAgo) {
        $message = new Message(['sender' => 'contact', 'body' => $body, 'meta' => $meta]);
        $message->created_at = now()->subHours($hoursAgo);
        $this->entry->messages()->save($message);
    };
    $this->entry->messages()->create(['sender' => 'user', 'body' => 'Back now!']);
    $sent('Hope you are ok', ['outreach' => 1], 5);
    $sent('Call me back, sweetie', ['voicemail' => true, 'call_id' => 1], 3);

    $this->actingAs($this->user)->get(route('app.chat', $this->entry->contact))
        ->assertSeeInOrder(['Hope you are ok', 'Wed 5:00 AM', 'Voicemail', 'Call me back, sweetie', 'Back now!'])
        ->assertSee('data-voicemail', false);
});

it('lists away mode in the settings menu', function () {
    $this->actingAs($this->user)->get(route('settings.profile'))->assertSee(route('settings.away'));
});
