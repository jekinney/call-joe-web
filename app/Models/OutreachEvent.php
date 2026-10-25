<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One planned reach-out: a contact texts, calls or leaves a voicemail at due_at. Texts and voicemails are written
 * only when the user is there to read them (App\Services\Outreach::write), keeping due_at as their time.
 *
 * @property int $id
 * @property int $user_contact_id
 * @property string $kind text | call | voicemail
 * @property int $stage
 * @property string $tone
 * @property Carbon $due_at
 * @property string $status planned | done | skipped
 * @property Carbon|null $materialized_at
 * @property int|null $message_id
 * @property int|null $call_id
 * @property-read UserContact $userContact
 */
class OutreachEvent extends Model
{
    public const TEXT = 'text';

    public const CALL = 'call';

    public const VOICEMAIL = 'voicemail';

    public const PLANNED = 'planned';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    protected $fillable = ['user_contact_id', 'kind', 'stage', 'tone', 'due_at', 'status', 'materialized_at', 'message_id', 'call_id'];

    protected $attributes = ['status' => self::PLANNED];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'materialized_at' => 'datetime', 'stage' => 'integer'];
    }

    /** @return BelongsTo<UserContact, $this> */
    public function userContact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class);
    }
}
