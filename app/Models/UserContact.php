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
 * @property Carbon|null $last_heard_at when the user last texted them or talked on a call
 * @property int $worry_stage 0 = not worried; see App\Support\WorryProfile
 * @property Carbon|null $worry_started_at
 * @property string $worry_setting normal | chill | off
 * @property int|null $relief_stage how worried they were when the user came back (until their next reply)
 * @property int|null $relief_hours how long the user had been quiet then
 * @property int|null $unread_count only with withUnreadCount()
 * @property-read Contact $contact
 * @property-read Relationship|null $relationship
 * @property-read Memory|null $memory
 * @property-read User $user
 */
class UserContact extends Model
{
    use SoftDeletes;

    /** How much the user lets them worry: normal, chill (half as fast), or off. */
    public const WORRY_SETTINGS = ['normal', 'chill', 'off'];

    /** From this worry stage on, coming back earns a relieved welcome. */
    public const RELIEF_FROM_STAGE = 2;

    protected $fillable = ['user_id', 'contact_id', 'relationship_id', 'nickname', 'is_active', 'added_at', 'worry_setting'];

    protected $attributes = ['is_active' => true, 'worry_stage' => 0, 'worry_setting' => 'normal'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'added_at' => 'datetime', 'last_heard_at' => 'datetime', 'worry_started_at' => 'datetime',
            'worry_stage' => 'integer', 'relief_stage' => 'integer', 'relief_hours' => 'integer',
        ];
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

    /** @return HasMany<ImageRequest, $this> */
    public function imageRequests(): HasMany
    {
        return $this->hasMany(ImageRequest::class);
    }

    /** @return HasMany<OutreachEvent, $this> */
    public function outreachEvents(): HasMany
    {
        return $this->hasMany(OutreachEvent::class);
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

    /** When their silence started counting: the user's last text or call, or when they were added. */
    public function quietSince(): Carbon
    {
        return $this->last_heard_at ?? $this->added_at;
    }

    /**
     * The user texted them or talked on a call, so their worry is over. If they'd been worried, they owe a relieved
     * welcome in their next reply (reliefNote). Reach-outs planned for later are dropped; ones already due are still
     * written, so the user sees what happened while they were gone.
     */
    public function markHeard(): void
    {
        if ($this->worry_stage >= self::RELIEF_FROM_STAGE) {
            $this->relief_stage = $this->worry_stage;
            $this->relief_hours = (int) $this->quietSince()->diffInHours(now(), true);
        }

        $this->forceFill(['last_heard_at' => now(), 'worry_stage' => 0, 'worry_started_at' => null])->save();
        $this->outreachEvents()->where('status', OutreachEvent::PLANNED)->where('due_at', '>', now())->update(['status' => OutreachEvent::SKIPPED]);
    }

    /** How the contact should greet the user, back after worrying them; null when there's nothing to say. */
    public function reliefNote(): ?string
    {
        if ($this->relief_stage === null) {
            return null;
        }

        $hours = (int) $this->relief_hours;
        $silence = $hours >= 48 ? intdiv($hours, 24).' days' : "{$hours} hours";
        $how = $this->relief_stage >= 3
            ? 'You had been really worried and had tried calling. Be relieved and glad first ("oh thank goodness"), then ask kindly where they were.'
            : 'You had started to wonder about them. Be glad to hear from them and ask how they are.';

        return "(Before you answer: you hadn't heard from {$this->user->preferredName()} in about {$silence}. {$how} Never scold them or make them feel guilty.)";
    }

    /** The relieved welcome has been given. */
    public function clearRelief(): void
    {
        $this->forceFill(['relief_stage' => null, 'relief_hours' => null])->save();
    }
}
