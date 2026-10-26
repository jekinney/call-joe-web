<?php

use App\Models\Contact;
use App\Models\Llm;
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

/** The id of a texting model, as the form holds it. */
function brainFor(string $modelId = 'grok-4.20-0309-non-reasoning'): string
{
    return (string) Llm::where('purpose', 'chat')->where('model_id', $modelId)->value('id');
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
        ->set('brainId', brainFor())
        ->set('persona_md', '# Joe')
        ->set('looks', 'Gray mustache.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.contacts.edit', Contact::firstWhere('slug', 'joe-tucker')));

    $contact = Contact::firstWhere('slug', 'joe-tucker');
    expect($contact->requiredPlan->slug)->toBe('paid')
        ->and($contact->brain->model_id)->toBe('grok-4.20-0309-non-reasoning')
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
        ->assertHasErrors(['name' => 'required', 'slug' => 'unique', 'gender' => 'in', 'persona_md' => 'required', 'requiredPlanId' => 'exists', 'brainId' => 'required']);
});

it('needs a texting model as the brain', function () {
    $callsModel = (string) Llm::where('purpose', 'voice')->value('id');

    editor()->set('name', 'Joe')->set('persona_md', '# Joe')->set('brainId', $callsModel)->call('save')->assertHasErrors(['brainId' => 'exists']);
    editor()->set('name', 'Joe')->set('persona_md', '# Joe')->set('brainId', '999999')->call('save')->assertHasErrors(['brainId' => 'exists']);
});

it('offers every active texting model as a brain, with what it costs', function () {
    Llm::where('model_id', 'gemini-3.8-flash')->update(['is_active' => false]);

    $this->actingAs($this->admin)->get(route('admin.contacts.create'))
        ->assertSee('Gemini 3.5 Flash-Lite · Gemini · $0.30 in / $2.50 out')
        ->assertSee('Grok 4.20 fast · Grok · $1.25 in / $2.50 out')
        ->assertSee('Claude Sonnet 5.5 · Claude · $2.00 in / $10.00 out')
        ->assertDontSee('Gemini 3.8 Flash')
        ->assertDontSee('Claude Sonnet 5.5 (memory)'); // that one is for memory notes, not texting
});

it('lets only an admin set or change a brain', function () {
    $contact = Contact::factory()->create();

    Volt::actingAs(User::factory()->create())->test('admin.contacts.edit', ['contact' => $contact])->set('brainId', brainFor())->call('save')->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('admin.contacts.edit', $contact))->assertForbidden();
    expect($contact->refresh()->brain->model_id)->toBe('gemini-3.5-flash-lite');
});

it('changes a brain without changing who the contact is', function () {
    $contact = Contact::factory()->create();

    editor($contact)->assertSet('brainId', brainFor('gemini-3.5-flash-lite'))->set('brainId', brainFor('claude-sonnet-5-5'))->call('save')->assertHasNoErrors();

    expect($contact->refresh()->brain->model_id)->toBe('claude-sonnet-5-5')->and($contact->version)->toBe(1)->and($contact->versions)->toHaveCount(0);
});

it('edits a contact: new version with a note, and loads the saved values', function () {
    $contact = app(ContactCatalog::class)->create(['name' => 'Joe Tucker', 'gender' => 'man', 'persona_md' => '# v1', 'tagline' => 'Old', 'brain_id' => (int) brainFor()]);

    editor($contact)
        ->assertSet('name', 'Joe Tucker')
        ->assertSet('tagline', 'Old')
        ->assertSet('requiredPlanId', '')
        ->assertSet('brainId', brainFor())
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
    $contact = Contact::factory()->withoutBrain()->create();

    editor($contact)->call('publish')->assertHasErrors('publish')->assertSee('still needs a picture, a voice and a brain');

    $contact->forceFill(['avatar_path' => 'contacts/x.jpg', 'voice' => 'Puck'])->save();
    editor($contact)->call('publish')->assertHasErrors('publish')->assertSee('still needs a brain');

    $contact->forceFill(['brain_id' => brainFor()])->save();
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

it('sets a contact\'s voice from Gemini\'s built-in voices', function () {
    $contact = Contact::factory()->create();

    editor($contact)->set('voice', 'robot')->call('save')->assertHasErrors(['voice' => 'in']);

    editor($contact)->set('voice', 'Kore')->call('save')->assertHasNoErrors();
    expect($contact->refresh()->voice)->toBe('Kore');

    editor($contact)->assertSet('voice', 'Kore')->set('voice', '')->call('save')->assertHasNoErrors();
    expect($contact->refresh()->voice)->toBeNull();
});

it('is linked from the admin sidebar', function () {
    $this->actingAs($this->admin)->get('/admin')->assertSee(route('admin.contacts.index'));
});
