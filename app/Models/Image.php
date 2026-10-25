<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A stored picture (a JPEG with its metadata stripped) on the images disk (config/contacts.php).
 *
 * @property int $id
 * @property string $disk
 * @property string $storage_path
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property string $sha256
 * @property float|null $nsfw_score
 * @property Carbon $created_at
 */
class Image extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['disk', 'storage_path', 'width', 'height', 'bytes', 'sha256', 'nsfw_score'];

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'bytes' => 'integer', 'nsfw_score' => 'float', 'created_at' => 'datetime'];
    }

    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->storage_path);
    }
}
