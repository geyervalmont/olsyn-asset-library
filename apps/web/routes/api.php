<?php

use App\Http\Controllers\Api\CommandsController;
use App\Http\Controllers\Api\DrivesController;
use App\Http\Controllers\Api\IdentitiesController;
use App\Http\Controllers\Api\LinkController;
use App\Http\Controllers\Api\MaterialsController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\SessionsController;
use App\Http\Controllers\Api\VariantsController;
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
    Route::post('link', [LinkController::class, 'store'])->middleware('throttle:10,1')->name('api.link.store');
    Route::get('link/{code}', [LinkController::class, 'show'])->middleware('throttle:60,1')->name('api.link.show');
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
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

    Route::get('realtime', RealtimeController::class)->name('api.realtime');
    Route::get('sessions', [SessionsController::class, 'index'])->name('api.sessions.index');
    Route::post('sessions', [SessionsController::class, 'store'])->name('api.sessions.store');
    Route::post('sessions/{session}/heartbeat', [SessionsController::class, 'heartbeat'])->name('api.sessions.heartbeat');
    Route::delete('sessions/{session}', [SessionsController::class, 'destroy'])->name('api.sessions.destroy');
    Route::get('sessions/{session}/commands', [SessionsController::class, 'commands'])->name('api.sessions.commands');
    Route::post('commands/{command}/ack', [CommandsController::class, 'ack'])->name('api.commands.ack');
    Route::post('commands/{command}/result', [CommandsController::class, 'result'])->name('api.commands.result');
});
