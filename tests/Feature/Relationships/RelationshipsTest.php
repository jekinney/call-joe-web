<?php

use App\Models\Contact;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Phonebook;
use Livewire\Volt\Volt;

// Who a contact is to the user.

it('starts with family, friends and romantic relationships, and family is never romantic', function () {
    expect(Relationship::count())->toBe(15)
        ->and(Relationship::where('group', 'family')->where('allows_romance', true)->count())->toBe(0)
        ->and(Relationship::where('allows_romance', true)->pluck('slug')->sort()->values()->all())->toBe(['partner', 'spouse']);
});

it('offers only relationships that suit the contact', function () {
    $woman = Contact::factory()->create(['gender' => 'woman']);
    $man = Contact::factory()->create(['gender' => 'man']);

    $forWoman = Relationship::for($woman)->pluck('slug');
    $forMan = Relationship::for($man)->pluck('slug');

    expect($forWoman)->toContain('sister', 'mom', 'best-friend')->not->toContain('brother', 'dad')
        ->and($forMan)->toContain('brother', 'dad')->not->toContain('sister', 'mom')
        ->and($forWoman->first())->toBe('mom'); // in display order
});

it('fills in names in the relationship prompt', function () {
    expect(Relationship::bySlug('sister')->promptFor('Maya', 'Jamie'))->toStartWith("You are Jamie's sister.");
});

it('adds a contact as who you chose, or Friend by default', function () {
    $user = User::factory()->create();
    $maya = Contact::factory()->published()->create(['gender' => 'woman']);
    $joe = Contact::factory()->published()->create(['gender' => 'man']);

    $sister = app(Phonebook::class)->add($user, $maya, Relationship::bySlug('sister'));
    $friend = app(Phonebook::class)->add($user, $joe);

    expect($sister->relationship->slug)->toBe('sister')
        ->and($friend->relationship->slug)->toBe('friend');
});

it('refuses a relationship that does not suit the contact', function () {
    $joe = Contact::factory()->published()->create(['name' => 'Joe Tucker', 'gender' => 'man']);

    app(Phonebook::class)->add(User::factory()->create(), $joe, Relationship::bySlug('sister'));
})->throws(DomainException::class, "Joe can't be your sister.");

it('changes who a contact is, within what suits them', function () {
    $user = User::factory()->create();
    $maya = Contact::factory()->published()->create(['gender' => 'woman']);
    $entry = app(Phonebook::class)->add($user, $maya);

    app(Phonebook::class)->setRelationship($entry, Relationship::bySlug('best-friend'));
    expect($entry->refresh()->relationship->slug)->toBe('best-friend');

    expect(fn () => app(Phonebook::class)->setRelationship($entry, Relationship::bySlug('brother')))->toThrow(DomainException::class);
    expect($entry->refresh()->relationship->slug)->toBe('best-friend');
});

it('keeps or replaces the relationship when a removed contact comes back', function () {
    $user = User::factory()->create();
    $maya = Contact::factory()->published()->create(['gender' => 'woman']);
    app(Phonebook::class)->add($user, $maya, Relationship::bySlug('sister'));
    app(Phonebook::class)->remove($user, $maya);

    $again = app(Phonebook::class)->add($user, $maya, Relationship::bySlug('close-friend'));

    expect($again->refresh()->relationship->slug)->toBe('close-friend');
});

it('picks the relationship when adding from a contact\'s page, and changes it there', function () {
    $user = User::factory()->create();
    $maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman']);

    Volt::actingAs($user)->test('app.catalog.show', ['contact' => $maya])
        ->assertSee('Maya is my…')
        ->set('relationshipId', (string) Relationship::bySlug('sister')->id)
        ->call('add')
        ->assertHasNoErrors()
        ->set('relationshipId', (string) Relationship::bySlug('best-friend')->id)
        ->assertDispatched('relationship-saved');

    expect(app(Phonebook::class)->entry($user, $maya)->relationship->slug)->toBe('best-friend');
});

it('shows an error when changing to a relationship that does not suit them', function () {
    $user = User::factory()->create();
    $maya = Contact::factory()->published()->create(['gender' => 'woman']);
    app(Phonebook::class)->add($user, $maya, Relationship::bySlug('sister'));

    Volt::actingAs($user)->test('app.catalog.show', ['contact' => $maya])
        ->assertSet('relationshipId', (string) Relationship::bySlug('sister')->id)
        ->set('relationshipId', (string) Relationship::bySlug('brother')->id)
        ->assertHasErrors('relationshipId');
});

it('ignores a relationship change for a contact not on the phone', function () {
    $user = User::factory()->create();
    $maya = Contact::factory()->published()->create(['gender' => 'woman']);

    Volt::actingAs($user)->test('app.catalog.show', ['contact' => $maya])
        ->set('relationshipId', (string) Relationship::bySlug('sister')->id)
        ->assertNotDispatched('relationship-saved');

    expect(app(Phonebook::class)->entry($user, $maya))->toBeNull();
});

it('picks the relationship in the catalog, and can cancel', function () {
    $user = User::factory()->create();
    $maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman']);

    Volt::actingAs($user)->test('app.catalog')
        ->call('startAdding', $maya->id)
        ->assertSee('Add to my phone')
        ->call('cancelAdding')
        ->assertDontSee('Add to my phone')
        ->call('startAdding', $maya->id)
        ->set('relationshipId', (string) Relationship::bySlug('cousin')->id)
        ->call('add', $maya->id)
        ->assertHasNoErrors();

    expect(app(Phonebook::class)->entry($user, $maya)->relationship->slug)->toBe('cousin');
});

it('shows each contact\'s relationship in My contacts', function () {
    $user = User::factory()->create();
    app(Phonebook::class)->add($user, Contact::factory()->published()->create(['gender' => 'woman']), Relationship::bySlug('mom'));

    $this->actingAs($user)->get(route('app.contacts'))->assertSee('Mom ·');
});
