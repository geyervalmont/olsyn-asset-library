<?php

use App\Http\Controllers\Api\DrivesController;
use App\Http\Controllers\Api\IdentitiesController;
use App\Http\Controllers\Api\MaterialsController;
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

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('me', fn (Request $request) => [
        'name' => $request->user()->name,
        'email' => $request->user()->email,
    ]);

    Route::get('materials', [MaterialsController::class, 'index'])->name('api.materials.index');
    Route::get('materials/{code}', [MaterialsController::class, 'show'])->name('api.materials.show');
    Route::get('variants/resolve', [VariantsController::class, 'resolve'])->name('api.variants.resolve');
    Route::get('variants/{code}', [VariantsController::class, 'show'])->name('api.variants.show');
    Route::get('variants/{code}/paths', [DrivesController::class, 'variantPaths'])->name('api.variants.paths');
    Route::post('variants/{code}/identities', [IdentitiesController::class, 'store'])->name('api.variants.identities.store');
    Route::get('drives', [DrivesController::class, 'index'])->name('api.drives.index');
});
