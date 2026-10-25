<?php

use App\Models\Contact;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserContact;
use App\Services\Entitlements;
use App\Services\Phonebook;
use Livewire\Volt\Volt;

// The catalog, a contact's page, My contacts, and the home screen.

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('shows only published contacts, with what you can do for each', function () {
    $paidPlan = Plan::where('slug', 'paid')->firstOrFail();
    $joe = Contact::factory()->published()->create(['name' => 'Joe Tucker']);
    Contact::factory()->published()->create(['name' => 'Savvy Lux', 'required_plan_id' => $paidPlan->id]);
    Contact::factory()->create(['name' => 'Draft Dan']);
    app(Phonebook::class)->add($this->user, $joe);

    $this->actingAs($this->user)->get(route('app.catalog'))
        ->assertOk()
        ->assertSee('Joe Tucker')->assertSee('On your phone')
        ->assertSee('Savvy Lux')->assertSee('Paid plan')
        ->assertDontSee('Draft Dan')
        ->assertSee('1 of 2 contacts on your phone');
});

it('searches, and can hide contacts the plan does not include', function () {
    Contact::factory()->published()->create(['name' => 'Joe Tucker', 'tagline' => 'Kansas mechanic']);
    Contact::factory()->published()->create(['name' => 'Maya Brooks', 'tagline' => 'ER nurse', 'required_plan_id' => Plan::where('slug', 'paid')->value('id')]);

    Volt::actingAs($this->user)->test('app.catalog')
        ->set('search', 'nurse')->assertSee('Maya Brooks')->assertDontSee('Joe Tucker')
        ->set('search', 'zzz')->assertSee('No one matches that yet.')
        ->set('search', '')->set('availableOnly', true)->assertSee('Joe Tucker')->assertDontSee('Maya Brooks');
});

it('adds from the catalog', function () {
    $joe = Contact::factory()->published()->create();

    Volt::actingAs($this->user)->test('app.catalog')
        ->call('add', $joe->id)
        ->assertHasNoErrors()
        ->assertDispatched('contact-added')
        ->assertSee('On your phone');

    expect(app(Phonebook::class)->count($this->user))->toBe(1);
});

it('shows why adding failed, and offers plans when full', function () {
    [$a, $b, $c] = Contact::factory()->published()->count(3)->create();
    app(Phonebook::class)->add($this->user, $a);
    app(Phonebook::class)->add($this->user, $b);

    Volt::actingAs($this->user)->test('app.catalog')
        ->assertSee('Your contacts are full')
        ->call('add', $c->id)
        ->assertHasErrors('add');
});

it('cannot add a draft by guessing its id', function () {
    $draft = Contact::factory()->create();

    Volt::actingAs($this->user)->test('app.catalog')->call('add', $draft->id)->assertStatus(404);
    expect(UserContact::count())->toBe(0);
});

it('shows a contact\'s page and adds or removes them there', function () {
    $joe = Contact::factory()->published()->create(['name' => 'Joe Tucker', 'tagline' => 'Kansas mechanic']);

    $this->actingAs($this->user)->get(route('app.catalog.show', $joe))
        ->assertOk()->assertSee('Joe Tucker')->assertSee('Kansas mechanic')->assertSee('Add Joe to my phone');

    Volt::actingAs($this->user)->test('app.catalog.show', ['contact' => $joe])
        ->call('add')->assertHasNoErrors()->assertDispatched('contact-added')->assertSee('Joe is on your phone.')
        ->call('remove')->assertSee('Add Joe to my phone');

    expect(app(Phonebook::class)->count($this->user))->toBe(0);
});

it('explains a locked contact and links to plans', function () {
    $paidOnly = Contact::factory()->published()->create(['required_plan_id' => Plan::where('slug', 'paid')->value('id')]);

    $this->actingAs($this->user)->get(route('app.catalog.show', $paidOnly))
        ->assertSee('part of a paid plan')->assertSee('Paid plan')->assertSee(route('app.plan'));
});

it('shows the reason when adding from a contact\'s page fails', function () {
    [$a, $b, $c] = Contact::factory()->published()->count(3)->create();
    app(Phonebook::class)->add($this->user, $a);
    app(Phonebook::class)->add($this->user, $b);

    Volt::actingAs($this->user)->test('app.catalog.show', ['contact' => $c])
        ->assertSee('contacts are full')
        ->call('add')
        ->assertHasErrors('add');
});

it('hides drafts and hidden contacts, except from people who already have them', function () {
    $joe = Contact::factory()->published()->create();
    app(Phonebook::class)->add($this->user, $joe);
    $joe->forceFill(['is_published' => false])->save();

    $this->actingAs($this->user)->get(route('app.catalog.show', $joe))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('app.catalog.show', $joe))->assertNotFound();
});

it('lists my contacts and removes one', function () {
    $joe = Contact::factory()->published()->create(['name' => 'Joe Tucker']);
    $entry = app(Phonebook::class)->add($this->user, $joe);

    $this->actingAs($this->user)->get(route('app.contacts'))->assertOk()->assertSee('Joe')->assertSee('1 of 2 on your phone');

    Volt::actingAs($this->user)->test('app.contacts')->call('remove', $entry->id)->assertSee('No one yet.');
    expect(app(Phonebook::class)->count($this->user))->toBe(0);
});

it('cannot remove someone else\'s contact', function () {
    $other = User::factory()->create();
    $entry = app(Phonebook::class)->add($other, Contact::factory()->published()->create());

    Volt::actingAs($this->user)->test('app.contacts')->call('remove', $entry->id)->assertStatus(404);
    expect($entry->refresh()->trashed())->toBeFalse();
});

it('shows contacts on the home screen, or the catalog when there are none', function () {
    $this->actingAs($this->user)->get('/app')->assertSee('Browse the catalog');

    app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['name' => 'Maya Brooks']));
    $this->actingAs($this->user)->get('/app')->assertSee('Maya')->assertDontSee('Browse the catalog');
});

it('says "no limit" counts for plans without a contact limit', function () {
    $plan = Plan::factory()->create(['limits' => ['contacts' => null]]);
    app(Entitlements::class)->assignPlan($this->user, $plan, null);

    $this->actingAs($this->user)->get(route('app.catalog'))->assertSee('0 contacts on your phone');
    $this->actingAs($this->user)->get(route('app.contacts'))->assertSee('0 on your phone');
});

it('is linked from the sidebar', function () {
    $this->actingAs($this->user)->get('/app')->assertSee(route('app.catalog'))->assertSee(route('app.contacts'));
});
