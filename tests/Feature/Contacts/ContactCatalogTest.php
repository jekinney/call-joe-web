<?php

use App\Models\Contact;
use App\Models\ContactVersion;
use App\Models\Llm;
use App\Models\Plan;
use App\Models\User;
use App\Services\ContactCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Creating and editing contacts: identity is versioned, and any version can come back.

function catalog(): ContactCatalog
{
    return app(ContactCatalog::class);
}

function newContact(array $overrides = []): Contact
{
    return catalog()->create(array_merge([
        'name' => 'Joe Tucker',
        'gender' => 'man',
        'persona_md' => '# Joe v1',
        'looks' => 'Gray mustache.',
    ], $overrides));
}

it('creates a contact as version 1, with a slug from the name', function () {
    $admin = User::factory()->admin()->create();
    $contact = catalog()->create(['name' => 'Joe Tucker', 'gender' => 'man', 'persona_md' => '# Joe'], $admin);

    expect($contact->slug)->toBe('joe-tucker')
        ->and($contact->version)->toBe(1)
        ->and($contact->is_published)->toBeFalse()
        ->and($contact->versions)->toHaveCount(1)
        ->and($contact->versions->first()->only(['version', 'persona_md', 'note', 'created_by']))
        ->toBe(['version' => 1, 'persona_md' => '# Joe', 'note' => 'Created', 'created_by' => $admin->id]);
});

it('makes slugs unique, counting deleted contacts too', function () {
    newContact()->delete();
    $second = newContact();
    $third = newContact();

    expect($second->slug)->toBe('joe-tucker-2')
        ->and($third->slug)->toBe('joe-tucker-3')
        ->and(catalog()->uniqueSlug('Joe Tucker', $third->id))->toBe('joe-tucker-3')
        ->and(catalog()->uniqueSlug('!!!'))->toBe('contact');
});

it('saves a new version when the persona or looks change, with a note', function () {
    $admin = User::factory()->admin()->create();
    $contact = newContact();

    catalog()->update($contact, ['persona_md' => '# Joe v2'], $admin, 'Softer');
    catalog()->update($contact, ['looks' => 'Clean shaven.'], $admin);

    expect($contact->refresh()->version)->toBe(3)
        ->and($contact->versions->pluck('version')->all())->toBe([3, 2, 1])
        ->and($contact->versions->firstWhere('version', 2)->only(['persona_md', 'note']))->toBe(['persona_md' => '# Joe v2', 'note' => 'Softer'])
        ->and($contact->versions->first()->looks)->toBe('Clean shaven.')
        ->and($contact->versions->first()->author->is($admin))->toBeTrue();
});

it('changes plain details without a new version', function () {
    $contact = newContact();

    catalog()->update($contact, ['tagline' => 'Kansas storyteller', 'name' => 'Joseph Tucker']);

    expect($contact->refresh()->version)->toBe(1)
        ->and($contact->tagline)->toBe('Kansas storyteller')
        ->and(ContactVersion::count())->toBe(1);
});

it('restores an earlier version as a new one, losing nothing', function () {
    $contact = newContact();
    catalog()->update($contact, ['persona_md' => '# Joe v2', 'looks' => 'Beard.']);

    catalog()->restore($contact, 1);

    expect($contact->refresh()->persona_md)->toBe('# Joe v1')
        ->and($contact->looks)->toBe('Gray mustache.')
        ->and($contact->version)->toBe(3)
        ->and($contact->versions->first()->note)->toBe('Restored version 1')
        ->and($contact->versions)->toHaveCount(3);
});

it('refuses to restore a version that does not exist', function () {
    catalog()->restore(newContact(), 9);
})->throws(InvalidArgumentException::class, "Version 9 doesn't exist.");

it('stores a picture and removes the old one when replaced', function () {
    Storage::fake('public');
    $contact = newContact();

    catalog()->setAvatar($contact, UploadedFile::fake()->image('joe.jpg', 512, 512));
    $first = $contact->avatar_path;
    Storage::disk('public')->assertExists($first);
    expect($first)->toStartWith("contacts/{$contact->id}/avatar-")
        ->and($contact->avatarUrl())->toContain($first);

    catalog()->setAvatar($contact, UploadedFile::fake()->image('joe2.png', 512, 512));
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($contact->avatar_path);
});

it('has no picture address without a picture', function () {
    expect(newContact()->avatarUrl())->toBeNull();
});

it('publishes only when the contact has a persona, a picture, a voice and a brain', function () {
    Storage::fake('public');
    $contact = newContact();

    expect(fn () => catalog()->publish($contact))->toThrow(InvalidArgumentException::class, 'Joe Tucker still needs a picture, a voice and a brain.');

    catalog()->setAvatar($contact, UploadedFile::fake()->image('joe.jpg', 512, 512));
    expect(fn () => catalog()->publish($contact))->toThrow(InvalidArgumentException::class, 'Joe Tucker still needs a voice and a brain.');

    catalog()->update($contact, ['voice_provider_id' => 'voice-123', 'voice_settings' => ['provider' => '11labs']]);
    expect(fn () => catalog()->publish($contact))->toThrow(InvalidArgumentException::class, 'Joe Tucker still needs a brain.');

    catalog()->update($contact, ['brain_id' => Llm::where('purpose', 'chat')->value('id')]);
    catalog()->publish($contact);

    expect($contact->refresh()->is_published)->toBeTrue()
        ->and($contact->published_at)->not->toBeNull()
        ->and(Contact::published()->count())->toBe(1);
});

it('lists everything missing before publishing', function () {
    expect((new Contact(['persona_md' => '  ']))->missingForPublish())->toBe(['a persona', 'a picture', 'a voice', 'a brain']);
});

it('has a voice only with both a provider and a voice id', function () {
    expect((new Contact(['voice_provider_id' => 'abc', 'voice_settings' => ['provider' => '11labs']]))->voice())->toBe(['provider' => '11labs', 'voiceId' => 'abc'])
        ->and((new Contact(['voice_provider_id' => 'abc']))->voice())->toBeNull()
        ->and((new Contact(['voice_settings' => ['provider' => '11labs']]))->voice())->toBeNull();
});

it('hides a contact again, keeping the first publish date', function () {
    $contact = Contact::factory()->published()->create();
    $first = $contact->published_at;

    catalog()->unpublish($contact);
    expect($contact->refresh()->is_published)->toBeFalse();

    catalog()->publish($contact);
    expect($contact->refresh()->published_at->toIso8601String())->toBe($first->toIso8601String());
});

it('knows the first name and the plan needed', function () {
    $contact = newContact(['required_plan_id' => Plan::where('slug', 'paid')->value('id')]);

    expect($contact->firstName())->toBe('Joe')
        ->and($contact->requiredPlan->slug)->toBe('paid')
        ->and((new Contact(['name' => 'Cher']))->firstName())->toBe('Cher');
});

it('links each version back to its contact', function () {
    $contact = newContact();

    expect($contact->versions->first()->contact->is($contact))->toBeTrue();
});

it('seeds two sample drafts, and can run again safely', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Contact::pluck('slug')->sort()->values()->all())->toBe(['joe-tucker', 'maya-brooks'])
        ->and(Contact::with('brain')->orderBy('slug')->get()->pluck('brain.model_id')->all())->toBe(['gemini-3.5-flash-lite', 'grok-4.20-0309-non-reasoning'])
        ->and(Contact::published()->count())->toBe(0)
        ->and(User::where('email', 'test@example.com')->count())->toBe(1);
});
