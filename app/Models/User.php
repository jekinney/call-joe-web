<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $display_name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $date_of_birth
 * @property bool $is_admin
 * @property string $timezone
 * @property string $locale
 * @property Carbon|null $last_seen_at
 * @property int|null $plan_id
 * @property Carbon|null $plan_expires_at
 * @property-read Plan|null $plan
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The youngest age allowed to use the app. */
    public const MINIMUM_AGE = 18;

    /**
     * Defaults matching the database's, so a new model has them before it's reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
        'timezone' => 'UTC',
        'locale' => 'en',
    ];

    /**
     * The attributes that are mass assignable. is_admin never is: admins are made with `php artisan app:make-admin`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'email',
        'password',
        'date_of_birth',
        'timezone',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'date_of_birth',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'is_admin' => 'boolean',
            'last_seen_at' => 'datetime',
            'plan_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * Their own plan, if any (no plan, or an expired one, means the default: see App\Services\Entitlements).
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Their plan history, newest first.
     *
     * @return HasMany<PlanChange, $this>
     */
    public function planChanges(): HasMany
    {
        return $this->hasMany(PlanChange::class)->latest('id');
    }

    /**
     * What contacts call them: their chosen name, or their first name.
     */
    public function preferredName(): string
    {
        return $this->display_name ?: Str::before($this->name, ' ');
    }

    /**
     * Their age in whole years today, in their own timezone; null when we don't know their birthday.
     */
    public function age(): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return (int) CarbonImmutable::parse($this->date_of_birth->toDateString())
            ->diffInYears(CarbonImmutable::now($this->timezone)->startOfDay());
    }

    public function isAdult(): bool
    {
        return ($this->age() ?? 0) >= self::MINIMUM_AGE;
    }

    /**
     * A real birthday of someone 18 or older (and not over 120).
     *
     * @return list<string>
     */
    public static function birthDateRules(): array
    {
        return [
            'required',
            'date_format:Y-m-d',
            'before_or_equal:'.now()->subYears(self::MINIMUM_AGE)->toDateString(),
            'after:'.now()->subYears(120)->toDateString(),
        ];
    }

    /**
     * A real timezone name, like America/Chicago.
     *
     * @return list<string>
     */
    public static function timezoneRules(): array
    {
        return ['required', 'string', 'timezone:all'];
    }
}
