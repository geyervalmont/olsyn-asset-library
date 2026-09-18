<?php

namespace App\Console\Commands;

use App\Library\Studio\SynthesisCompute;
use App\Models\SynthesisRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileSynthesis extends Command
{
    protected $signature = 'opal:synthesis:reconcile';

    protected $description = 'Dispatch and reconcile self-hosted material workers and their compute leases';

    public function handle(SynthesisCompute $compute): int
    {
        $lock = Cache::lock('opal:synthesis:reconcile', 300);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $runs = SynthesisRun::query()->whereNull('released_at')->orderBy('id')->get();
            $active = $runs->filter(fn (SynthesisRun $run): bool => $run->allocation !== null)->count();
            foreach ($runs as $run) {
                try {
                    if (! $run->terminal() && ($run->deadline_at->isPast() || $run->revision->draft->state === 'discarded')) {
                        $run->update(['status' => 'cancelled', 'stage' => 'cancelled', 'error' => 'Generation expired or the draft was discarded.']);
                    }
                    if ($run->terminal()) {
                        if ($run->allocation === null) {
                            $run->update(['released_at' => now()]);
                        } else {
                            $compute->release($run);
                            if ($run->released_at !== null) {
                                $active--;
                            }
                        }

                        continue;
                    }
                    if ($run->allocation === null) {
                        if ($active >= (int) config('synthesis.max_concurrent') || ! config('synthesis.enabled')) {
                            continue;
                        }
                        // Persist intent before the network call. A crashed/ambiguous
                        // allocation is not blindly retried on the next scheduler tick.
                        if ($run->status === 'allocating') {
                            $run->update(['status' => 'failed', 'error' => 'Compute allocation was interrupted. Please create a new generation attempt.']);

                            continue;
                        }
                        $claimed = SynthesisRun::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'allocating', 'stage' => 'waiting_for_compute']);
                        if (! $claimed) {
                            continue;
                        }
                        $allocation = $compute->allocate($run);
                        DB::transaction(function () use ($run, $allocation): void {
                            $locked = SynthesisRun::query()->lockForUpdate()->findOrFail($run->id);
                            $locked->update(['allocation' => $allocation, 'status' => $locked->terminal() ? $locked->status : 'starting']);
                        });
                        $run->refresh();
                        $active++;
                        if ($run->terminal()) {
                            continue;
                        }
                    }
                    $compute->heartbeat($run);
                    if ($run->status === 'starting') {
                        $compute->launch($run);
                    }
                    $status = $compute->status($run);
                    if (($status['failed'] ?? 0) > 0 || ($status['succeeded'] ?? 0) > 0) {
                        $run->refresh();
                        if (! $run->terminal()) {
                            $run->update(['status' => 'failed', 'error' => 'The worker stopped without completing its output. See the worker logs.']);
                        }
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    if (! $run->fresh()->terminal()) {
                        $run->update(['status' => 'failed', 'stage' => 'failed', 'error' => 'The compute worker could not continue. Your draft is saved; an administrator can inspect the job logs.']);
                    }
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
