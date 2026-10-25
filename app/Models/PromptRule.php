<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A rule every contact follows (how they talk, honesty about being an AI, crisis help, lines never crossed).
 * They open every prompt, in `sort` order.
 *
 * @property int $id
 * @property string $key
 * @property string $title
 * @property string $body_md
 * @property int $version
 * @property int $sort
 */
class PromptRule extends Model
{
    protected $fillable = ['key', 'title', 'body_md', 'version', 'sort'];
}
