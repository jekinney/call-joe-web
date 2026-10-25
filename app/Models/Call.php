<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One voice call with a contact.
 *
 * @property int $id
 * @property int $user_contact_id
 * @property string $direction outgoing | incoming
 * @property string $status starting | active | ended | failed
 * @property string|null $vapi_assistant_id
 * @property string|null $vapi_call_id
 * @property string $prompt_hash
 * @property int $max_seconds
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property string|null $ended_reason
 * @property string|null $transcript
 * @property int|null $cost_micros
 * @property-read UserContact $userContact
 */
class Call extends Model
{
    public const STARTING = 'starting';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_contact_id', 'direction', 'status', 'vapi_assistant_id', 'vapi_call_id', 'prompt_hash', 'max_seconds',
        'started_at', 'ended_at', 'duration_seconds', 'ended_reason', 'transcript', 'cost_micros',
    ];

    /** Defaults matching the database's, so a new call has them before it's reloaded. */
    protected $attributes = ['status' => self::STARTING, 'direction' => 'outgoing'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'duration_seconds' => 'integer', 'max_seconds' => 'integer'];
    }

    /** @return BelongsTo<UserContact, $this> */
    public function userContact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class)->withTrashed();
    }

    public function isOver(): bool
    {
        return in_array($this->status, [self::ENDED, self::FAILED], true);
    }

    /** "4 min" or "35 sec". */
    public function lengthLabel(): string
    {
        $seconds = (int) $this->duration_seconds;

        return $seconds >= 60 ? intdiv($seconds, 60).' min' : $seconds.' sec';
    }
}
