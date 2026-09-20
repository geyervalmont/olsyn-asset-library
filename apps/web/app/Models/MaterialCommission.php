<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property int $tenant_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string $trace_id
 * @property string $bender_job_id
 * @property string $brief
 * @property string $status
 * @property array<string, array<string, mixed>>|null $artifacts
 * @property array<string, mixed>|null $provenance
 * @property int|null $studio_draft_id
 * @property-read StudioDraft|null $draft
 */
class MaterialCommission extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<StudioDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(StudioDraft::class, 'studio_draft_id');
    }

    protected function casts(): array
    {
        return ['artifacts' => 'array', 'provenance' => 'array'];
    }
}
