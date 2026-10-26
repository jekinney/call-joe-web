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
 * @property string $status ringing | missed (incoming only) | starting | active | ended | failed
 * @property string|null $voice_session_id the voice service's id for this call
 * @property int|null $llm_model_id the live model the call used
 * @property string|null $prompt_hash none until it's answered
 * @property int $max_seconds
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property string|null $ended_reason
 * @property string|null $transcript
 * @property int|null $cost_micros
 * @property-read UserContact $userContact
 * @property-read Llm|null $llmModel
 */
class Call extends Model
{
    public const STARTING = 'starting';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const FAILED = 'failed';

    /** A contact is calling the user right now. */
    public const RINGING = 'ringing';

    /** A contact called and the user didn't answer (or was away). */
    public const MISSED = 'missed';

    public const INCOMING = 'incoming';

    protected $fillable = [
        'user_contact_id', 'direction', 'status', 'voice_session_id', 'llm_model_id', 'prompt_hash', 'max_seconds',
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

    /** @return BelongsTo<Llm, $this> */
    public function llmModel(): BelongsTo
    {
        return $this->belongsTo(Llm::class);
    }

    public function isOver(): bool
    {
        return in_array($this->status, [self::ENDED, self::FAILED, self::MISSED], true);
    }

    /** "4 min" or "35 sec". */
    public function lengthLabel(): string
    {
        $seconds = (int) $this->duration_seconds;

        return $seconds >= 60 ? intdiv($seconds, 60).' min' : $seconds.' sec';
    }
}
