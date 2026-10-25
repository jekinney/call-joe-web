<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One text, from the user or the contact.
 *
 * @property int $id
 * @property int $user_contact_id
 * @property int|null $conversation_id
 * @property string $sender user | contact
 * @property string $body
 * @property array<string, mixed>|null $meta e.g. reply_failed: true when the contact couldn't answer it
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
class Message extends Model
{
    public const UPDATED_AT = null;

    public const FROM_USER = 'user';

    public const FROM_CONTACT = 'contact';

    protected $fillable = ['user_contact_id', 'conversation_id', 'sender', 'body', 'meta', 'read_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<UserContact, $this> */
    public function userContact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class)->withTrashed();
    }

    public function fromUser(): bool
    {
        return $this->sender === self::FROM_USER;
    }

    public function replyFailed(): bool
    {
        return (bool) ($this->meta['reply_failed'] ?? false);
    }
}
