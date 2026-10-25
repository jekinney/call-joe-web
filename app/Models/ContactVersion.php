<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One saved version of a contact's identity (persona and looks).
 *
 * @property int $id
 * @property int $contact_id
 * @property int $version
 * @property string $persona_md
 * @property string|null $looks
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read User|null $author
 */
class ContactVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['contact_id', 'version', 'persona_md', 'looks', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
