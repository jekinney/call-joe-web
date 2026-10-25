<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A contact on a user's phone. Removing one archives it (soft delete); adding them again brings the same row back,
 * with everything they shared.
 *
 * @property int $id
 * @property int $user_id
 * @property int $contact_id
 * @property int|null $relationship_id
 * @property string|null $nickname
 * @property bool $is_active
 * @property Carbon $added_at
 * @property Carbon|null $deleted_at
 * @property int|null $unread_count only with withUnreadCount()
 * @property-read Contact $contact
 * @property-read Relationship|null $relationship
 * @property-read Memory|null $memory
 * @property-read User $user
 */
class UserContact extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'contact_id', 'relationship_id', 'nickname', 'is_active', 'added_at'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'added_at' => 'datetime'];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<Call, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /** @return HasOne<Memory, $this> */
    public function memory(): HasOne
    {
        return $this->hasOne(Memory::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Adds unread_count: texts from the contact the user hasn't seen yet (one query for the whole list).
     *
     * @param  Builder<UserContact>  $query
     */
    public function scopeWithUnreadCount(Builder $query): void
    {
        $query->withCount(['messages as unread_count' => fn ($q) => $q->where('sender', Message::FROM_CONTACT)->whereNull('read_at')]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What the user calls them: their nickname, or the contact's first name. */
    public function displayName(): string
    {
        return $this->nickname ?: $this->contact->firstName();
    }
}
