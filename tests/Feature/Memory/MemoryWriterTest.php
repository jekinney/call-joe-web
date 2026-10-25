<?php

use App\Models\Contact;
use App\Models\Llm;
use App\Models\Memory;
use App\Models\MemoryVersion;
use App\Models\Relationship;
use App\Models\User;
use App\Models\UserContact;
use App\Services\LlmGateway;
use App\Services\MemoryWriter;
use App\Services\Phonebook;
use App\Services\Texting;

// Each contact's notes about its user: written from texts, versioned, forgettable, and never shared.

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Jamie Rivera']);
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman']);
    $this->entry = app(Phonebook::class)->add($this->user, $this->maya, Relationship::bySlug('sister'));
});

function writer(): MemoryWriter
{
    return app(MemoryWriter::class);
}

function chatSays(UserContact $entry, string ...$lines): void
{
    foreach ($lines as $i => $line) {
        $entry->messages()->create(['sender' => $i % 2 === 0 ? 'user' : 'contact', 'body' => $line]);
    }
}

it('writes notes from new texts with the memory model, and remembers where it got to', function () {
    chatSays($this->entry, 'I got the job at the bakery!', 'No way, congrats!!');
    $this->llm->reply("- Got a job at a bakery.\n- Starts Monday.\nSome stray sentence");

    expect(writer()->update($this->entry))->toBeTrue();

    $memory = $this->entry->memory()->first();
    expect($memory->notes_md)->toBe("- Got a job at a bakery.\n- Starts Monday.\n- Some stray sentence")
        ->and($memory->version)->toBe(1)
        ->and($memory->last_message_id)->toBe($this->entry->messages()->max('id'))
        ->and($memory->versions()->first()->reason)->toBe('update');

    $call = $this->llm->calls[0];
    expect($call['model'])->toBe(Llm::where('purpose', 'memory')->value('model_id'))
        ->and($call['messages'][0]['content'])->toContain("Maya's private notes about Jamie")
        ->and($call['messages'][1]['content'])->toContain("Old notes:\n(none yet)")
        ->and($call['messages'][1]['content'])->toContain("Jamie: I got the job at the bakery!\nMaya: No way, congrats!!");
});

it('only reads texts newer than the last update, and keeps the old notes in view', function () {
    chatSays($this->entry, 'I have a dog named Biscuit');
    $this->llm->reply('- Has a dog named Biscuit.');
    writer()->update($this->entry);

    chatSays($this->entry, 'Biscuit ate my shoe');
    writer()->update($this->entry);

    $second = $this->llm->calls[1]['messages'][1]['content'];
    expect($second)->toContain("Old notes:\n- Has a dog named Biscuit.")
        ->and($second)->toContain('Biscuit ate my shoe')
        ->and($second)->not->toContain('I have a dog named Biscuit');
});

it('does nothing when there is nothing new', function () {
    expect(writer()->update($this->entry))->toBeFalse()->and($this->llm->calls)->toBe([]);
});

it('keeps the old notes when the AI fails', function () {
    chatSays($this->entry, 'Hello');
    $this->llm->reply('- Said hello.');
    writer()->update($this->entry);

    chatSays($this->entry, 'Big news!');
    Llm::query()->update(['fallback_id' => null]);
    app(LlmGateway::class)->forgetModels();
    $this->llm->failFor(Llm::where('purpose', 'memory')->value('model_id'));

    expect(writer()->update($this->entry))->toBeFalse()
        ->and($this->entry->memory()->first()->notes_md)->toBe('- Said hello.');
});

it('never lets the notes grow past the limit', function () {
    chatSays($this->entry, 'Long story');
    $this->llm->reply(implode("\n", array_fill(0, 200, '- '.str_repeat('x', 60))));

    writer()->update($this->entry);

    expect(mb_strlen($this->entry->memory()->first()->notes_md))->toBeLessThanOrEqual(MemoryWriter::MAX_CHARS);
});

it('forgets one note and never writes it back', function () {
    chatSays($this->entry, 'My ex is Chris');
    $this->llm->reply("- Ex is Chris.\n- Likes jazz.");
    writer()->update($this->entry);

    writer()->forget($this->entry, 0);

    $memory = $this->entry->memory()->first();
    expect($memory->lines())->toBe(['Likes jazz.'])
        ->and($memory->forgotten)->toBe(['Ex is Chris.'])
        ->and($memory->versions()->first()->reason)->toBe('forget');

    chatSays($this->entry, 'Anyway');
    writer()->update($this->entry);
    expect($this->llm->calls[1]['messages'][1]['content'])->toContain("asked you to forget these; never write them down again:\n- Ex is Chris.");
});

it('ignores forgetting a note that is not there', function () {
    writer()->forget($this->entry, 5);

    expect(MemoryVersion::count())->toBe(0);
});

it('forgets everything, without relearning old texts', function () {
    chatSays($this->entry, 'Secret stuff');
    $this->llm->reply('- Secret stuff.');
    writer()->update($this->entry);

    writer()->reset($this->entry);

    expect($this->entry->memory()->first()->notes_md)->toBe('')
        ->and(writer()->update($this->entry))->toBeFalse()
        ->and(MemoryVersion::orderByDesc('id')->first()->reason)->toBe('reset');
});

it('keeps every version', function () {
    chatSays($this->entry, 'One');
    $this->llm->reply('- One.');
    writer()->update($this->entry);
    writer()->forget($this->entry, 0);

    expect(Memory::first()->versions->pluck('reason')->all())->toBe(['forget', 'update'])
        ->and(Memory::first()->userContact->is($this->entry))->toBeTrue();
});

it('reads bullet lines in any common style', function () {
    expect((new Memory(['notes_md' => "- one\n* two\n• three\n\n  four  "]))->lines())->toBe(['one', 'two', 'three', 'four']);
});

it('puts a contact\'s notes in its own prompt, and never in another contact\'s', function () {
    $joe = Contact::factory()->published()->create(['name' => 'Joe Tucker', 'gender' => 'man']);
    $joeEntry = app(Phonebook::class)->add($this->user, $joe);
    writer()->memoryFor($this->entry)->update(['notes_md' => '- Told Maya a secret.']);

    expect(app(Texting::class)->conversationFor($this->entry)->prompt_text)->toContain('- Told Maya a secret.')
        ->and(app(Texting::class)->conversationFor($joeEntry)->prompt_text)->not->toContain('secret');
});

it('never shows one user\'s notes to another user of the same contact', function () {
    writer()->memoryFor($this->entry)->update(['notes_md' => '- Jamie\'s private thing.']);
    $sam = User::factory()->create(['name' => 'Sam Lee']);
    $samEntry = app(Phonebook::class)->add($sam, $this->maya);

    expect(app(Texting::class)->conversationFor($samEntry)->prompt_text)->not->toContain('private thing');
});
