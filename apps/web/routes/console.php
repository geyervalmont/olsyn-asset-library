<?php

use App\Models\DriveTelemetryEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('opal:embeddings:index --stale')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('opal.embeddings.enabled'));

Schedule::command('opal:synthesis:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('opal:drive:prune-telemetry', function () {
    $deleted = DriveTelemetryEvent::query()->where('received_at', '<', now()->subDays(30))->delete();
    $this->info("Pruned {$deleted} drive telemetry events.");
})->purpose('Remove drive telemetry older than 30 days');

Schedule::command('opal:drive:prune-telemetry')->daily()->withoutOverlapping();
