<?php

namespace App\Http\Controllers\Prismfs;

use App\Library\Drives\DriveNamespace;
use App\Models\Drive;
use App\Models\FileAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The access trail PrismFS ships: one event per filesystem operation on a
 * drive, mapped back to library files through the drive's namespace.
 * Batches are idempotent on request id, so a retried batch records nothing twice.
 */
class PrismfsAccessController
{
    public const MAX_BATCH = 500;

    public function __invoke(Request $request, Drive $drive, DriveNamespace $namespace): JsonResponse
    {
        if (! $drive->is_active || ! $drive->tokenMatches($request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized'], 401, ['WWW-Authenticate' => 'Bearer realm="opal-drive"']);
        }

        /** @var array{events: list<array{request_id: string, occurred_at: string, principal?: string|null, operation: string, path: string, result: string, bytes?: int|null, duration_ms?: float|null}>} $validated */
        $validated = $request->validate([
            'events' => ['required', 'array', 'max:'.self::MAX_BATCH],
            'events.*.request_id' => ['required', 'string', 'max:64'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.principal' => ['nullable', 'string', 'max:255'],
            'events.*.operation' => ['required', 'string', 'max:24'],
            'events.*.path' => ['required', 'string', 'max:1024'],
            'events.*.result' => ['required', 'string', 'max:16'],
            'events.*.bytes' => ['nullable', 'integer', 'min:0'],
            'events.*.duration_ms' => ['nullable', 'numeric', 'min:0'],
        ]);

        $index = $this->pathIndex($drive, $namespace);
        $rows = [];

        foreach ($validated['events'] as $event) {
            $rows[] = [
                'file_id' => $index[$event['path']] ?? null,
                'channel' => FileAccess::CHANNEL_PRISMFS,
                'action' => $event['operation'],
                'result' => $event['result'],
                'drive_id' => $drive->getKey(),
                'request_id' => $event['request_id'],
                'principal' => $event['principal'] ?? null,
                'path' => $event['path'],
                'bytes' => $event['bytes'] ?? null,
                'duration_ms' => $event['duration_ms'] ?? null,
                'accessed_at' => Carbon::parse($event['occurred_at'])->utc()->toDateTimeString(),
            ];
        }

        $recorded = FileAccess::query()->insertOrIgnore($rows);

        return response()->json(['accepted' => count($rows), 'recorded' => $recorded], 202);
    }

    /**
     * Drive path => file id for what the drive currently projects, cached briefly.
     *
     * @return array<string, int>
     */
    private function pathIndex(Drive $drive, DriveNamespace $namespace): array
    {
        /** @var array<string, int> $index */
        $index = Cache::remember('opal:drive-paths:'.$drive->getKey(), now()->addMinute(), function () use ($drive, $namespace): array {
            $index = [];

            foreach ($namespace->entries($drive) as $entry) {
                $index[$entry['path']] = $entry['file_id'];
            }

            return $index;
        });

        return $index;
    }
}
