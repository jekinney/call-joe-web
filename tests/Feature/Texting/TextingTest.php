<?php

use App\Enums\Metric;
use App\Events\ContactMessaged;
use App\Events\ContactReplyFailed;
use App\Events\ContactTyping;
use App\Jobs\SendContactReply;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Llm;
use App\Models\Message;
use App\Models\Relationship;
use App\Models\User;
use App\Services\ContactCatalog;
use App\Services\Entitlements;
use App\Services\Phonebook;
use App\Services\Texting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// Sending texts, the daily limit, and the contact's reply.

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Jamie Rivera']);
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman']);
    $this->entry = app(Phonebook::class)->add($this->user, $this->maya, Relationship::bySlug('sister'));
});

function texting(): Texting
{
    return app(Texting::class);
}

it('sends a text, counts it, and asks for a reply in the background', function () {
    Queue::fake();

    $message = texting()->send($this->user, $this->entry, '  Hey sis! 😂  ');

    expect($message->body)->toBe('Hey sis! 😂')
        ->and($message->fromUser())->toBeTrue()
        ->and(app(Entitlements::class)->used($this->user, Metric::TextMessages))->toBe(1);
    Queue::assertPushed(SendContactReply::class, fn ($job) => $job->messageId === $message->id);
});

it('refuses empty or very long texts without counting them', function (string $body) {
    expect(fn () => texting()->send($this->user, $this->entry, $body))->toThrow(DomainException::class, '1 to 2000 characters')
        ->and(app(Entitlements::class)->used($this->user, Metric::TextMessages))->toBe(0);
})->with(['empty' => ['   '], 'too long' => [str_repeat('a', 2001)]]);

it('stops at the daily limit and says when it resets', function () {
    Queue::fake();
    app(Entitlements::class)->consume($this->user, Metric::TextMessages, 50);

    expect(fn () => texting()->send($this->user, $this->entry, 'one more?'))
        ->toThrow(DomainException::class, "You've used today's 50 texts. They reset at 12:00 AM.");
    expect(Message::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('replies with the frozen prompt and recent history, in bubbles, live', function () {
    Event::fake([ContactMessaged::class, ContactTyping::class]);
    $this->llm->reply("Hey you!\n\nHow was the interview?");

    texting()->send($this->user, $this->entry, 'Hi Maya');

    $call = $this->llm->calls[0];
    expect($call['messages'][0]['role'])->toBe('system')
        ->and($call['messages'][0]['content'])->toContain("You are Jamie's sister.")
        ->and(array_slice($call['messages'], 1))->toBe([['role' => 'user', 'content' => 'Hi Maya']]);

    $replies = Message::where('sender', 'contact')->orderBy('id')->pluck('body')->all();
    expect($replies)->toBe(['Hey you!', 'How was the interview?']);
    Event::assertDispatched(ContactTyping::class, fn ($e) => $e->userContactId === $this->entry->id && $e->user->is($this->user));
    Event::assertDispatchedTimes(ContactMessaged::class, 2);
});

it('includes earlier texts in order, as user and assistant turns', function () {
    texting()->send($this->user, $this->entry, 'First');   // reply: "Fake reply 1"
    texting()->send($this->user, $this->entry, 'Second');

    expect(collect($this->llm->calls[1]['messages'])->slice(1)->values()->all())->toBe([
        ['role' => 'user', 'content' => 'First'],
        ['role' => 'assistant', 'content' => 'Fake reply 1'],
        ['role' => 'user', 'content' => 'Second'],
    ]);
});

it('keeps the same prompt for a whole conversation, even if the persona changes', function () {
    texting()->send($this->user, $this->entry, 'Hi');
    app(ContactCatalog::class)->update($this->maya, ['persona_md' => '# A whole new Maya']);
    texting()->send($this->user, $this->entry, 'Still there?');

    expect(Conversation::count())->toBe(1)
        ->and($this->llm->calls[1]['messages'][0]['content'])->toBe($this->llm->calls[0]['messages'][0]['content'])
        ->and(Message::where('sender', 'user')->pluck('conversation_id')->unique()->all())->toBe([Conversation::first()->id]);
});

it('starts a new conversation with a fresh prompt after a long quiet spell', function () {
    texting()->send($this->user, $this->entry, 'Hi');
    app(ContactCatalog::class)->update($this->maya, ['persona_md' => '# A whole new Maya']);

    $this->travel(Texting::CONVERSATION_GAP_HOURS + 1)->hours();
    texting()->send($this->user, $this->entry, 'Morning!');

    expect(Conversation::count())->toBe(2)
        ->and($this->llm->calls[1]['messages'][0]['content'])->toContain('# A whole new Maya');
});

it('marks the text when the contact cannot answer, says so live, and retries without counting again', function () {
    Event::fake([ContactReplyFailed::class]);
    Llm::query()->update(['fallback_id' => null]);
    $this->llm->failAll();

    $message = texting()->send($this->user, $this->entry, 'Hello?');

    expect($message->refresh()->replyFailed())->toBeTrue()
        ->and(Message::where('sender', 'contact')->count())->toBe(0);
    Event::assertDispatched(ContactReplyFailed::class, fn ($e) => $e->message->is($message));

    Queue::fake();
    texting()->retry($message);

    expect($message->refresh()->replyFailed())->toBeFalse()
        ->and(app(Entitlements::class)->used($this->user, Metric::TextMessages))->toBe(1);
    Queue::assertPushed(SendContactReply::class);
});

it('splits replies into at most four bubbles', function () {
    expect(texting()->bubbles("a\n\nb\n\n\nc"))->toBe(['a', 'b', 'c'])
        ->and(texting()->bubbles("1\n\n2\n\n3\n\n4\n\n5"))->toBe(['1', '2', '3', "4\n\n5"])
        ->and(texting()->bubbles("one line\nsecond line"))->toBe(["one line\nsecond line"])
        ->and(texting()->bubbles('   '))->toBe(['…']);
});

it('does nothing for a text that no longer exists', function () {
    (new SendContactReply(999999))->handle(texting());

    expect($this->llm->calls)->toBe([]);
});

it('sends live events on the user\'s private channel', function () {
    $message = $this->entry->messages()->create(['sender' => 'contact', 'body' => 'Hey!']);

    $sent = new ContactMessaged($this->user, $message);
    $typing = new ContactTyping($this->user, $this->entry->id);
    $failed = new ContactReplyFailed($this->user, $message);

    $channel = 'private-App.Models.User.'.$this->user->id;
    expect($sent->broadcastOn()[0]->name)->toBe($channel)
        ->and($sent->broadcastAs())->toBe('message')
        ->and($sent->broadcastWith())->toMatchArray(['id' => $message->id, 'user_contact_id' => $this->entry->id, 'body' => 'Hey!'])
        ->and($typing->broadcastOn()[0]->name)->toBe($channel)
        ->and($typing->broadcastAs())->toBe('typing')
        ->and($typing->broadcastWith())->toBe(['user_contact_id' => $this->entry->id])
        ->and($failed->broadcastOn()[0]->name)->toBe($channel)
        ->and($failed->broadcastAs())->toBe('reply-failed')
        ->and($failed->broadcastWith())->toBe(['id' => $message->id, 'user_contact_id' => $this->entry->id]);
});

it('links messages and conversations back to the thread', function () {
    texting()->send($this->user, $this->entry, 'Hi');
    $conversation = Conversation::first();

    expect($conversation->userContact->is($this->entry))->toBeTrue()
        ->and($conversation->messages)->toHaveCount(2)
        ->and(Message::first()->userContact->is($this->entry))->toBeTrue();
});
