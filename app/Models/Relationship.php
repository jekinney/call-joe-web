<?php

namespace App\Models;

use App\Support\WorryProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a contact is to the user: Mom, Sister, Best friend, Partner... Decides part of the contact's prompt, whether
 * romance is ever allowed (never for family), which contacts can take it (Sister needs a woman), and how the contact
 * worries when the user goes quiet.
 *
 * @property int $id
 * @property string $slug
 * @property string $label
 * @property string $group family | friend | romantic
 * @property string $prompt_md
 * @property bool $allows_romance
 * @property list<string>|null $allowed_genders
 * @property array<string, mixed> $worry_profile
 * @property int $sort
 */
class Relationship extends Model
{
    protected $fillable = ['slug', 'label', 'group', 'prompt_md', 'allows_romance', 'allowed_genders', 'worry_profile', 'sort'];

    protected function casts(): array
    {
        return [
            'allows_romance' => 'boolean',
            'allowed_genders' => 'array',
            'worry_profile' => 'array',
        ];
    }

    public static function bySlug(string $slug): self
    {
        return self::where('slug', $slug)->firstOrFail();
    }

    /**
     * Relationships a contact can have, in display order.
     *
     * @param  Builder<Relationship>  $query
     */
    public function scopeFor(Builder $query, Contact $contact): void
    {
        $query->where(fn ($q) => $q->whereNull('allowed_genders')->orWhereJsonContains('allowed_genders', $contact->gender))
            ->orderBy('sort');
    }

    public function suits(Contact $contact): bool
    {
        return $this->allowed_genders === null || in_array($contact->gender, $this->allowed_genders, true);
    }

    public function worry(): WorryProfile
    {
        return WorryProfile::fromArray($this->worry_profile);
    }

    /** This relationship's prompt with the names filled in. */
    public function promptFor(string $contactName, string $buddyName): string
    {
        return strtr($this->prompt_md, [':contact' => $contactName, ':buddy' => $buddyName]);
    }
}
