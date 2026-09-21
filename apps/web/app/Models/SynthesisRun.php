<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $studio_revision_id
 * @property string $status
 * @property string $stage
 * @property string $worker_token
 * @property array<string, mixed>|null $allocation
 * @property array<string, array<string, mixed>>|null $artifacts
 * @property array<string, mixed>|null $manifest
 * @property string|null $error
 * @property Carbon $deadline_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $released_at
 * @property-read StudioRevision $revision
 */
class SynthesisRun extends Model
{
    protected $guarded = [];

    protected $hidden = ['worker_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['worker_token' => 'encrypted', 'allocation' => 'array', 'artifacts' => 'array', 'manifest' => 'array', 'deadline_at' => 'datetime', 'heartbeat_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /** @return BelongsTo<StudioRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(StudioRevision::class, 'studio_revision_id');
    }

    public function runtime(string $key): string
    {
        return (string) ($this->manifest['runtime'][$key] ?? '');
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled'], true);
    }

    public function jobName(): string
    {
        return 'synthesis-'.$this->uuid;
    }

    /** @return array<string, string> */
    public function stages(): array
    {
        $stages = ['queued' => 'Queued', 'waiting_for_compute' => 'Starting compute',
            'loading_models' => 'Loading models', 'preparing_photo' => 'Preparing photo'];
        if (($this->revision->document['cleanup'] ?? 0) > 0) {
            $stages['cleaning_photo'] = 'Cleaning glare';
        }

        return $stages + ['estimating_material' => 'Estimating surface', 'uploading_maps' => 'Saving maps', 'complete' => 'Ready'];
    }

    public function stageLabel(): string
    {
        return $this->stages()[$this->stage] ?? ucfirst(str_replace('_', ' ', $this->stage));
    }
}
