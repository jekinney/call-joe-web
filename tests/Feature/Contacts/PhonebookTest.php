<?php

use App\Enums\AddCheck;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserContact;
use App\Services\Entitlements;
use App\Services\Phonebook;

// Adding and removing contacts on a user's phone.

function phonebook(): Phonebook
{
    return app(Phonebook::class);
}

function paidUser(): User
{
    $user = User::factory()->create();
    app(Entitlements::class)->assignPlan($user, Plan::where('slug', 'paid')->firstOrFail(), null);

    return $user;
}

function paidOnlyContact(): Contact
{
    return Contact::factory()->published()->create(['required_plan_id' => Plan::where('slug', 'paid')->value('id')]);
}

it('adds a published contact', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->published()->create();

    $entry = phonebook()->add($user, $contact);

    expect($entry->contact->is($contact))->toBeTrue()
        ->and($entry->user->is($user))->toBeTrue()
        ->and(phonebook()->count($user))->toBe(1)
        ->and(phonebook()->check($user, $contact))->toBe(AddCheck::AlreadyAdded);
});

it('stops at the plan\'s contact limit (Free: 2)', function () {
    $user = User::factory()->create();
    [$a, $b, $c] = Contact::factory()->published()->count(3)->create();

    phonebook()->add($user, $a);
    phonebook()->add($user, $b);

    expect(phonebook()->check($user, $c))->toBe(AddCheck::LimitReached)
        ->and(fn () => phonebook()->add($user, $c))->toThrow(DomainException::class, AddCheck::LimitReached->message())
        ->and(phonebook()->count($user))->toBe(2);
});

it('has no contact limit on a plan without one', function () {
    $plan = Plan::factory()->create(['limits' => ['contacts' => null]]);
    $user = User::factory()->create();
    app(Entitlements::class)->assignPlan($user, $plan, null);

    foreach (Contact::factory()->published()->count(4)->create() as $contact) {
        phonebook()->add($user, $contact);
    }

    expect(phonebook()->count($user))->toBe(4)->and(phonebook()->limit($user))->toBeNull();
});

it('locks Paid contacts for Free users, and opens them on Paid', function () {
    $contact = paidOnlyContact();
    $free = User::factory()->create();

    expect(phonebook()->isLocked($free, $contact))->toBeTrue()
        ->and(phonebook()->check($free, $contact))->toBe(AddCheck::Locked)
        ->and(fn () => phonebook()->add($free, $contact))->toThrow(DomainException::class, 'part of a paid plan');

    $paid = paidUser();
    expect(phonebook()->isLocked($paid, $contact))->toBeFalse()
        ->and(phonebook()->add($paid, $contact))->toBeInstanceOf(UserContact::class);
});

it('never locks a contact that any plan can add', function () {
    expect(phonebook()->isLocked(User::factory()->create(), Contact::factory()->published()->create()))->toBeFalse();
});

it('refuses contacts that are not in the catalog', function () {
    $user = User::factory()->create();
    $draft = Contact::factory()->create();
    $deleted = Contact::factory()->published()->create();
    $deleted->delete();

    expect(phonebook()->check($user, $draft))->toBe(AddCheck::Unavailable)
        ->and(phonebook()->check($user, $deleted))->toBe(AddCheck::Unavailable)
        ->and(fn () => phonebook()->add($user, $draft))->toThrow(DomainException::class, 'aren\'t available');
});

it('refuses to add the same contact twice', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->published()->create();
    phonebook()->add($user, $contact);

    phonebook()->add($user, $contact);
})->throws(DomainException::class, 'already on your phone');

it('removes a contact without deleting anything, and brings the same entry back', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->published()->create();
    $first = phonebook()->add($user, $contact);

    phonebook()->remove($user, $contact);
    expect(phonebook()->count($user))->toBe(0)
        ->and(UserContact::withTrashed()->find($first->id)->trashed())->toBeTrue()
        ->and(phonebook()->check($user, $contact))->toBe(AddCheck::Ok);

    $again = phonebook()->add($user, $contact);
    expect($again->id)->toBe($first->id)->and(UserContact::withTrashed()->count())->toBe(1);
});

it('does nothing when removing a contact that is not on the phone', function () {
    phonebook()->remove(User::factory()->create(), Contact::factory()->published()->create());

    expect(UserContact::withTrashed()->count())->toBe(0);
});

it('counts a removed contact coming back against the limit', function () {
    $user = User::factory()->create();
    [$a, $b, $c] = Contact::factory()->published()->count(3)->create();
    phonebook()->add($user, $a);
    phonebook()->remove($user, $a);
    phonebook()->add($user, $b);
    phonebook()->add($user, $c);

    expect(phonebook()->check($user, $a))->toBe(AddCheck::LimitReached);
});

it('uses a nickname when there is one', function () {
    $user = User::factory()->create();
    $entry = phonebook()->add($user, Contact::factory()->published()->create(['name' => 'Joe Tucker']));

    expect($entry->displayName())->toBe('Joe');
    $entry->update(['nickname' => 'Pops']);
    expect($entry->displayName())->toBe('Pops');
});

it('keeps an entry\'s contact even after the contact is deleted from the catalog', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->published()->create();
    $entry = phonebook()->add($user, $contact);
    $contact->delete();

    expect($entry->refresh()->contact->is($contact))->toBeTrue();
});

it('explains every answer', function () {
    expect(collect(AddCheck::cases())->map->message()->filter()->count())->toBe(count(AddCheck::cases()));
});
