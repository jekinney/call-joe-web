<?php

use App\Enums\Metric;
use App\Models\Call;
use App\Models\Contact;
use App\Models\User;
use App\Services\Calling;
use App\Services\Entitlements;
use App\Services\Phonebook;
use Livewire\Volt\Volt;

// The call screen.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman']);
    $this->entry = app(Phonebook::class)->add($this->user, $this->maya);
});

function callPage()
{
    return Volt::actingAs(test()->user)->test('app.call', ['contact' => test()->maya]);
}

it('opens for a contact on the phone only', function () {
    $this->actingAs($this->user)->get(route('app.call', $this->maya))->assertOk()->assertSee('Maya')->assertSee('10 minutes of calls left this month');
    $this->actingAs(User::factory()->create())->get(route('app.call', $this->maya))->assertNotFound();
});

it('starts a call and hands the browser only the public key and the call\'s assistant', function () {
    $page = callPage()->call('start')->assertSet('state', 'connecting')->assertSee('Calling…');

    $call = Call::sole();
    $page->assertDispatched('vapi-start', publicKey: 'pk_fake', assistantId: $call->vapi_assistant_id, callId: $call->id);
});

it('follows the call as it connects and ends', function () {
    callPage()->call('start')
        ->dispatch('vapi-connected', vapiCallId: 'call_1')->assertSet('state', 'active')->assertSee('On the call')->assertSee('Hang up')
        ->call('hangUp')->assertDispatched('vapi-stop')
        ->dispatch('vapi-ended')->assertSet('state', 'ended')->assertSee('Call ended')->assertSee('Call again');

    expect(Call::sole()->status)->toBe(Call::ENDED)->and(Call::sole()->vapi_call_id)->toBe('call_1');
});

it('explains a dropped call', function () {
    callPage()->call('start')->dispatch('vapi-error', message: 'mic blocked')
        ->assertSet('state', 'idle')->assertSee('The call dropped.');

    expect(Call::sole()->status)->toBe(Call::ENDED);
});

it('shows why a call cannot start', function () {
    app(Entitlements::class)->consume($this->user, Metric::VoiceSeconds, 590);

    callPage()->call('start')->assertSet('state', 'idle')->assertSee('used this month&#039;s call time', false)->assertNotDispatched('vapi-start');
});

it('ignores call events before a call has started', function () {
    callPage()->dispatch('vapi-connected')->dispatch('vapi-ended')->assertSet('state', 'ended');

    expect(Call::count())->toBe(0);
});

it('lists recent calls with their length', function () {
    config(['services.vapi.webhook_secret' => 'shh']);
    $call = app(Calling::class)->start($this->user, $this->entry);
    app(Calling::class)->handleReport(['call' => ['assistantId' => $call->vapi_assistant_id], 'durationSeconds' => 130]);

    callPage()->assertSee('Recent calls')->assertSee('2 min');
});

it('says "1 minute" in the singular', function () {
    app(Entitlements::class)->consume($this->user, Metric::VoiceSeconds, 540);

    callPage()->assertSee('1 minute of calls left this month');
});

it('has Call buttons on the chat and the contact\'s page', function () {
    $this->actingAs($this->user)->get(route('app.chat', $this->maya))->assertSee(route('app.call', $this->maya));
    $this->actingAs($this->user)->get(route('app.catalog.show', $this->maya))->assertSee(route('app.call', $this->maya));
});
