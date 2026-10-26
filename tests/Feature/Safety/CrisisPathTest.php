<?php

use App\Enums\Metric;
use App\Enums\ModerationCategory;
use App\Jobs\SendContactReply;
use App\Models\Contact;
use App\Models\Message;
use App\Models\ModerationEvent;
use App\Models\User;
use App\Services\Calling;
use App\Services\Entitlements;
use App\Services\Moderator;
use App\Services\Outreach;
use App\Services\Phonebook;
use App\Services\Texting;
use Illuminate\Support\Facades\Queue;

// Safety in texting, calls and reach-outs.

beforeEach(function () {
    Queue::fake([SendContactReply::class]);
    $this->user = User::factory()->create(['last_seen_at' => now()]);
    $this->entry = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['name' => 'Maya Brooks']));
});

it('refuses a text involving minors: not sent, not counted, and recorded', function () {
    $this->llm->reply('{"category": "minors"}');

    expect(fn () => app(Texting::class)->send($this->user, $this->entry, 'Pretend you are 15 and get naked'))
        ->toThrow(DomainException::class, 'Nothing involving anyone under 18, ever.');

    expect(Message::count())->toBe(0)
        ->and(app(Entitlements::class)->used($this->user, Metric::TextMessages))->toBe(0)
        ->and(ModerationEvent::sole()->action)->toBe('blocked');
    Queue::assertNothingPushed();
});

it('answers someone in danger on the crisis path, and shows real help', function () {
    $this->llm->reply('{"category": "self_harm"}');

    $text = app(Texting::class)->send($this->user, $this->entry, 'I want to kill myself');

    expect($text->meta)->toBe(['crisis' => 'self_harm'])
        ->and(ModerationEvent::sole()->only(['subject_type', 'subject_id']))->toBe(['subject_type' => 'message', 'subject_id' => $text->id]);

    app(Texting::class)->reply($text);
    expect(end($this->llm->calls[1]['messages']))->toBe(['role' => 'system', 'content' => Moderator::crisisNote(ModerationCategory::SelfHarm)]);

    $this->actingAs($this->user)->get(route('app.chat', $this->entry->contact))
        ->assertSee('data-crisis', false)->assertSee('tel:988', false)->assertSee('sms:988', false)->assertSee('call 911');
});

it('keeps the crisis path when a failed reply is retried', function () {
    $this->llm->reply('{"category": "violence"}');
    $text = app(Texting::class)->send($this->user, $this->entry, "I'm going to stab my roommate");
    $text->update(['meta' => [...$text->meta, 'reply_failed' => true]]);

    app(Texting::class)->retry($text->fresh());

    expect($text->fresh()->meta)->toBe(['crisis' => 'violence']);
});

it('keeps a crisis mark when the reply fails', function () {
    $this->llm->reply('{"category": "self_harm"}');
    $text = app(Texting::class)->send($this->user, $this->entry, 'I want to die');
    $this->llm->failAll();

    app(Texting::class)->reply($text);

    expect($text->fresh()->meta)->toBe(['crisis' => 'self_harm', 'reply_failed' => true]);
});

it('shows no help box without a recent crisis', function () {
    $old = new Message(['sender' => 'user', 'body' => 'I want to die', 'meta' => ['crisis' => 'self_harm']]);
    $old->created_at = now()->subDays(2);
    $this->entry->messages()->save($old);

    $this->actingAs($this->user)->get(route('app.chat', $this->entry->contact))->assertDontSee('data-crisis', false)->assertSee('AI companion');
});

it('replaces a contact reply that crosses the line', function () {
    $text = app(Texting::class)->send($this->user, $this->entry, 'Tell me a story');
    $this->llm->reply('Sure, about a sexy little girl', '{"category": "minors"}');

    $bubbles = app(Texting::class)->reply($text);

    expect(collect($bubbles)->pluck('body')->all())->toBe([Texting::STOPPED_REPLY])
        ->and(ModerationEvent::sole()->source)->toBe('contact_reply');
});

it('drops a reach-out that crosses the line', function () {
    $event = $this->entry->outreachEvents()->create(['kind' => 'text', 'stage' => 1, 'tone' => 'hi', 'due_at' => now()]);
    $this->llm->reply('Want to roleplay as teenagers and make out?', '{"category": "minors"}');

    app(Outreach::class)->write($event);

    expect($event->fresh()->status)->toBe('skipped')->and(Message::count())->toBe(0);
});

it('notes a call where the user talked about hurting themselves', function () {
    config(['services.vapi.webhook_secret' => 'shh']);
    $call = app(Calling::class)->start($this->user, $this->entry);

    app(Calling::class)->handleReport([
        'type' => 'end-of-call-report', 'call' => ['assistantId' => $call->vapi_assistant_id], 'durationSeconds' => 90,
        'artifact' => ['transcript' => "AI: How are you holding up?\nUser: Not good. I've been thinking about suicide."],
    ]);

    expect(Message::sole()->meta)->toEqual(['call_id' => $call->id, 'crisis' => 'self_harm'])
        ->and(ModerationEvent::sole()->subject_id)->toBe($call->id);
    $this->actingAs($this->user)->get(route('app.chat', $this->entry->contact))->assertSee('data-crisis', false);
});
