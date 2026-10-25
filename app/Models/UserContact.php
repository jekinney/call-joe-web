<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property-read Contact $contact
 * @property-read Relationship|null $relationship
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
