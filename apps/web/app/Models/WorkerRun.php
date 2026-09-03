<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One piece of background work: queued by a person or a schedule, executed
 * by a worker, and recorded whatever happens. Provenance events reference
 * the run through their job_id.
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property RunStatus $status
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property int $attempts
 * @property int|null $actor_id
 * @property int|null $tenant_id
 * @property string|null $queue
 * @property CarbonInterface $queued_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property int|null $duration_ms
 * @property-read User|null $actor
 */
#[Fillable(['type', 'status', 'payload', 'result', 'error', 'attempts', 'actor_id', 'tenant_id', 'queue', 'queued_at', 'started_at', 'finished_at', 'duration_ms'])]
class WorkerRun extends Model
{
    protected static function booted(): void
    {
        static::creating(function (WorkerRun $run): void {
            $run->uuid ??= (string) Str::uuid();

            if (! array_key_exists('queued_at', $run->getAttributes())) {
                $run->queued_at = now();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => RunStatus::Running, 'started_at' => now(), 'attempts' => $this->attempts + 1])->save();
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function markSucceeded(?array $result = null): void
    {
        $this->finish(RunStatus::Succeeded, result: $result);
    }

    public function markFailed(string $error): void
    {
        $this->finish(RunStatus::Failed, error: Str::limit($error, 4000));
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finish(RunStatus $status, ?array $result = null, ?string $error = null): void
    {
        $finished = now();

        $this->forceFill([
            'status' => $status,
            'result' => $result ?? $this->result,
            'error' => $error,
            'finished_at' => $finished,
            'duration_ms' => $this->started_at === null ? null : max(0, (int) $this->started_at->diffInMilliseconds($finished)),
        ])->save();
    }

    /**
     * @param  Builder<WorkerRun>  $query
     * @return Builder<WorkerRun>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value]);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
