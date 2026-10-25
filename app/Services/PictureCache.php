<?php

namespace App\Services;

use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Contact;
use App\Models\Image;
use App\Models\ImageCacheEntry;
use App\Models\ImageRequest;
use App\Models\User;
use App\Models\UserContact;
use Illuminate\Support\Str;

/**
 * Shares pictures across users: when a contact sends a scene they've sent before (to anyone), a stored picture is
 * reused instead of making a new one.
 *
 * - signature(): the scene boiled down (subject, setting, action, outfit, time of day) by a small AI model, so
 *   "my lunch" and "what I had for lunch" match. If the model can't answer, a word list is used instead. A scene
 *   involving the user ("us at the lake", their name) gets no signature: it's never shared.
 * - keyFor(): the contact, their identity version and looks, and the signature. Changing a contact's persona or looks
 *   starts their cache over.
 * - Each key keeps up to VARIANTS pictures. Until the pool is full, new ones are made; after that they're reused,
 *   preferring one this user hasn't been sent, then the least recently used.
 */
class PictureCache
{
    /** Pictures kept per scene, rotated between users. */
    public const VARIANTS = 3;

    /** A scene nobody's been sent for this long leaves the cache (php artisan pictures:evict). */
    public const EVICT_AFTER_DAYS = 30;

    private const FIELDS = ['action', 'outfit', 'setting', 'subject', 'time_of_day'];

    private const STOPWORDS = ['a', 'an', 'and', 'at', 'for', 'from', 'here', 'i', 'in', 'is', 'just', 'me', 'my', 'of', 'on', 'some', 'that', 'the', 'this', 'to', 'today', 'with'];

    private const SIGNATURE_PROMPT = <<<'TXT'
        Boil a photo description down to JSON with exactly these keys: subject, setting, action, outfit, time_of_day.
        Use one to three plain lowercase words for each, or "" when it isn't said. Different wordings of the same scene
        must give the same answer: "my lunch" and "what I had for lunch" are both {"subject": "lunch", "setting": "", "action": "", "outfit": "", "time_of_day": ""}.
        Answer with JSON only.
        TXT;

    public function __construct(private LlmGateway $gateway) {}

    /** The scene's signature, or null when it involves the user, so it must never be shared. */
    public function signature(UserContact $entry, string $scene): ?string
    {
        if ($this->isPersonal($entry->user, $scene)) {
            return null;
        }

        try {
            $answer = $this->gateway->chat(LlmPurpose::Scenes, [
                ['role' => 'system', 'content' => self::SIGNATURE_PROMPT],
                ['role' => 'user', 'content' => mb_substr($scene, 0, 300)],
            ], $entry->user, $entry->contact, ['max_tokens' => 120, 'temperature' => 0.0]);
            $fields = json_decode((string) Str::of($answer->text)->match('/\{.*\}/s'), true);
        } catch (LlmException) {
            $fields = null;
        }

        if (is_array($fields) && array_intersect(self::FIELDS, array_keys($fields)) !== []) {
            $clean = [];
            foreach (self::FIELDS as $field) {
                $clean[$field] = $this->words(is_string($fields[$field] ?? null) ? $fields[$field] : '');
            }
            if (implode('', $clean) !== '') {
                return (string) json_encode($clean);
            }
        }

        return 'words:'.$this->words($scene, sorted: true);
    }

    public function keyFor(Contact $contact, string $signature): string
    {
        return hash('sha256', implode('|', [$contact->id, $contact->version, hash('sha256', (string) $contact->looks), $signature]));
    }

    /** A stored picture for this user, once the scene's pool is full (null: make a new one). */
    public function pick(string $key, User $user): ?ImageCacheEntry
    {
        $pool = ImageCacheEntry::with('image')->where('cache_key', $key)->get();
        if ($pool->count() < self::VARIANTS) {
            return null;
        }

        $alreadySent = ImageRequest::whereIn('image_id', $pool->pluck('image_id'))
            ->whereHas('userContact', fn ($q) => $q->where('user_id', $user->id))->pluck('image_id')->all();
        $leastUsed = fn (ImageCacheEntry $entry) => [$entry->last_hit_at?->getTimestamp() ?? 0, $entry->variant];
        $choice = $pool->reject(fn (ImageCacheEntry $entry) => in_array($entry->image_id, $alreadySent, true))->sortBy($leastUsed)->first()
            ?? $pool->sortBy($leastUsed)->firstOrFail();

        $choice->increment('hits', 1, ['last_hit_at' => now()]);

        return $choice;
    }

    /** A newly made picture joins the scene's pool, if there's room. */
    public function add(string $key, Contact $contact, string $signature, Image $image): void
    {
        $variant = ImageCacheEntry::where('cache_key', $key)->count();
        if ($variant >= self::VARIANTS) {
            return;
        }

        // If another picture took that spot at the same moment, this one is still sent, just not shared. (An insert
        // that skips conflicts, so the database doesn't abort a surrounding transaction.)
        ImageCacheEntry::insertOrIgnore([
            'cache_key' => $key, 'variant' => $variant, 'contact_id' => $contact->id, 'contact_version' => $contact->version,
            'scene_signature' => $signature, 'image_id' => $image->id, 'hits' => 0, 'created_at' => now(),
        ]);
    }

    /** Forgets a contact's cached pictures (from /admin/pictures). Returns how many. */
    public function purge(Contact $contact): int
    {
        return ImageCacheEntry::where('contact_id', $contact->id)->delete();
    }

    /**
     * Drops scenes nobody's been sent in a while. The pictures stay stored: each one is still in the threads it was
     * sent to.
     */
    public function evict(int $days = self::EVICT_AFTER_DAYS): int
    {
        return ImageCacheEntry::whereRaw('COALESCE(last_hit_at, created_at) < ?', [now()->subDays($days)])->delete();
    }

    /** About the user ("you", "us", their name): never shared with anyone else. */
    private function isPersonal(User $user, string $scene): bool
    {
        if (preg_match("/\b(you|your|yours|you're|us|we|our|ours)\b/i", $scene)) {
            return true;
        }

        $names = array_filter([Str::before($user->name, ' '), Str::afterLast($user->name, ' '), $user->display_name], fn ($name) => mb_strlen((string) $name) > 1);
        foreach ($names as $name) {
            if (preg_match('/\b'.preg_quote((string) $name, '/').'\b/iu', $scene)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase words without punctuation (and, for the word list, without filler words, in order). */
    private function words(string $text, bool $sorted = false): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($sorted) {
            $words = array_unique(array_diff($words, self::STOPWORDS));
            sort($words);
        }

        return implode(' ', $words);
    }
}
