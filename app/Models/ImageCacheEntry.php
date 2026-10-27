<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One variant of a cached picture: a contact's scene, shared by everyone that contact sends it to
 * (App\Services\PictureCache).
 *
 * @property int $id
 * @property string $cache_key
 * @property int $variant
 * @property int $contact_id
 * @property int $contact_version
 * @property string $scene_signature
 * @property int $image_id
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property Carbon $created_at
 * @property-read Contact $contact
 * @property-read Image $image
 */
class ImageCacheEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'image_cache';

    protected $fillable = ['cache_key', 'variant', 'contact_id', 'contact_version', 'scene_signature', 'image_id', 'hits', 'last_hit_at'];

    protected $attributes = ['hits' => 0];

    protected function casts(): array
    {
        return ['variant' => 'integer', 'contact_version' => 'integer', 'hits' => 'integer', 'last_hit_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /**
     * The scene as labelled parts (Subject, Setting, ...), skipping empty ones. A scene that could not be boiled down
     * to parts is just its sorted words.
     *
     * @return array<string, string>
     */
    public function sceneLabels(): array
    {
        $parts = json_decode($this->scene_signature, true);

        if (! is_array($parts)) {
            return ['Scene' => ltrim(Str::after($this->scene_signature, 'words:'))];
        }

        $labels = ['subject' => 'Subject', 'setting' => 'Setting', 'action' => 'Action', 'outfit' => 'Outfit', 'time_of_day' => 'Time of day'];
        $found = [];
        foreach ($labels as $key => $label) {
            if (is_string($parts[$key] ?? null) && $parts[$key] !== '') {
                $found[$label] = $parts[$key];
            }
        }

        return $found;
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /** @return BelongsTo<Image, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }
}
