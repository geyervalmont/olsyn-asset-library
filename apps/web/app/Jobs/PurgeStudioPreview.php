<?php

namespace App\Jobs;

use App\Library\Procedural\StudioPreviewStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Removes transient Studio maps after Revit has had time to download them. */
final class PurgeStudioPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $preview) {}

    public function handle(StudioPreviewStore $store): void
    {
        $store->purge($this->preview);
    }
}
