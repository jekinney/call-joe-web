<?php

use App\Models\Contact;
use App\Models\Image;
use App\Models\ImageRequest;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserContact;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

// /app/settings/data: a copy of your data, and deleting it all.

function linkedUser(string $name = 'Sam Rivers'): array
{
    $user = User::factory()->create(['name' => $name]);
    $link = UserContact::create(['user_id' => $user->id, 'contact_id' => Contact::factory()->create(['name' => 'Joe Tucker'])->id, 'is_active' => true, 'added_at' => now()]);
    Message::create(['user_contact_id' => $link->id, 'sender' => 'user', 'body' => 'hello Joe']);
    Memory::create(['user_contact_id' => $link->id, 'notes_md' => 'Sam likes fishing.', 'version' => 1]);

    return [$user, $link];
}

it('needs a signed-in user', function () {
    $this->get(route('settings.data'))->assertRedirect(route('login'));
    $this->get(route('settings.data.export'))->assertRedirect(route('login'));
});

it('shows the page', function () {
    $this->actingAs(User::factory()->create())->get(route('settings.data'))
        ->assertOk()->assertSee('Download my data')->assertSee(route('settings.data.export'));
});

it('downloads only the signed-in user\'s data', function () {
    [$user] = linkedUser();
    linkedUser('Other Person');

    $response = $this->actingAs($user)->get(route('settings.data.export'))->assertOk();
    $data = json_decode($response->streamedContent(), true);

    expect($response->headers->get('content-disposition'))->toContain('call-joe-my-data-')
        ->and($data['account']['email'])->toBe($user->email)
        ->and($data['contacts'])->toHaveCount(1)
        ->and($data['contacts'][0])->toMatchArray(['contact' => 'Joe Tucker', 'memory_notes' => 'Sam likes fishing.'])
        ->and($data['contacts'][0]['messages'][0]['text'])->toBe('hello Joe')
        ->and($response->streamedContent())->not->toContain('Other Person');
});

it('deletes everything and the pictures only they used', function () {
    Storage::fake('local');
    [$user, $link] = linkedUser();
    [, $otherLink] = linkedUser('Other Person');
    Storage::disk('local')->put('mine.jpg', 'x');
    Storage::disk('local')->put('theirs.jpg', 'x');
    $mine = Image::create(['disk' => 'local', 'storage_path' => 'mine.jpg', 'width' => 1, 'height' => 1, 'bytes' => 1, 'sha256' => str_repeat('a', 64)]);
    $theirs = Image::create(['disk' => 'local', 'storage_path' => 'theirs.jpg', 'width' => 1, 'height' => 1, 'bytes' => 1, 'sha256' => str_repeat('b', 64)]);
    foreach ([[$link, $mine], [$otherLink, $theirs]] as [$l, $image]) {
        ImageRequest::create(['user_contact_id' => $l->id, 'prompt' => 'lunch', 'normalized_prompt' => 'lunch', 'provider' => 'fake', 'status' => 'ready', 'image_id' => $image->id]);
    }

    $this->actingAs($user);
    Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');

    expect(User::find($user->id))->toBeNull()
        ->and(Message::where('user_contact_id', $link->id)->exists())->toBeFalse()
        ->and(Memory::where('user_contact_id', $link->id)->exists())->toBeFalse()
        ->and(Image::find($mine->id))->toBeNull()
        ->and(Storage::disk('local')->exists('mine.jpg'))->toBeFalse()
        ->and(Image::find($theirs->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists('theirs.jpg'))->toBeTrue()
        ->and(Message::count())->toBe(1);
});

it('cancels a live subscription before deleting', function () {
    $user = User::factory()->create();
    Subscription::create(['user_id' => $user->id, 'plan_id' => Plan::factory()->create()->id, 'braintree_subscription_id' => 'sub_x', 'status' => 'active', 'paid_through' => now()->addMonth()]);

    $this->actingAs($user);
    Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors();

    expect($this->payments->canceled)->toBe(['sub_x'])->and(User::find($user->id))->toBeNull();
});

it('deletes nothing when the subscription cannot be canceled', function () {
    $user = User::factory()->create();
    Subscription::create(['user_id' => $user->id, 'plan_id' => Plan::factory()->create()->id, 'braintree_subscription_id' => 'sub_x', 'status' => 'active', 'paid_through' => now()->addMonth()]);
    $this->payments->decline();

    $this->actingAs($user);
    Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password']);

    expect(User::find($user->id))->not->toBeNull();
});

it('will not delete the only admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);
    Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password']);

    expect(User::find($admin->id))->not->toBeNull();
});
