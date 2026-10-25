<?php

use App\Enums\Metric;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Phonebook;
use Livewire\Volt\Volt;

// The chat page: sending, live updates, read receipts, retry, and unread badges.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman']);
    $this->entry = app(Phonebook::class)->add($this->user, $this->maya, Relationship::bySlug('sister'));
});

function chat()
{
    return Volt::actingAs(test()->user)->test('app.chat', ['contact' => test()->maya]);
}

it('opens the thread for a contact on the phone, and nobody else\'s', function () {
    $this->actingAs($this->user)->get(route('app.chat', $this->maya))
        ->assertOk()->assertSee('Maya')->assertSee('Sister')->assertSee('Say hi to Maya.')->assertSee('50 texts left today');

    $this->actingAs(User::factory()->create())->get(route('app.chat', $this->maya))->assertNotFound();

    app(Phonebook::class)->remove($this->user, $this->maya);
    $this->actingAs($this->user)->get(route('app.chat', $this->maya))->assertNotFound();
});

it('sends a text and shows the reply', function () {
    $this->llm->reply('Hey you! 💕');

    chat()->set('body', 'Hi sis')->call('send')
        ->assertHasNoErrors()
        ->assertSet('body', '')
        ->assertDispatched('text-sent')
        ->assertSee('Hi sis')
        ->assertSee('Hey you! 💕')
        ->assertSee('49 texts left today');
});

it('shows why a text could not be sent', function () {
    app(Entitlements::class)->consume($this->user, Metric::TextMessages, 50);

    chat()->set('body', 'Hello')->call('send')->assertHasErrors('body')->assertSee('used today');
    chat()->set('body', '   ')->call('send')->assertHasErrors('body');
});

it('says "1 text left" in the singular', function () {
    app(Entitlements::class)->consume($this->user, Metric::TextMessages, 49);

    chat()->assertSee('1 text left today');
});

it('listens on the user\'s private channel for messages, typing and failures', function () {
    $channel = 'echo-private:App.Models.User.'.$this->user->id;

    expect(chat()->instance()->getListeners())->toBe([
        "{$channel},.message" => 'onMessage',
        "{$channel},.typing" => 'onTyping',
        "{$channel},.reply-failed" => 'onReplyFailed',
    ]);
});

it('shows typing for this contact only, and stops when they answer or fail', function () {
    chat()
        ->call('onTyping', ['user_contact_id' => 999])->assertSet('typing', false)
        ->call('onTyping', ['user_contact_id' => $this->entry->id])->assertSet('typing', true)->assertSeeHtml('data-typing')
        ->call('onMessage', ['user_contact_id' => 999])->assertSet('typing', true)
        ->call('onMessage', ['user_contact_id' => $this->entry->id])->assertSet('typing', false)
        ->call('onTyping', ['user_contact_id' => $this->entry->id])
        ->call('onReplyFailed', ['user_contact_id' => 999])->assertSet('typing', true)
        ->call('onReplyFailed', ['user_contact_id' => $this->entry->id])->assertSet('typing', false);
});

it('marks texts as read when the thread opens or a new one arrives', function () {
    $this->entry->messages()->create(['sender' => 'contact', 'body' => 'You up?']);
    $page = chat();
    expect(Message::whereNull('read_at')->count())->toBe(0);

    $this->entry->messages()->create(['sender' => 'contact', 'body' => 'Hello??']);
    $page->call('onMessage', ['user_contact_id' => $this->entry->id]);
    expect(Message::whereNull('read_at')->count())->toBe(0);
});

it('offers to try again when the contact could not answer', function () {
    $failed = $this->entry->messages()->create(['sender' => 'user', 'body' => 'Hello?', 'meta' => ['reply_failed' => true]]);
    $this->llm->reply('Sorry! I\'m here.');

    chat()->assertSee('Maya couldn&#039;t answer. Try again', false)
        ->call('retry', $failed->id)
        ->assertSee('Sorry! I&#039;m here.', false);

    expect($failed->refresh()->replyFailed())->toBeFalse();
});

it('cannot retry someone else\'s or a contact\'s message', function () {
    $theirs = $this->entry->messages()->create(['sender' => 'contact', 'body' => 'Hi']);

    chat()->call('retry', $theirs->id)->assertStatus(404);
});

it('shows unread counts on My contacts and the home screen, linking to the thread', function () {
    $this->entry->messages()->create(['sender' => 'contact', 'body' => 'One']);
    $this->entry->messages()->create(['sender' => 'contact', 'body' => 'Two']);
    $this->entry->messages()->create(['sender' => 'user', 'body' => 'Mine']);

    $this->actingAs($this->user)->get(route('app.contacts'))->assertSee('2 new')->assertSee(route('app.chat', $this->maya));
    $this->actingAs($this->user)->get('/app')->assertSeeHtml('data-unread')->assertSee(route('app.chat', $this->maya));
});

it('has a Text button on the contact\'s page', function () {
    $this->actingAs($this->user)->get(route('app.catalog.show', $this->maya))->assertSee('Text Maya')->assertSee(route('app.chat', $this->maya));
});
