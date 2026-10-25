<?php

namespace App\Models;

use App\Enums\ModerationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A text, reply, image prompt or call that a safety check blocked or flagged (App\Services\Moderator). Admins review
 * them in /admin/moderation.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $subject_type e.g. message, call
 * @property int|null $subject_id
 * @property string $source user_text | contact_reply | image_prompt | call
 * @property ModerationCategory $category
 * @property string $action blocked | flagged
 * @property string|null $excerpt
 * @property array<string, mixed>|null $details
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property Carbon $created_at
 * @property-read User $user
 */
class ModerationEvent extends Model
{
    public const UPDATED_AT = null;

    public const BLOCKED = 'blocked';

    public const FLAGGED = 'flagged';

    public const USER_TEXT = 'user_text';

    public const CONTACT_REPLY = 'contact_reply';

    public const IMAGE_PROMPT = 'image_prompt';

    public const CALL = 'call';

    protected $fillable = ['user_id', 'subject_type', 'subject_id', 'source', 'category', 'action', 'excerpt', 'details', 'reviewed_at', 'reviewed_by'];

    protected function casts(): array
    {
        return ['category' => ModerationCategory::class, 'details' => 'array', 'reviewed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ties the event to what it was about, once that's saved (the user's message, or a call). */
    public function about(Model $subject): void
    {
        $this->update(['subject_type' => $subject->getTable() === 'calls' ? 'call' : 'message', 'subject_id' => $subject->getKey()]);
    }
}
