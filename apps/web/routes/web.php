<?php

use App\Http\Controllers\Clients\RevitDownloadController;
use App\Http\Controllers\Drives\DriveManifestController;
use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\OlsynLoginController;
use App\Http\Controllers\Prismfs\PrismfsAccessController;
use App\Http\Controllers\Prismfs\PrismfsManifestController;
use App\Http\Controllers\PublicMaterialController;
use App\Http\Controllers\PublicMaterialPreviewController;
use App\Http\Controllers\PublicMaterialQrCodeController;
use App\Http\Controllers\StudioLivePreviewController;
use App\Http\Controllers\Tenants\SwitchTenantController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('materials.index') : view('welcome'))->name('home');

Route::middleware(['signed', 'throttle:120,1'])->group(function () {
    Route::get('m/{material}/qr.svg', PublicMaterialQrCodeController::class)->name('materials.public.qr');
    Route::get('m/{material}/preview/{variant}', PublicMaterialPreviewController::class)->name('materials.public.preview');
    Route::get('m/{material}', PublicMaterialController::class)->name('materials.public');
});

if (app()->environment(['local', 'testing'])) {
    Route::view('ui', 'ui.index')->name('ui.index');
}

Route::get('prismfs/drives/{drive:slug}/manifest.yaml', PrismfsManifestController::class)->name('prismfs.drives.manifest');
Route::post('prismfs/drives/{drive:slug}/accesses', PrismfsAccessController::class)->name('prismfs.drives.accesses');
Route::get('downloads/revit/{asset}', [RevitDownloadController::class, 'show'])
    ->whereIn('asset', ['installer', 'package'])
    ->name('revit.download');
Route::get('downloads/revit/{revitVersion}/{asset}', [RevitDownloadController::class, 'showVersion'])
    ->whereIn('revitVersion', array_map('strval', array_keys(config('opal.clients.revit.supported_versions', []))))
    ->whereIn('asset', ['installer', 'package'])
    ->name('revit.download.version');
Route::get('downloads/revit/{revitVersion}/{channel}/{asset}', [RevitDownloadController::class, 'legacyVersionChannel'])
    ->whereIn('revitVersion', array_map('strval', array_keys(config('opal.clients.revit.supported_versions', []))))
    ->whereIn('channel', ['development', 'stable'])
    ->whereIn('asset', ['installer', 'package'])
    ->name('revit.download.legacy-version-channel');
Route::get('downloads/revit/{channel}/{asset}', [RevitDownloadController::class, 'legacyChannel'])
    ->whereIn('channel', ['development', 'stable'])
    ->whereIn('asset', ['installer', 'package'])
    ->name('revit.download.legacy-channel');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('link', 'pages::link')->name('link');
    Route::post('tenants/{tenant}/switch', SwitchTenantController::class)->name('tenants.switch');
});

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::livewire('materials', 'pages::materials.index')->name('materials.index');
    Route::livewire('materials/create', 'pages::materials.create')->name('materials.create');
    Route::livewire('materials/studio', 'pages::materials.studio')->name('materials.studio');
    Route::get('studio-preview/{digest}/{role}', StudioLivePreviewController::class)
        ->where('digest', '[a-f0-9]{64}')
        ->whereIn('role', ['base_color', 'normal', 'roughness', 'height', 'metallic'])
        ->name('studio-preview.show');
    Route::livewire('materials/{material:code}', 'pages::materials.show')->name('materials.show');
    Route::livewire('quality', 'pages::quality.index')->name('quality.index');
    Route::livewire('drives', 'pages::drives.index')->name('drives.index');
    Route::livewire('jobs', 'pages::jobs.index')->name('jobs.index');
    Route::get('files/{file}/{name?}', FileController::class)->name('files.show');
    Route::get('drives/{drive:slug}/manifest.yaml', DriveManifestController::class)->name('drives.manifest');
});

require __DIR__.'/settings.php';

Route::get('auth/login', [OlsynLoginController::class, 'login'])->middleware('throttle:20,1')->name('olsyn.login');
Route::get('auth/callback', [OlsynLoginController::class, 'callback'])->middleware('throttle:20,1')->name('olsyn.callback');
