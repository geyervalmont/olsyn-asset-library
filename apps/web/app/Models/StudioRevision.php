<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $studio_draft_id
 * @property int|null $parent_id
 * @property int|null $representation_id
 * @property array<string, mixed> $document
 * @property array<string, array<string, mixed>>|null $artifacts
 * @property-read StudioDraft $draft
 * @property-read SynthesisRun|null $run
 */
class StudioRevision extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (StudioRevision $revision): void {
            throw_if($revision->isDirty(['document', 'parent_id', 'studio_draft_id']), \LogicException::class, 'Revision inputs are immutable.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['document' => 'array', 'artifacts' => 'array'];
    }

    /** @return BelongsTo<StudioDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(StudioDraft::class, 'studio_draft_id');
    }

    /** @return HasOne<SynthesisRun, $this> */
    public function run(): HasOne
    {
        return $this->hasOne(SynthesisRun::class);
    }

    public function label(): string
    {
        if ($this->run !== null) {
            return match ($this->run->status) {
                'succeeded' => 'Generated material',
                'failed' => 'Generation failed',
                'cancelled' => 'Generation cancelled',
                default => $this->run->stageLabel(),
            };
        }

        return ! empty($this->document['edits']) ? 'Finish adjustment'
            : (isset($this->artifacts['base_color']) ? 'Saved material' : 'Source preparation');
    }

    public function previewRole(): string
    {
        return isset($this->artifacts['base_color']) ? 'base_color' : 'source';
    }
}
