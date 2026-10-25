<?php

use App\Contracts\ImageGenerator;
use App\Enums\Metric;
use App\Events\ContactMessaged;
use App\Jobs\GenerateImage;
use App\Jobs\SendContactReply;
use App\Models\Contact;
use App\Models\Image;
use App\Models\ImageRequest;
use App\Models\Message;
use App\Models\User;
use App\Models\UserContact;
use App\Services\Entitlements;
use App\Services\Outreach;
use App\Services\Phonebook;
use App\Services\Pictures;
use App\Services\PromptBuilder;
use App\Services\Texting;
use App\Support\Images\GeneratedImage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Contacts sending pictures: [photo: ...] in a reply becomes a picture a little later, checked, stored and metered.

beforeEach(function () {
    $this->freezeTime();
    Storage::fake('local');
    $this->user = User::factory()->create(['last_seen_at' => now()]);
    $contact = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'looks' => 'Early 30s, curly red hair, freckles.']);
    $this->entry = app(Phonebook::class)->add($this->user, $contact);
});

function pictures(): Pictures
{
    return app(Pictures::class);
}

function queuedPicture(string $scene = 'my lunch'): ?ImageRequest
{
    Queue::fake([GenerateImage::class]);

    return pictures()->request(test()->entry, $scene);
}

function picturesUsed(): int
{
    return app(Entitlements::class)->used(test()->user, Metric::Images);
}

it('takes the photo out of a reply', function (string $reply, string $text, ?string $scene) {
    expect(pictures()->extract($reply))->toBe([$text, $scene]);
})->with([
    'on its own line' => ["Here you go!\n[photo: my lunch, a big salad]", 'Here you go!', 'my lunch, a big salad'],
    'in the middle' => ["Look\n\n[Photo: the dog asleep]\n\nisn't he cute", "Look\n\nisn't he cute", 'the dog asleep'],
    'two of them' => ['[photo: the view] and [photo: the sunset]', 'and', 'the view'],
    'none' => ['Just text', 'Just text', null],
    'empty' => ['[photo: ] hm', 'hm', null],
]);

it('plans a full prompt from the contact\'s looks, adults only', function () {
    expect(pictures()->promptFor($this->entry, 'me at the beach.'))->toBe(
        'A casual, realistic smartphone photo that Maya took and texted to a friend: me at the beach. '
        .'Natural light, an everyday setting, no text, captions or watermarks. '
        .'If Maya is in the photo, they look like this: Early 30s, curly red hair, freckles. Maya is an adult. '
        .'Only adults appear in the photo, no children.'
    );

    $this->entry->contact->forceFill(['looks' => null])->save();
    expect(pictures()->promptFor($this->entry->fresh(), 'my garden'))->not->toContain('look like this')->toContain('Only adults appear');
});

it('sends the reply without the marker, and queues the picture to follow', function () {
    Queue::fake([GenerateImage::class, SendContactReply::class]);
    $text = app(Texting::class)->send($this->user, $this->entry, 'What did you have for lunch?');
    $this->llm->reply("A huge salad!\n[photo: my salad at the cafe]");

    $bubbles = app(Texting::class)->reply($text);

    $request = ImageRequest::sole();
    expect(collect($bubbles)->pluck('body')->all())->toBe(['A huge salad!'])
        ->and($request->only(['prompt', 'status', 'provider']))->toBe(['prompt' => 'my salad at the cafe', 'status' => 'queued', 'provider' => 'fake'])
        ->and($request->normalized_prompt)->toContain('my salad at the cafe')
        ->and(picturesUsed())->toBe(1);
    Queue::assertPushed(GenerateImage::class, fn ($job) => $job->requestId === $request->id && $job->delay->equalTo(now()->addSeconds(Pictures::DELAY_SECONDS)));
});

it('sends just the picture when the reply is only a photo', function () {
    Queue::fake([GenerateImage::class, SendContactReply::class]);
    $text = app(Texting::class)->send($this->user, $this->entry, 'Send me a pic of the dog');
    $this->llm->reply('[photo: my dog asleep on the couch]');

    expect(app(Texting::class)->reply($text))->toBe([])->and(ImageRequest::count())->toBe(1);
});

