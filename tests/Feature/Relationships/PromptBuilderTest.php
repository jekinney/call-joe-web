<?php

use App\Models\Contact;
use App\Models\PromptRule;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Phonebook;
use App\Services\PromptBuilder;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

// The system prompt: always the same order, the same text for the same inputs.

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Jamie Rivera', 'timezone' => 'America/Chicago']);
    $this->maya = Contact::factory()->published()->create([
        'name' => 'Maya Brooks', 'gender' => 'woman', 'persona_md' => "# Who you are\nYou're Maya Brooks, an ER nurse.",
    ]);
    $this->at = Carbon::parse('2026-10-25 22:15', 'UTC');
});

function entryAs(string $relationship)
{
    return app(Phonebook::class)->add(test()->user, test()->maya, Relationship::bySlug($relationship));
}

it('builds the sections in a fixed order', function () {
    $snapshot = app(PromptBuilder::class)->build(entryAs('sister'), null, $this->at);

    expect(array_keys($snapshot->sections))->toBe(['rules', 'persona', 'relationship', 'buddy', 'memory', 'now'])
        ->and($snapshot->text)->toStartWith('# Rules you always follow')
        ->and($snapshot->text)->toContain("# Who you are\nYou're Maya Brooks")
        ->and($snapshot->sections['relationship'])->toContain("You are Jamie's sister.")
        ->and($snapshot->sections['buddy'])->toContain('They go by Jamie (full name: Jamie Rivera)')
        ->and($snapshot->sections['buddy'])->toContain('America/Chicago')
        ->and($snapshot->sections['now'])->toBe("# Right now\nIt's Sunday, October 25, 2026, 5:15 PM where Jamie is.");
});

it('includes every rule, in order', function () {
    $rules = app(PromptBuilder::class)->build(entryAs('friend'), null, $this->at)->sections['rules'];

    $titles = PromptRule::orderBy('sort')->pluck('title')->map(fn ($t) => "## {$t}")->all();
    expect($rules)->toContain(...$titles)
        ->and(strpos($rules, $titles[0]))->toBeLessThan(strpos($rules, $titles[1]))
        ->and($rules)->toContain('988');
});

it('gives the same text and hash for the same inputs', function () {
    $entry = entryAs('friend');

    $a = app(PromptBuilder::class)->build($entry, 'Likes hiking.', $this->at);
    $b = app(PromptBuilder::class)->build($entry->fresh(), 'Likes hiking.', $this->at);

    expect($a->text)->toBe($b->text)->and($a->hash)->toBe($b->hash)->and($a->hash)->toHaveLength(64);
});

it('changes the hash when the memory, relationship or persona changes', function () {
    $entry = entryAs('friend');
    $base = app(PromptBuilder::class)->build($entry, 'Likes hiking.', $this->at)->hash;

    expect(app(PromptBuilder::class)->build($entry, 'Likes fishing.', $this->at)->hash)->not->toBe($base);

    app(Phonebook::class)->setRelationship($entry, Relationship::bySlug('cousin'));
    expect(app(PromptBuilder::class)->build($entry, 'Likes hiking.', $this->at)->hash)->not->toBe($base);
});

it('allows romance only in romantic relationships', function () {
    $friend = app(PromptBuilder::class)->build(entryAs('friend'), null, $this->at)->sections['relationship'];
    expect($friend)->toContain('Never flirt or be romantic with Jamie.')->not->toContain('Romance is part of');

    app(Phonebook::class)->remove($this->user, $this->maya);
    $partner = app(PromptBuilder::class)->build(entryAs('partner'), null, $this->at)->sections['relationship'];
    expect($partner)->toContain('Romance is part of this relationship')->not->toContain('Never flirt');
});

it('never puts romance in a family prompt', function () {
    Relationship::where('group', 'family')->get()->each(function (Relationship $family) {
        expect($family->allows_romance)->toBeFalse()
            ->and($family->promptFor('Maya', 'Jamie'))->toContain('never romantic');
    });
});

it('uses the memory notes, or says they are just getting to know them', function () {
    $entry = entryAs('friend');

    expect(app(PromptBuilder::class)->build($entry, '- Has a dog named Biscuit.', $this->at)->sections['memory'])->toContain('Biscuit')
        ->and(app(PromptBuilder::class)->build($entry, '   ', $this->at)->sections['memory'])->toContain("You don't know much about Jamie yet");
});

it('treats an entry with no relationship as a friend', function () {
    $entry = entryAs('sister');
    $entry->forceFill(['relationship_id' => null])->save();

    expect(app(PromptBuilder::class)->build($entry->fresh(), null, $this->at)->sections['relationship'])->toContain('You are a friend of Jamie.');
});

it('builds the full prompt exactly as before', function () {
    expect(app(PromptBuilder::class)->build(entryAs('sister'), '- Has a dog named Biscuit.', $this->at)->text)->toMatchSnapshot();
});

it('previews a user\'s prompt in the admin area', function () {
    $entryAs = entryAs('sister');
    $admin = User::factory()->admin()->create();

    Volt::actingAs($admin)->test('admin.prompt-preview')
        ->set('email', 'nobody@example.com')->assertSee('No account with that email.')
        ->set('email', $this->user->email)
        ->assertSee('Maya Brooks (Sister)')
        ->assertSee('You are Jamie&#039;s sister.', false)
        ->assertSee('sha256');

    $loner = User::factory()->create(['name' => 'Lonely Lou']);
    Volt::actingAs($admin)->test('admin.prompt-preview')->set('email', $loner->email)->assertSee('Lonely Lou has no contacts yet.');

    $this->actingAs($this->user)->get(route('admin.prompt-preview'))->assertForbidden();
    expect($entryAs)->not->toBeNull();
});
