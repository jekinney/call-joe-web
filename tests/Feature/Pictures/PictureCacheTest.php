<?php

use App\Contracts\LlmClient;
use App\Enums\Metric;
use App\Jobs\GenerateImage;
use App\Models\Contact;
use App\Models\Image;
use App\Models\ImageCacheEntry;
use App\Models\ImageRequest;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserContact;
use App\Services\ContactCatalog;
use App\Services\Entitlements;
use App\Services\Phonebook;
use App\Services\PictureCache;
use App\Services\Pictures;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

// The shared picture cache: the same contact's same scene is reused across users.

const LUNCH = '{"subject": "lunch", "setting": "cafe", "action": "", "outfit": "", "time_of_day": "noon"}';

beforeEach(function () {
    Storage::fake('local');
    Queue::fake([GenerateImage::class]);
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'looks' => 'Curly red hair.']);
});

function pictureCache(): PictureCache
{
    return app(PictureCache::class);
}

function mayaFor(?User $user = null): UserContact
{
    $user ??= User::factory()->create(['name' => 'Jamie Rivera']);
    app(Entitlements::class)->assignPlan($user, Plan::where('slug', 'paid')->firstOrFail(), null);

    return UserContact::where('user_id', $user->id)->where('contact_id', test()->maya->id)->first()
        ?? app(Phonebook::class)->add($user, test()->maya);
}

/** Maya sends this scene to this person: the safety check passes, the scene model answers $scene. */
function sendPicture(UserContact $entry, string $prompt = 'my lunch at the cafe', string $scene = LUNCH): ImageRequest
{
    app(LlmClient::class)->reply('{"category": "none"}', $scene);
    $request = app(Pictures::class)->request($entry, $prompt);
    app(Pictures::class)->generate($request);

    return $request->fresh();
}

it('boils a scene down to a tidy signature', function () {
    $this->llm->reply('Sure! {"subject": "Lunch!!", "setting": " the  Cafe ", "mood": "happy"}');

    expect(pictureCache()->signature(mayaFor(), 'what I had for lunch'))
        ->toBe('{"action":"","outfit":"","setting":"the cafe","subject":"lunch","time_of_day":""}')
        ->and($this->llm->calls[0]['model'])->toBe('gemini-3.5-flash-lite')
        ->and($this->llm->calls[0]['options'])->toBe(['max_tokens' => 120, 'temperature' => 0.0]);
});

it('falls back to a word list when the model can\'t say', function (?string $answer) {
    $answer === null ? $this->llm->failAll() : $this->llm->reply($answer, $answer);

    expect(pictureCache()->signature(mayaFor(), 'My big salad at the cafe!'))->toBe('words:big cafe salad')
        ->and(pictureCache()->signature(mayaFor(), 'the cafe, my BIG salad'))->toBe('words:big cafe salad');
})->with(['down' => [null], 'not json' => ['a salad'], 'empty fields' => ['{"subject": "", "setting": ""}'], 'other keys' => ['{"food": "salad"}']]);

it('never shares a scene about the user', function (string $scene) {
    $jamie = User::factory()->create(['name' => 'Jamie Rivera', 'display_name' => 'Jay']);

    expect(pictureCache()->signature(mayaFor($jamie), $scene))->toBeNull()->and($this->llm->calls)->toBe([]);
})->with(['us at the lake', 'a selfie just for you', 'our favorite diner', 'a cake that says Jamie', 'the Rivera family reunion', 'Jay\'s new truck']);

it('keys on the contact, their identity version and looks, and the scene', function () {
    $key = pictureCache()->keyFor($this->maya, 'scene');
    $other = Contact::factory()->create();

    expect(pictureCache()->keyFor($this->maya, 'scene'))->toBe($key)
        ->and(pictureCache()->keyFor($this->maya, 'another scene'))->not->toBe($key)
        ->and(pictureCache()->keyFor($other, 'scene'))->not->toBe($key)
        ->and(pictureCache()->keyFor($this->maya->replicate()->forceFill(['id' => $this->maya->id, 'version' => 2]), 'scene'))->not->toBe($key)
        ->and(pictureCache()->keyFor($this->maya->replicate()->forceFill(['id' => $this->maya->id, 'looks' => 'Short black hair.']), 'scene'))->not->toBe($key);
});

it('makes a few variants, then reuses them for everyone, still counting each picture', function () {
    $first = collect(range(1, PictureCache::VARIANTS))->map(fn () => sendPicture(mayaFor()));
    expect(count($this->images->prompts))->toBe(PictureCache::VARIANTS)
        ->and($first->pluck('from_cache')->unique()->all())->toBe([false])
        ->and(ImageCacheEntry::orderBy('variant')->pluck('variant')->all())->toBe([0, 1, 2]);

    $fourth = mayaFor();
    $reused = sendPicture($fourth);

    $entry = ImageCacheEntry::where('image_id', $reused->image_id)->sole();
    expect(count($this->images->prompts))->toBe(PictureCache::VARIANTS) // no new picture made
        ->and($reused->only(['status', 'from_cache']))->toBe(['status' => 'ready', 'from_cache' => true])
        ->and($reused->cache_key)->toBe($first[0]->cache_key)
        ->and($reused->scene_signature)->toBe('{"action":"","outfit":"","setting":"cafe","subject":"lunch","time_of_day":"noon"}')
        ->and($entry->only(['variant', 'hits']))->toBe(['variant' => 0, 'hits' => 1])
        ->and($entry->last_hit_at)->not->toBeNull()
        ->and($fourth->messages()->sole()->meta['image_id'])->toBe($reused->image_id)
        ->and(app(Entitlements::class)->used($fourth->user, Metric::Images))->toBe(1)
        ->and(Image::count())->toBe(PictureCache::VARIANTS);
});