it('sends no picture once the month\'s pictures are used up', function () {
    app(Entitlements::class)->consume($this->user, Metric::Images, 5);

    expect(queuedPicture())->toBeNull()->and(ImageRequest::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('makes, cleans, stores and sends the picture', function () {
    Event::fake([ContactMessaged::class]);
    $request = queuedPicture('my salad at the cafe');
    $this->llm->reply('{"category": "none"}');

    (new GenerateImage($request->id))->handle(pictures());

    $request->refresh();
    $image = Image::sole();
    $message = Message::sole();
    $stored = Storage::disk('local')->get($image->storage_path);
    expect($request->only(['status', 'image_id', 'message_id', 'provider_job_id']))->toBe(['status' => 'ready', 'image_id' => $image->id, 'message_id' => $message->id, 'provider_job_id' => 'fake-1'])
        ->and($request->image?->is($image))->toBeTrue()
        ->and($image->only(['disk', 'width', 'height']))->toBe(['disk' => 'local', 'width' => Pictures::WIDTH, 'height' => Pictures::HEIGHT])
        ->and($image->storage_path)->toBe('images/'.substr($image->sha256, 0, 2)."/{$image->sha256}.jpg")
        ->and(hash('sha256', $stored))->toBe($image->sha256)
        ->and($image->bytes)->toBe(strlen($stored))
        ->and($stored)->not->toContain('Secret-GPS') // the metadata is gone
        ->and($message->only(['sender', 'body']))->toBe(['sender' => 'contact', 'body' => '📷 Photo: my salad at the cafe'])
        ->and($message->meta)->toEqual(['image_id' => $image->id, 'image_request_id' => $request->id])
        ->and($this->images->prompts)->toBe([$request->normalized_prompt])
        ->and($this->llm->calls[0]['messages'][1]['content'])->toContain('A prompt for a picture')
        ->and(picturesUsed())->toBe(1);
    Event::assertDispatched(ContactMessaged::class, fn ($e) => $e->message->is($message));
});

it('drops a picture the safety check stops, before asking the provider, and gives its count back', function () {
    $request = queuedPicture();
    $this->llm->reply('{"category": "real_person"}');

    pictures()->generate($request);

    expect($request->fresh()->only(['status', 'error']))->toBe(['status' => 'blocked', 'error' => 'Stopped by the safety check.'])
        ->and($this->images->prompts)->toBe([])
        ->and(Message::count())->toBe(0)
        ->and(picturesUsed())->toBe(0);
});

it('drops a picture the provider can\'t make, or refuses, and gives its count back', function (Closure $provider, string $status, string $error) {
    $provider($this->images);
    $request = queuedPicture();
    $this->llm->reply('{"category": "none"}');

    pictures()->generate($request);

    expect($request->fresh()->only(['status', 'error']))->toBe(['status' => $status, 'error' => $error])
        ->and(Image::count())->toBe(0)->and(Message::count())->toBe(0)->and(picturesUsed())->toBe(0);
})->with([
    'down' => [fn ($fake) => $fake->fail('Together answered 503'), 'failed', 'Together answered 503'],
    'refused (a black picture)' => [fn ($fake) => $fake->refuse(), 'blocked', 'Refused by the provider\'s safety checker.'],
]);

it('drops something that isn\'t a picture', function () {
    $this->app->instance(ImageGenerator::class, new class implements ImageGenerator
    {
        public function name(): string
        {
            return 'broken';
        }

        public function generate(string $prompt, int $width, int $height): GeneratedImage
        {
            return new GeneratedImage('<html>oops</html>');
        }
    });
    $request = queuedPicture();
    $this->llm->reply('{"category": "none"}');

    pictures()->generate($request);

    expect($request->fresh()->status)->toBe('failed')->and($request->fresh()->provider)->toBe('broken');
});

it('makes each picture once', function () {
    $request = queuedPicture();
    $request->update(['status' => 'ready']);

    pictures()->generate($request);
    (new GenerateImage(999))->handle(pictures());

    expect($this->images->prompts)->toBe([])->and($request->fresh()->isPending())->toBeFalse();
});

it('leaves pictures out of texts a contact sends first', function (string $written, ?string $sent) {
    $event = $this->entry->outreachEvents()->create(['kind' => 'text', 'stage' => 1, 'tone' => 'hi', 'due_at' => now()]);
    $this->llm->reply($written);

    app(Outreach::class)->write($event);

    expect(Message::first()?->body)->toBe($sent)->and(ImageRequest::count())->toBe(0)
        ->and($event->fresh()->status)->toBe($sent ? 'done' : 'skipped');
})->with([
    'with words' => ["Thinking of you!\n[photo: my garden]", 'Thinking of you!'],
    'only a photo' => ['[photo: my garden]', null],
]);

it('shows a picture only to the user it was sent to', function () {
    $request = queuedPicture();
    $this->llm->reply('{"category": "none"}');
    pictures()->generate($request);
    $image = Image::sole();

    $this->actingAs($this->user)->get(route('app.images.show', $image))->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'max-age=86400, private');
    $this->actingAs(User::factory()->create())->get(route('app.images.show', $image))->assertNotFound();

    Storage::disk('local')->delete($image->storage_path);
    $this->actingAs($this->user)->get(route('app.images.show', $image))->assertNotFound();
});

it('shows pictures in the thread, and a picture on its way', function () {
    $request = queuedPicture('my dog');
    $this->actingAs($this->user)->get(route('app.chat', $this->entry->contact))->assertSee('data-sending-photo', false)->assertSee('Maya is sending a photo');

    $this->llm->reply('{"category": "none"}');
    pictures()->generate($request);

    $this->actingAs($this->user)->get(route('app.chat', $this->entry->contact))
        ->assertDontSee('data-sending-photo', false)
        ->assertSee(route('app.images.show', Image::sole()))->assertSee('alt="my dog"', false);
});

it('never gives back more than was used', function () {
    app(Entitlements::class)->release($this->user, Metric::Images);
    app(Entitlements::class)->consume($this->user, Metric::Images);
    app(Entitlements::class)->release($this->user, Metric::Images, 2);

    expect(picturesUsed())->toBe(1);
});

it('tells every contact how to send photos, and what never to send', function () {
    $prompt = app(PromptBuilder::class)->build(UserContact::sole())->text;

    expect($prompt)->toContain('[photo: what it shows]')->toContain('Never a photo of a child or anyone under 18');
});
