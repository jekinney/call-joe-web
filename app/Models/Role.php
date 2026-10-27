<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of admin permissions. The owner role has every permission and can't be edited or deleted.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property list<string> $permissions
 * @property bool $is_owner
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'permissions'];

    protected $attributes = [
        'permissions' => '[]',
        'is_owner' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_owner' => 'boolean',
        ];
    }

    /** The one role that has everything (made by the migration; created here if a test database lacks it). */
    public static function owner(): self
    {
        return self::firstOrCreate(['is_owner' => true], [
            'name' => 'Owner',
            'description' => 'Everything, including roles.',
        ]);
    }

    public function allows(Permission $permission): bool
    {
        return $this->is_owner || in_array($permission->value, $this->permissions, true);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
