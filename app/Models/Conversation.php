<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One stretch of texting with a contact, with the prompt it started with (kept unchanged until it ends).
 *
 * @property int $id
 * @property int $user_contact_id
 * @property string $prompt_text
 * @property string $prompt_hash
 * @property Carbon $started_at
 * @property Carbon $last_message_at
 */
class Conversation extends Model
{
    protected $fillable = ['user_contact_id', 'prompt_text', 'prompt_hash', 'started_at', 'last_message_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_message_at' => 'datetime'];
    }

    /** @return BelongsTo<UserContact, $this> */
    public function userContact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class)->withTrashed();
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
