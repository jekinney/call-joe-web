<?php

use App\Models\Contact;
use App\Models\Plan;
use App\Models\User;
use App\Services\ContactCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

// /admin/contacts: the catalog's list and editor.

beforeEach(function () {
    Storage::fake('public');
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
});

function editor(?Contact $contact = null)
{
    return Volt::actingAs(test()->admin)->test('admin.contacts.edit', $contact ? ['contact' => $contact] : []);
}

it('keeps regular users out', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create();

    foreach ([route('admin.contacts.index'), route('admin.contacts.create'), route('admin.contacts.edit', $contact)] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
});

it('lists contacts with their status, and searches', function () {
    Contact::factory()->published()->create(['name' => 'Joe Tucker', 'tagline' => 'Kansas storyteller']);
    Contact::factory()->create(['name' => 'Maya Brooks', 'tagline' => 'ER nurse']);

    $this->actingAs($this->admin)->get(route('admin.contacts.index'))
        ->assertOk()->assertSee('Joe Tucker')->assertSee('Published')->assertSee('Draft')->assertSee('v1');

    Volt::actingAs($this->admin)->test('admin.contacts.index')
        ->set('search', 'nurse')->assertSee('Maya Brooks')->assertDontSee('Joe Tucker')
        ->set('search', 'zzz')->assertSee('No contacts yet.');
});

it('creates a contact and goes to its page', function () {
    editor()
        ->set('name', 'Joe Tucker')
        ->set('gender', 'man')
        ->set('tagline', 'Kansas storyteller')
        ->set('requiredPlanId', (string) Plan::where('slug', 'paid')->value('id'))
        ->set('persona_md', '# Joe')
        ->set('looks', 'Gray mustache.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.contacts.edit', Contact::firstWhere('slug', 'joe-tucker')));

    $contact = Contact::firstWhere('slug', 'joe-tucker');
    expect($contact->requiredPlan->slug)->toBe('paid')
        ->and($contact->versions->first()->created_by)->toBe($this->admin->id);
});

it('validates the form', function () {
    Contact::factory()->create(['slug' => 'taken']);

    editor()
        ->set('name', '')
        ->set('slug', 'taken')
        ->set('gender', 'robot')
        ->set('persona_md', '')
        ->set('requiredPlanId', '999999')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'slug' => 'unique', 'gender' => 'in', 'persona_md' => 'required', 'requiredPlanId' => 'exists']);
});

it('edits a contact: new version with a note, and loads the saved values', function () {
    $contact = app(ContactCatalog::class)->create(['name' => 'Joe Tucker', 'gender' => 'man', 'persona_md' => '# v1', 'tagline' => 'Old']);

    editor($contact)
        ->assertSet('name', 'Joe Tucker')
        ->assertSet('tagline', 'Old')
        ->assertSet('requiredPlanId', '')
        ->set('persona_md', '# v2')
        ->set('note', 'Softer')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('contact-saved')
        ->assertSet('note', '')
        ->assertSee('Softer')
        ->assertSee('Ada Admin');

    expect($contact->refresh()->version)->toBe(2);
});

it('shows a live persona preview, with any HTML stripped', function () {
    editor()
        ->set('persona_md', "# Who you are\n<script>alert(1)</script>Plain")
        ->set('preview', true)
        ->assertSeeHtml('<h1>Who you are</h1>')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('uploads a picture, and rejects ones that are too small', function () {
    $contact = Contact::factory()->create();

    editor($contact)
        ->set('avatar', UploadedFile::fake()->image('tiny.jpg', 100, 100))
        ->assertHasErrors(['avatar' => 'dimensions']);

    editor($contact)
        ->set('avatar', UploadedFile::fake()->image('face.jpg', 512, 512))
        ->assertHasNoErrors()
        ->assertDispatched('avatar-saved');

    expect($contact->refresh()->avatar_path)->not->toBeNull();
});

it('explains what is missing when publishing too early, then publishes and hides', function () {
    $contact = Contact::factory()->create();

    editor($contact)->call('publish')->assertHasErrors('publish')->assertSee('still needs a picture and a voice');

    $contact->forceFill(['avatar_path' => 'contacts/x.jpg', 'voice_provider_id' => 'v1', 'voice_settings' => ['provider' => 'vapi']])->save();
    editor($contact)->call('publish')->assertHasNoErrors()->assertSee('Hide from catalog');
    expect($contact->refresh()->is_published)->toBeTrue();

    editor($contact)->call('unpublish')->assertSee('Publish');
    expect($contact->refresh()->is_published)->toBeFalse();
});

it('restores an earlier version from the history', function () {
    $contact = app(ContactCatalog::class)->create(['name' => 'Joe', 'gender' => 'man', 'persona_md' => '# v1', 'looks' => 'Mustache']);
    app(ContactCatalog::class)->update($contact, ['persona_md' => '# v2', 'looks' => null]);

    editor($contact->refresh())
        ->assertSee('Restore')
        ->call('restore', 1)
        ->assertSet('persona_md', '# v1')
        ->assertSet('looks', 'Mustache')
        ->assertSee('Restored version 1');

    expect($contact->refresh()->version)->toBe(3);
});

it('sets a contact\'s voice, and needs both the provider and the voice id', function () {
    $contact = Contact::factory()->create();

    editor($contact)->set('voiceProvider', '11labs')->call('save')->assertHasErrors(['voiceId' => 'required_with']);
    editor($contact)->set('voiceProvider', 'robot')->set('voiceId', 'x')->call('save')->assertHasErrors(['voiceProvider' => 'in']);

    editor($contact)->set('voiceProvider', '11labs')->set('voiceId', 'voice-123')->call('save')->assertHasNoErrors();
    expect($contact->refresh()->voice())->toBe(['provider' => '11labs', 'voiceId' => 'voice-123']);

    editor($contact)->assertSet('voiceProvider', '11labs')->assertSet('voiceId', 'voice-123')
        ->set('voiceProvider', '')->set('voiceId', '')->call('save')->assertHasNoErrors();
    expect($contact->refresh()->voice())->toBeNull();
});

it('is linked from the admin sidebar', function () {
    $this->actingAs($this->admin)->get('/admin')->assertSee(route('admin.contacts.index'));
});
