<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What one contact remembers about one user. Change it only through App\Services\MemoryWriter, which keeps versions.
 *
 * @property int $id
 * @property int $user_contact_id
 * @property string $notes_md
 * @property list<string>|null $forgotten
 * @property int $version
 * @property int|null $last_message_id
 * @property Carbon|null $updated_at
 */
class Memory extends Model
{
    protected $fillable = ['user_contact_id', 'notes_md', 'forgotten', 'version', 'last_message_id'];

    protected $attributes = ['notes_md' => '', 'version' => 0];

    protected function casts(): array
    {
        return ['forgotten' => 'array', 'version' => 'integer', 'last_message_id' => 'integer'];
    }

    /** @return BelongsTo<UserContact, $this> */
    public function userContact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class)->withTrashed();
    }

    /** @return HasMany<MemoryVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(MemoryVersion::class)->orderByDesc('version');
    }

    /**
     * The notes as separate lines (bullets without their "- ").
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_filter(array_map(
            fn (string $line) => trim(preg_replace('/^\s*[-*•]\s*/u', '', $line) ?? ''),
            explode("\n", $this->notes_md),
        ), fn (string $line) => $line !== ''));
    }
}
