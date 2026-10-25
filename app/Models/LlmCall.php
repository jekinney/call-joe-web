<?php

namespace App\Models;

use App\Enums\LlmPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI call: which model, how many tokens, how long, what it cost, and whether it worked.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $contact_id
 * @property int|null $llm_model_id
 * @property string $model
 * @property LlmPurpose $purpose
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $latency_ms
 * @property string $status ok | error | fallback
 * @property string|null $error
 * @property int $cost_micros
 * @property Carbon $created_at
 */
class LlmCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'contact_id', 'llm_model_id', 'model', 'purpose', 'prompt_tokens', 'completion_tokens',
        'latency_ms', 'status', 'error', 'cost_micros',
    ];

    protected function casts(): array
    {
        return ['purpose' => LlmPurpose::class, 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Llm, $this> */
    public function llmModel(): BelongsTo
    {
        return $this->belongsTo(Llm::class);
    }
}