it('rotates variants, and gives someone one they haven\'t been sent', function () {
    $owners = collect(range(1, PictureCache::VARIANTS))->map(fn () => mayaFor());
    $owners->each(fn ($entry) => sendPicture($entry));

    $this->travel(1)->minutes();
    $a = sendPicture(mayaFor())->image_id;
    $this->travel(1)->minutes();
    $b = sendPicture(mayaFor())->image_id;
    $this->travel(1)->minutes();
    $c = sendPicture(mayaFor())->image_id;
    expect([$a, $b, $c])->toEqualCanonicalizing(Image::pluck('id')->all()); // each variant once, least used first

    // The first owner already has variant 0; they get another one.
    $again = sendPicture($owners[0]);
    expect($again->image_id)->not->toBe($owners[0]->messages()->first()->meta['image_id']);

    // Someone who has every variant gets the least recently used.
    $collector = mayaFor();
    foreach (range(1, 4) as $i) {
        $this->travel(1)->minutes();
        sendPicture($collector);
    }
    expect(ImageCacheEntry::sum('hits'))->toBe(3 + 1 + 4);
});

it('still runs the safety check before reusing a picture', function () {
    collect(range(1, PictureCache::VARIANTS))->each(fn () => sendPicture(mayaFor()));
    $free = mayaFor();
    app(Entitlements::class)->assignPlan($free->user, Plan::where('slug', 'free')->firstOrFail(), null);
    $this->llm->reply('{"category": "explicit"}');

    $request = app(Pictures::class)->request($free, 'my lunch at the cafe');
    app(Pictures::class)->generate($request);

    expect($request->fresh()->status)->toBe('blocked')->and(ImageCacheEntry::sum('hits'))->toBe(0);
});

it('never caches a scene about the user', function () {
    $request = sendPicture(mayaFor(), 'us at the lake last summer');

    expect($request->only(['status', 'cache_key', 'scene_signature']))->toBe(['status' => 'ready', 'cache_key' => null, 'scene_signature' => null])
        ->and(ImageCacheEntry::count())->toBe(0);
});

it('starts a contact\'s cache over when their looks change', function () {
    collect(range(1, PictureCache::VARIANTS))->each(fn () => sendPicture(mayaFor()));
    app(ContactCatalog::class)->update($this->maya, ['looks' => 'Short black hair now.'], User::factory()->admin()->create());
    $this->maya->refresh();

    $request = sendPicture(mayaFor());

    expect($request->from_cache)->toBeFalse()->and(count($this->images->prompts))->toBe(PictureCache::VARIANTS + 1)
        ->and(ImageCacheEntry::where('contact_version', $this->maya->version)->count())->toBe(1);
});

it('keeps the pool to its size, and shrugs off a race for the same spot', function () {
    $image = Image::create(['disk' => 'local', 'storage_path' => 'x.jpg', 'width' => 1, 'height' => 1, 'bytes' => 1, 'sha256' => str_repeat('a', 64)]);
    ImageCacheEntry::create(['cache_key' => 'k', 'variant' => 1, 'contact_id' => $this->maya->id, 'contact_version' => 1, 'scene_signature' => 's', 'image_id' => $image->id]);

    pictureCache()->add('k', $this->maya, 's', $image); // wants variant 1, already taken
    expect(ImageCacheEntry::count())->toBe(1);

    foreach ([0, 2] as $variant) {
        ImageCacheEntry::create(['cache_key' => 'k', 'variant' => $variant, 'contact_id' => $this->maya->id, 'contact_version' => 1, 'scene_signature' => 's', 'image_id' => $image->id]);
    }
    pictureCache()->add('k', $this->maya, 's', $image);
    expect(ImageCacheEntry::count())->toBe(3);
});

it('forgets scenes nobody has been sent in a while, keeping the pictures', function () {
    collect(range(1, PictureCache::VARIANTS))->each(fn () => sendPicture(mayaFor()));
    ImageCacheEntry::where('variant', 0)->update(['created_at' => now()->subDays(40)]);
    ImageCacheEntry::where('variant', 1)->update(['created_at' => now()->subDays(40), 'last_hit_at' => now()->subDays(2)]);

    $this->artisan('pictures:evict')->expectsOutput('Dropped 1 cached pictures unused for 30 days.')->assertSuccessful();
    expect(ImageCacheEntry::pluck('variant')->sort()->values()->all())->toBe([1, 2])->and(Image::count())->toBe(3);

    $this->artisan('pictures:evict', ['--days' => 1])->expectsOutput('Dropped 1 cached pictures unused for 1 days.');
    $this->artisan('schedule:list')->expectsOutputToContain('pictures:evict');
});

it('shows admins how much is reused, and starts a contact over', function () {
    collect(range(1, PictureCache::VARIANTS + 1))->each(fn () => sendPicture(mayaFor()));
    $admin = User::factory()->admin()->create();

    $this->actingAs(User::factory()->create())->get(route('admin.pictures'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.pictures'))->assertOk()
        ->assertSeeInOrder(['Sent', '4', 'Reused', '1', 'Reuse rate', '25%'])
        ->assertSee('Maya Brooks')->assertSee('&quot;subject&quot;:&quot;lunch&quot;', false)
        ->assertSee(route('admin.pictures'));

    Volt::actingAs($admin)->test('admin.pictures')->call('purge', $this->maya->id)->assertSee('Maya Brooks: 3 cached pictures forgotten.');
    expect(ImageCacheEntry::count())->toBe(0);
    $this->actingAs($admin)->get(route('admin.pictures'))->assertSee('Nothing cached yet.');
});

it('shows a zero reuse rate before any pictures', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.pictures'))->assertSee('0%');
});
