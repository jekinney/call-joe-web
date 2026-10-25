<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An earlier version of a contact's notes about a user.
 *
 * @property int $id
 * @property int $memory_id
 * @property int $version
 * @property string $notes_md
 * @property string $reason update | forget | reset
 * @property Carbon $created_at
 */
class MemoryVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['memory_id', 'version', 'notes_md', 'reason'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'version' => 'integer'];
    }
}
