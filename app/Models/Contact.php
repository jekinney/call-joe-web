<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An AI friend in the catalog. Who they are (persona_md, looks) is versioned: change it only through
 * App\Services\ContactCatalog, never by saving these fields directly.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $tagline
 * @property string $gender
 * @property string $persona_md
 * @property string|null $looks
 * @property string|null $voice_provider_id
 * @property array<string, mixed>|null $voice_settings
 * @property string|null $avatar_path
 * @property int|null $required_plan_id
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan|null $requiredPlan
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug', 'name', 'tagline', 'gender', 'persona_md', 'looks', 'voice_provider_id', 'voice_settings', 'required_plan_id',
    ];

    protected $attributes = [
        'is_published' => false,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'voice_settings' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<ContactVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContactVersion::class)->orderByDesc('version');
    }

    /**
     * The plan needed to add this contact; null means any plan.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function requiredPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'required_plan_id');
    }

    /**
     * @param  Builder<Contact>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public function firstName(): string
    {
        return strtok($this->name, ' ') ?: $this->name;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk(config('contacts.avatar_disk'))->url($this->avatar_path) : null;
    }

    /**
     * What's still missing before this contact can be published; empty when ready.
     *
     * @return list<string>
     */
    public function missingForPublish(): array
    {
        return array_values(array_filter([
            trim($this->persona_md) === '' ? 'a persona' : null,
            $this->avatar_path ? null : 'a picture',
            $this->voice() ? null : 'a voice',
        ]));
    }

    /**
     * The voice they speak with on calls (a Vapi voice provider and that provider's voice id), or null if none yet.
     *
     * @return array{provider: string, voiceId: string}|null
     */
    public function voice(): ?array
    {
        $provider = $this->voice_settings['provider'] ?? null;

        return $this->voice_provider_id && is_string($provider) && $provider !== ''
            ? ['provider' => $provider, 'voiceId' => $this->voice_provider_id]
            : null;
    }
}
