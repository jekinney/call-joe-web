<?php

use App\Jobs\UpdateMemory;
use App\Models\Contact;
use App\Models\User;
use App\Services\MemoryWriter;
use App\Services\Phonebook;
use App\Services\Texting;
use Illuminate\Support\Facades\Queue;

// Notes update once texting goes quiet, never mid-conversation.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->entry = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create());
});

it('schedules an update after each reply, waiting for a quiet spell', function () {
    Queue::fake();
    $this->entry->messages()->create(['sender' => 'user', 'body' => 'Hi']);

    app(Texting::class)->reply($this->entry->messages()->first());

    Queue::assertPushed(UpdateMemory::class, fn (UpdateMemory $job) => $job->userContactId === $this->entry->id
        && $job->delay->diffInMinutes(now(), true) >= UpdateMemory::QUIET_MINUTES - 1);
});

it('keeps only one update waiting per contact', function () {
    expect((new UpdateMemory($this->entry->id))->uniqueId())->toBe((string) $this->entry->id);
});

it('waits again if texts are still coming, without updating', function () {
    $this->entry->messages()->create(['sender' => 'user', 'body' => 'Still here']);
    $job = (new UpdateMemory($this->entry->id))->withFakeQueueInteractions();

    $job->handle(app(MemoryWriter::class));

    $job->assertReleased(delay: UpdateMemory::QUIET_MINUTES * 60);
    expect($this->llm->calls)->toBe([]);
});

it('updates once it has been quiet long enough', function () {
    $this->entry->messages()->create(['sender' => 'user', 'body' => 'Bye for now']);
    $this->llm->reply('- Said bye.');
    $this->travel(UpdateMemory::QUIET_MINUTES + 1)->minutes();

    $job = (new UpdateMemory($this->entry->id))->withFakeQueueInteractions();
    $job->handle(app(MemoryWriter::class));

    $job->assertNotReleased();
    expect($this->entry->memory()->first()->notes_md)->toBe('- Said bye.');
});

it('still updates for a contact that was removed (their memory is kept)', function () {
    $this->entry->messages()->create(['sender' => 'user', 'body' => 'Bye']);
    $this->entry->delete();
    $this->travel(UpdateMemory::QUIET_MINUTES + 1)->minutes();

    (new UpdateMemory($this->entry->id))->handle(app(MemoryWriter::class));

    expect($this->entry->memory()->first())->not->toBeNull();
});

it('does nothing for a contact entry that no longer exists', function () {
    (new UpdateMemory(999999))->handle(app(MemoryWriter::class));

    expect($this->llm->calls)->toBe([]);
});
