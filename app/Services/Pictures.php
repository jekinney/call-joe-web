<?php

namespace App\Services;

use App\Contracts\ImageGenerator;
use App\Enums\Metric;
use App\Events\ContactMessaged;
use App\Exceptions\ImageException;
use App\Jobs\GenerateImage;
use App\Models\Image;
use App\Models\ImageRequest;
use App\Models\Message;
use App\Models\UserContact;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Pictures contacts send. A contact puts [photo: what it shows] on its own line in a reply (the "Sending photos" rule
 * in every prompt). The reply goes out without it, and the picture follows a little later:
 *
 * 1. request(): uses one of the plan's monthly pictures (none left: no picture) and plans the full prompt from the
 *    contact's looks, always adults only (promptFor).
 * 2. generate() (App\Jobs\GenerateImage, in the background): the safety check on the prompt, then the shared cache
 *    (App\Services\PictureCache: the same contact's same scene, already made for someone), and only then the provider
 *    and our own checks on what came back. A new picture is re-encoded (dropping all metadata), stored privately,
 *    added to the cache, and sent as the contact's message. A picture that fails or is blocked gives its count back.
 */
class Pictures
{
    /** A contact's photo marker: [photo: my lunch]. */
    public const MARKER = '/\[\s*photo\s*:\s*([^\]\n]{1,300})\]/i';

    /** Phone-shaped, like a photo someone snapped. */
    public const WIDTH = 768;

    public const HEIGHT = 1024;

    /** The picture follows the text a little later, like a real one uploading. */
    public const DELAY_SECONDS = 15;

    public function __construct(
        private Entitlements $entitlements,
        private Moderator $moderator,
        private ImageGenerator $generator,
        private PictureCache $cache,
    ) {}

    /**
     * The reply without photo markers, and what the first one shows (null when there's none).
     *
     * @return array{0: string, 1: string|null}
     */
    public function extract(string $text): array
    {
        $scene = preg_match(self::MARKER, $text, $match) ? trim($match[1]) : null;
        $clean = trim((string) preg_replace(["/[ \t]*\[\s*photo\s*:[^\]\n]*\][ \t]*/i", '/\n{3,}/'], ['', "\n\n"], $text));

        return [$clean, $scene !== '' ? $scene : null];
    }

    /** The contact decided to send a picture: queued if the plan has one left this month. */
    public function request(UserContact $entry, string $scene): ?ImageRequest
    {
        if (! $this->entitlements->consume($entry->user, Metric::Images)) {
            return null;
        }

        $request = $entry->imageRequests()->create([
            'prompt' => mb_substr(trim($scene), 0, 300),
            'normalized_prompt' => $this->promptFor($entry, $scene),
            'provider' => $this->generator->name(),
        ]);
        GenerateImage::dispatch($request->id)->delay(now()->addSeconds(self::DELAY_SECONDS));

        return $request;
    }

    /** The full prompt: a casual phone photo of the scene, the contact's looks if they're in it, and adults only. */
    public function promptFor(UserContact $entry, string $scene): string
    {
        $contact = $entry->contact;
        $first = $contact->firstName();
        $looks = trim((string) $contact->looks);

        return implode(' ', array_filter([
            "A casual, realistic smartphone photo that {$first} took and texted to a friend: ".rtrim(trim($scene), '.').'.',
            'Natural light, an everyday setting, no text, captions or watermarks.',
            $looks !== '' ? "If {$first} is in the photo, they look like this: {$looks} {$first} is an adult." : null,
            'Only adults appear in the photo, no children.',
        ]));
    }

    /** Makes, checks and sends one requested picture (from App\Jobs\GenerateImage). */
    public function generate(ImageRequest $request): void
    {
        if (! $request->isPending()) {
            return;
        }

        $entry = $request->userContact()->with(['user', 'contact'])->firstOrFail();
        $request->update(['status' => ImageRequest::GENERATING]);

        // Checked for every user (plans differ: Rated R), even when the picture itself comes from the cache.
        if ($this->moderator->checkImagePrompt($entry->user, $request->normalized_prompt)->blocked) {
            $this->settle($request, $entry, ImageRequest::BLOCKED, 'Stopped by the safety check.');

            return;
        }

        $signature = $this->cache->signature($entry, $request->prompt);
        $key = $signature !== null ? $this->cache->keyFor($entry->contact, $signature) : null;
        $request->update(['scene_signature' => $signature, 'cache_key' => $key]);
        if ($key !== null && ($cached = $this->cache->pick($key, $entry->user))) {
            $this->send($request, $entry, $cached->image, fromCache: true);

            return;
        }

        try {
            $generated = $this->generator->generate($request->normalized_prompt, self::WIDTH, self::HEIGHT);
        } catch (ImageException $e) {
            $this->settle($request, $entry, ImageRequest::FAILED, mb_substr($e->getMessage(), 0, 200));

            return;
        }

        $picture = @imagecreatefromstring($generated->bytes);
        if (! $picture instanceof GdImage) {
            $this->settle($request, $entry, ImageRequest::FAILED, 'The provider sent something that isn\'t a picture.');

            return;
        }
        if ($this->isBlank($picture)) {
            $this->settle($request, $entry, ImageRequest::BLOCKED, 'Refused by the provider\'s safety checker.');

            return;
        }

        $image = $this->store($picture);
        if ($key !== null) {
            $this->cache->add($key, $entry->contact, $signature, $image);
        }
        $request->update(['provider_job_id' => $generated->providerJobId]);
        $this->send($request, $entry, $image);
    }

    /** The picture as the contact's message, live on the user's page. */
    private function send(ImageRequest $request, UserContact $entry, Image $image, bool $fromCache = false): void
    {
        $message = $entry->messages()->create([
            'sender' => Message::FROM_CONTACT,
            'body' => "📷 Photo: {$request->prompt}",
            'meta' => ['image_id' => $image->id, 'image_request_id' => $request->id],
        ]);
        $request->update(['status' => ImageRequest::READY, 'from_cache' => $fromCache, 'image_id' => $image->id, 'message_id' => $message->id]);
        ContactMessaged::dispatch($entry->user, $message);
    }

    /** Re-encoded as a JPEG (which drops all metadata, like location), stored once per distinct picture. */
    private function store(GdImage $picture): Image
    {
        ob_start();
        imagejpeg($picture, null, 88);
        $bytes = (string) ob_get_clean();
        $sha = hash('sha256', $bytes);
        $disk = (string) config('contacts.images_disk');
        $path = 'images/'.substr($sha, 0, 2)."/{$sha}.jpg";
        Storage::disk($disk)->put($path, $bytes);

        return Image::create([
            'disk' => $disk, 'storage_path' => $path, 'width' => imagesx($picture), 'height' => imagesy($picture),
            'bytes' => strlen($bytes), 'sha256' => $sha,
        ]);
    }

    /** All black (or nearly): what a provider's safety checker sends back instead of a picture it refuses. */
    private function isBlank(GdImage $picture): bool
    {
        $width = imagesx($picture);
        $height = imagesy($picture);
        for ($x = 0; $x < 10; $x++) {
            for ($y = 0; $y < 10; $y++) {
                $rgb = imagecolorat($picture, intdiv(($x * 2 + 1) * $width, 20), intdiv(($y * 2 + 1) * $height, 20));
                if ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF) > 30) {
                    return false;
                }
            }
        }

        return true;
    }

    /** A picture that won't be sent: say why, and give its count back. */
    private function settle(ImageRequest $request, UserContact $entry, string $status, string $why): void
    {
        $request->update(['status' => $status, 'error' => $why]);
        $this->entitlements->release($entry->user, Metric::Images);
    }
}
