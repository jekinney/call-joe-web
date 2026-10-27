<?php

use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Image;
use App\Models\ImageCacheEntry;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

// /admin/pictures/{contact}: a contact's library of cached pictures, with the images themselves.

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks']);
});

function cachedPicture(Contact $contact, string $scene, int $hits = 0, string $bytes = 'jpeg-bytes'): Image
{
    Storage::disk('local')->put($path = 'images/'.md5($scene).'.jpg', $bytes);
    $image = Image::create(['disk' => 'local', 'storage_path' => $path, 'width' => 1, 'height' => 1, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $scene)]);
    ImageCacheEntry::create(['cache_key' => md5($scene), 'variant' => 1, 'contact_id' => $contact->id, 'contact_version' => 1, 'scene_signature' => $scene, 'image_id' => $image->id, 'hits' => $hits]);

    return $image;
}

it('shows a contact\'s cached pictures and links to them from the pictures page', function () {
    $lunch = cachedPicture($this->maya, 'lunch cafe noon', hits: 3);
    cachedPicture(Contact::factory()->published()->create(['name' => 'Joe Tucker']), 'tractor field dusk');

    Volt::actingAs($this->admin)->test('admin.pictures.contact', ['contactId' => $this->maya->id])
        ->assertSee('Maya Brooks')
        ->assertSee('1 picture')
        ->assertSee('3 reuses')
        ->assertSee('lunch cafe noon')
        ->assertSee(route('admin.pictures.file', $lunch->id))
        ->assertDontSee('tractor field dusk');

    Volt::actingAs($this->admin)->test('admin.pictures')
        ->assertSee(route('admin.pictures.contact', $this->maya->id));
});

it('says so when a contact has no cached pictures, and works for a deleted contact', function () {
    Volt::actingAs($this->admin)->test('admin.pictures.contact', ['contactId' => $this->maya->id])->assertSee('No cached pictures yet.');

    $this->maya->delete();
    $this->actingAs($this->admin)->get(route('admin.pictures.contact', $this->maya->id))->assertOk()->assertSee('Maya Brooks');
});

it('shows the scene as labels, not JSON', function () {
    cachedPicture($this->maya, '{"subject":"lunch","setting":"cafe","action":"","outfit":"red scarf","time_of_day":"noon"}');
    cachedPicture($this->maya, 'words:lake sunset');

    Volt::actingAs($this->admin)->test('admin.pictures.contact', ['contactId' => $this->maya->id])
        ->assertSee('lunch · cafe · red scarf · noon')
        ->assertSee('lake sunset')
        ->assertDontSee('"subject"')
        ->assertDontSee('words:');

    expect(ImageCacheEntry::where('scene_signature', 'like', '{%')->first()->sceneLabels())
        ->toBe(['Subject' => 'lunch', 'Setting' => 'cafe', 'Outfit' => 'red scarf', 'Time of day' => 'noon'])
        ->and(ImageCacheEntry::where('scene_signature', 'words:lake sunset')->first()->sceneLabels())->toBe(['Scene' => 'lake sunset']);
});

it('has a viewer with thumbnails, arrows and every picture in it', function () {
    $first = cachedPicture($this->maya, 'words:lunch');
    $second = cachedPicture($this->maya, 'words:lake');

    $page = $this->actingAs($this->admin)->get(route('admin.pictures.contact', $this->maya->id))
        ->assertSee('data-viewer', false)
        ->assertSee('Next picture')->assertSee('Previous picture')
        ->assertSee('View picture 2');

    // Each picture is a thumbnail and also in the viewer's list (where slashes are escaped).
    $html = preg_replace('#\\\\+/#', '/', $page->getContent());
    expect(substr_count($html, route('admin.pictures.file', $first->id)))->toBe(2)
        ->and(substr_count($html, route('admin.pictures.file', $second->id)))->toBe(2);
});

it('shows the labels on the pictures page too', function () {
    cachedPicture($this->maya, '{"subject":"lunch","setting":"cafe","action":"","outfit":"","time_of_day":""}');

    Volt::actingAs($this->admin)->test('admin.pictures')->assertSee('Subject:')->assertSee('lunch')->assertDontSee('"subject"');
});

it('serves a cached picture to admins who manage pictures', function () {
    $image = cachedPicture($this->maya, 'lunch cafe noon');

    $this->actingAs($this->admin)->get(route('admin.pictures.file', $image))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('does not serve a picture that is not in the shared cache (it was made for one user)', function () {
    Storage::disk('local')->put('images/private.jpg', 'private');
    $private = Image::create(['disk' => 'local', 'storage_path' => 'images/private.jpg', 'width' => 1, 'height' => 1, 'bytes' => 7, 'sha256' => 'x']);

    $this->actingAs($this->admin)->get(route('admin.pictures.file', $private))->assertNotFound();
});

it('404s when the file is missing from storage', function () {
    $image = cachedPicture($this->maya, 'lunch cafe noon');
    Storage::disk('local')->delete($image->storage_path);

    $this->actingAs($this->admin)->get(route('admin.pictures.file', $image))->assertNotFound();
});

it('is only for roles that manage pictures', function () {
    $image = cachedPicture($this->maya, 'lunch cafe noon');
    $support = User::factory()->allowing(Permission::UsersView)->create();

    $this->actingAs($support)->get(route('admin.pictures.contact', $this->maya->id))->assertForbidden();
    $this->actingAs($support)->get(route('admin.pictures.file', $image))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('admin.pictures.file', $image))->assertForbidden();
    $this->actingAs(User::factory()->allowing(Permission::PicturesManage)->create())->get(route('admin.pictures.contact', $this->maya->id))->assertOk();
});
