<?php

use App\Models\Contact;
use App\Models\User;
use App\Services\MemoryWriter;
use App\Services\Phonebook;
use Livewire\Volt\Volt;

// "What Maya remembers": see the notes, forget one, or forget everything.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman']);
    $this->entry = app(Phonebook::class)->add($this->user, $this->maya);
    $memory = app(MemoryWriter::class)->memoryFor($this->entry);
    $memory->update(['notes_md' => "- Has a dog named Biscuit.\n- Ex is Chris."]);
});

function memoryPage()
{
    return Volt::actingAs(test()->user)->test('app.memory', ['contact' => test()->maya]);
}

it('shows what the contact remembers, only to that user', function () {
    $this->actingAs($this->user)->get(route('app.memory', $this->maya))
        ->assertOk()->assertSee('What Maya remembers')->assertSee('Has a dog named Biscuit.')->assertSee('Last updated');

    $this->actingAs(User::factory()->create())->get(route('app.memory', $this->maya))->assertNotFound();
});

it('forgets one note', function () {
    memoryPage()->call('forget', 1)->assertDispatched('forgotten')->assertDontSee('Ex is Chris.')->assertSee('Biscuit');
});

it('forgets everything only after two steps', function () {
    memoryPage()
        ->assertSee('Forget everything')
        ->call('$set', 'confirmingReset', true)
        ->assertSee('will forget everything about you')
        ->call('$set', 'confirmingReset', false)
        ->assertSee('Biscuit')
        ->call('$set', 'confirmingReset', true)
        ->call('forgetEverything')
        ->assertSet('confirmingReset', false)
        ->assertSee('doesn&#039;t remember anything about you yet', false);
});

it('explains when nothing is remembered yet', function () {
    $joe = Contact::factory()->published()->create(['name' => 'Joe Tucker']);
    app(Phonebook::class)->add($this->user, $joe);

    $this->actingAs($this->user)->get(route('app.memory', $joe))
        ->assertSee('Joe doesn&#039;t remember anything about you yet', false)->assertDontSee('Forget everything');
});

it('is linked from the chat and the contact\'s page', function () {
    $this->actingAs($this->user)->get(route('app.chat', $this->maya))->assertSee(route('app.memory', $this->maya));
    $this->actingAs($this->user)->get(route('app.catalog.show', $this->maya))->assertSee(route('app.memory', $this->maya));
});
