<?php

use App\Http\Controllers\Api\SynthesisWorkerController;

// Each endpoint checks a private, short-lived, per-run worker capability.
Route::prefix('synthesis-runs/{run:uuid}')->middleware('throttle:120,1')->group(function () {
    Route::get('/', [SynthesisWorkerController::class, 'show']);
    Route::get('source', [SynthesisWorkerController::class, 'source']);
    Route::post('progress', [SynthesisWorkerController::class, 'progress']);
    Route::post('artifacts/{role}', [SynthesisWorkerController::class, 'artifact']);
    Route::post('complete', [SynthesisWorkerController::class, 'complete']);
});

use App\Http\Controllers\Api\BenderMaterialsController;
use App\Http\Controllers\Api\ClientReleasesController;
use App\Http\Controllers\Api\CommandsController;
use App\Http\Controllers\Api\Drive\DriveHeartbeatController;
use App\Http\Controllers\Api\Drive\IntakeController;
use App\Http\Controllers\Api\Drive\PersonalDriveController;
use App\Http\Controllers\Api\DrivesController;
use App\Http\Controllers\Api\IdentitiesController;
use App\Http\Controllers\Api\LinkController;
use App\Http\Controllers\Api\MaterialsController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\SessionsController;
use App\Http\Controllers\Api\StudioPreviewsController;
use App\Http\Controllers\Api\VariantsController;
use App\Http\Middleware\AuthorizePersonalDrive;
use App\Http\Middleware\EnsureOlsynAccess;
use App\Http\Middleware\RestrictBenderToken;
use App\Http\Middleware\UseCurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OPAL API v1
|--------------------------------------------------------------------------
|
| Bearer tokens issued from Settings → API tokens. Library reads are
| visibility-aware for the token's user. Documentation renders at /docs/api.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('link', [LinkController::class, 'store'])->middleware('throttle:device-link-start')->name('api.link.store');
    Route::get('link/{code}', [LinkController::class, 'show'])->middleware('throttle:device-link-poll')->name('api.link.show');
    Route::get('client-releases/revit', [ClientReleasesController::class, 'show'])
        ->middleware('throttle:300,1')
        ->name('api.client-releases.revit');
    Route::get('client-releases/revit/{revitVersion}', [ClientReleasesController::class, 'showVersion'])
        ->whereIn('revitVersion', array_map('strval', array_keys(config('opal.clients.revit.supported_versions', []))))
        ->middleware('throttle:300,1')
        ->name('api.client-releases.revit.version');
    Route::get('client-releases/revit/{revitVersion}/{channel}', [ClientReleasesController::class, 'legacyVersionChannel'])
        ->whereIn('revitVersion', array_map('strval', array_keys(config('opal.clients.revit.supported_versions', []))))
        ->whereIn('channel', ['development', 'stable'])
        ->middleware('throttle:300,1')
        ->name('api.client-releases.revit.legacy-version-channel');
    Route::get('client-releases/revit/{channel}', [ClientReleasesController::class, 'legacyChannel'])
        ->whereIn('channel', ['development', 'stable'])
        ->middleware('throttle:300,1')
        ->name('api.client-releases.revit.legacy-channel');
});

Route::prefix('v1')->middleware(['auth:sanctum', EnsureOlsynAccess::class, RestrictBenderToken::class])->group(function () {
    Route::prefix('bender')->middleware('throttle:60,1')->group(function () {
        $controller = BenderMaterialsController::class;
        Route::get('identity', [$controller, 'identity']);
        Route::get('materials', [$controller, 'search']);
        Route::post('commissions', [$controller, 'store']);
        Route::get('commissions/{id}', [$controller, 'show'])->whereUuid('id');
        Route::match(['GET', 'POST'], 'commissions/{id}/artifacts/{role}', [$controller, 'artifact'])->whereUuid('id');
        Route::post('commissions/{id}/complete', [$controller, 'complete'])->whereUuid('id');
    });
    Route::prefix('drive')->middleware([UseCurrentTenant::class, AuthorizePersonalDrive::class, 'throttle:600,1'])->group(function () {
        Route::get('bootstrap', [PersonalDriveController::class, 'bootstrap'])->name('api.drive.bootstrap');
        Route::get('manifest', [PersonalDriveController::class, 'manifest'])->name('api.drive.manifest');
        Route::get('files/{derivative}/{file}', [PersonalDriveController::class, 'file'])->whereUuid('derivative')->whereNumber('file')->name('api.drive.files');
        Route::post('heartbeat', DriveHeartbeatController::class)->name('api.drive.heartbeat');
        $intake = IntakeController::class;
        Route::get('intake', [$intake, 'index'])->name('api.drive.intake.index');
        Route::post('intake', [$intake, 'store'])->name('api.drive.intake.store');
        Route::get('intake/{session}', [$intake, 'show'])->whereUuid('session')->name('api.drive.intake.show');
        Route::post('intake/{session}/files', [$intake, 'reserve'])->whereUuid('session')->name('api.drive.intake.reserve');
        Route::put('intake/{session}/files/{file}', [$intake, 'upload'])->whereUuid('session')->whereUuid('file')->name('api.drive.intake.upload');
        Route::post('intake/{session}/submit', [$intake, 'submit'])->whereUuid('session')->name('api.drive.intake.submit');
        Route::delete('intake/{session}', [$intake, 'destroy'])->whereUuid('session')->name('api.drive.intake.destroy');
    });
    Route::get('me', fn (Request $request) => [
        'name' => $request->user()->name,
        'email' => $request->user()->email,
    ]);

    Route::get('materials', [MaterialsController::class, 'index'])->name('api.materials.index');
    Route::get('materials/{code}', [MaterialsController::class, 'show'])->name('api.materials.show');
    Route::get('variants/resolve', [VariantsController::class, 'resolve'])->name('api.variants.resolve');
    Route::post('variants/resolve', [VariantsController::class, 'resolveMany'])->name('api.variants.resolve-many');
    Route::get('variants/{code}', [VariantsController::class, 'show'])->name('api.variants.show');
    Route::get('variants/{code}/paths', [DrivesController::class, 'variantPaths'])->name('api.variants.paths');
    Route::post('variants/{code}/identities', [IdentitiesController::class, 'store'])->name('api.variants.identities.store');
    Route::get('drives', [DrivesController::class, 'index'])->name('api.drives.index');
    Route::get('studio-previews/{preview}/{role}', [StudioPreviewsController::class, 'show'])
        ->whereUuid('preview')
        ->where('role', '[a-z][a-z0-9_]*')
        ->name('api.studio-previews.show');

    Route::get('realtime', RealtimeController::class)->name('api.realtime');
    Route::get('sessions', [SessionsController::class, 'index'])->name('api.sessions.index');
    Route::post('sessions', [SessionsController::class, 'store'])->name('api.sessions.store');
    Route::post('sessions/{session}/heartbeat', [SessionsController::class, 'heartbeat'])->name('api.sessions.heartbeat');
    Route::delete('sessions/{session}', [SessionsController::class, 'destroy'])->name('api.sessions.destroy');
    Route::get('sessions/{session}/commands', [SessionsController::class, 'commands'])->name('api.sessions.commands');
    Route::post('commands/{command}/ack', [CommandsController::class, 'ack'])->name('api.commands.ack');
    Route::post('commands/{command}/result', [CommandsController::class, 'result'])->name('api.commands.result');
});
