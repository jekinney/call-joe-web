<?php

namespace App\Models;

use App\Enums\Feature;
use App\Enums\Metric;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * A plan and what it allows. `limits` looks like:
 *   contacts: 2                                  how many contacts at once (null = no limit)
 *   metered: {text_messages: 50, voice_seconds: 600, images: 5}   per period (see Metric); null = no limit
 *   features: {rated_r: false, voicemail: false, videos: false}   see Feature
 *   llm_tier: small | large                       which model size friends think with
 *   worry_max_stage: 2, away_outreach_days: 2     how far contacts' worry goes when the user is away
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $price_cents
 * @property string|null $braintree_plan_id
 * @property array<string, mixed> $limits
 * @property bool $is_default
 * @property int $sort
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'name', 'price_cents', 'braintree_plan_id', 'limits', 'is_default', 'sort'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'is_default' => 'boolean',
            'price_cents' => 'integer',
        ];
    }

    /** The plan everyone is on unless they have another one (Free). */
    public static function default(): self
    {
        return once(fn () => self::where('is_default', true)->firstOrFail());
    }

    /** How much of a metered thing is allowed per period; null means no limit. */
    public function limit(Metric $metric): ?int
    {
        $value = Arr::get($this->limits, 'metered.'.$metric->value, 0);

        return $value === null ? null : (int) $value;
    }

    public function allows(Feature $feature): bool
    {
        return (bool) Arr::get($this->limits, 'features.'.$feature->value, false);
    }

    /** A plain setting (contacts, llm_tier, worry_max_stage, away_outreach_days). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->limits, $key, $default);
    }

    public function priceLabel(): string
    {
        return $this->price_cents === 0 ? 'Free' : '$'.number_format($this->price_cents / 100, 2).'/month';
    }
}
