<?php

namespace App\Models;

use App\Enums\LlmPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI model contacts can think with, managed in /admin/models.
 *
 * @property int $id
 * @property string $provider
 * @property string $model_id
 * @property string $label
 * @property LlmPurpose $purpose
 * @property string|null $plan_tier
 * @property bool $is_active
 * @property int|null $fallback_id
 * @property string $input_cents_per_mtok
 * @property string $output_cents_per_mtok
 * @property int $max_tokens
 * @property int $sort
 * @property-read Llm|null $fallback
 */
class Llm extends Model
{
    protected $table = 'llm_models';

    protected $fillable = [
        'provider', 'model_id', 'label', 'purpose', 'plan_tier', 'is_active', 'fallback_id',
        'input_cents_per_mtok', 'output_cents_per_mtok', 'max_tokens', 'sort',
    ];

    protected $attributes = ['provider' => 'groq', 'is_active' => true, 'max_tokens' => 1024, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'purpose' => LlmPurpose::class,
            'is_active' => 'boolean',
            'max_tokens' => 'integer',
            'input_cents_per_mtok' => 'decimal:2',
            'output_cents_per_mtok' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Llm, $this> */
    public function fallback(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_id');
    }

    /** What a call cost, in millionths of a dollar. */
    public function costMicros(int $promptTokens, int $completionTokens): int
    {
        // A token costs (cents per million) / 100 / 1,000,000 dollars, which is (cents per million) / 100 micro-dollars.
        return (int) round(($promptTokens * (float) $this->input_cents_per_mtok + $completionTokens * (float) $this->output_cents_per_mtok) / 100);
    }
}
