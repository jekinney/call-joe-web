<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One picture a contact decided to send ([photo: ...] in a reply), from planning to the message it became
 * (App\Services\Pictures).
 *
 * @property int $id
 * @property int $user_contact_id
 * @property int|null $message_id
 * @property string $prompt what the contact wrote, like "my lunch"
 * @property string $normalized_prompt the full prompt for the provider
 * @property string|null $cache_key
 * @property string|null $scene_signature what the scene boils down to (App\Services\PictureCache); null if personal
 * @property bool $from_cache the picture was reused, not made
 * @property int $cost_micros what the provider charged, in millionths of a dollar (nothing when reused)
 * @property string $provider
 * @property string|null $provider_job_id
 * @property string $status queued | generating | ready | failed | blocked
 * @property string|null $error
 * @property int|null $image_id
 * @property Carbon $created_at
 * @property-read UserContact $userContact
 * @property-read Image|null $image
 */
class ImageRequest extends Model
{
    public const QUEUED = 'queued';

    public const GENERATING = 'generating';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    protected $fillable = ['user_contact_id', 'message_id', 'prompt', 'normalized_prompt', 'cache_key', 'scene_signature', 'provider', 'provider_job_id', 'status', 'from_cache', 'cost_micros', 'error', 'image_id'];

    protected $attributes = ['status' => self::QUEUED, 'from_cache' => false, 'cost_micros' => 0];

    protected function casts(): array
    {
        return ['from_cache' => 'boolean', 'cost_micros' => 'integer'];
    }

    /** @return BelongsTo<UserContact, $this> */
    public function userContact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class)->withTrashed();
    }

    /** @return BelongsTo<Image, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::QUEUED, self::GENERATING], true);
    }
}
