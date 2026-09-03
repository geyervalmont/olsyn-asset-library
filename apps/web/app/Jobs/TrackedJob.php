<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkerRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * A queued job with a WorkerRun record: queued by a person or a schedule,
 * every attempt and outcome written down. Subclasses implement execute().
 *
 * Library work is landlord-level: the run remembers which tenant asked for
 * it, but the job itself runs outside any tenant context.
 */
abstract class TrackedJob implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    final public function __construct(public readonly int $runId) {}

    /**
     * The registry key of this job type, e.g. "downscale_representation".
     */
    abstract public static function type(): string;

    /**
     * Do the work. The returned array is stored as the run's result.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function execute(WorkerRun $run): ?array;

    /**
     * Record a run and dispatch the job for it.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function launch(array $payload = [], ?Model $subject = null, ?User $actor = null, ?string $queue = null): WorkerRun
    {
        $run = new WorkerRun([
            'type' => static::type(),
            'status' => RunStatus::Queued,
            'payload' => $payload,
            'actor_id' => $actor?->getKey(),
            'tenant_id' => Tenant::current()?->getKey(),
            'queue' => $queue ?? 'default',
            'queued_at' => now(),
        ]);

        if ($subject !== null) {
            $run->subject()->associate($subject);
        }

        $run->save();

        $job = new static((int) $run->getKey());

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        dispatch($job);

        return $run->refresh();
    }

    /**
     * Queue the same work again, as a new run with the same payload.
     */
    public static function requeue(WorkerRun $previous, ?User $actor = null): WorkerRun
    {
        $subject = $previous->subject_type !== null ? $previous->subject : null;

        return static::launch($previous->payload ?? [], $subject, $actor ?? $previous->actor, $previous->queue);
    }

    public function handle(): void
    {
        $run = WorkerRun::query()->findOrFail($this->runId);
        $run->markRunning();

        try {
            $result = $this->execute($run);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }

        $run->markSucceeded($result);
    }

    public function failed(Throwable $exception): void
    {
        $run = WorkerRun::query()->find($this->runId);

        if ($run !== null && $run->status !== RunStatus::Failed) {
            $run->markFailed($exception->getMessage());
        }
    }
}
