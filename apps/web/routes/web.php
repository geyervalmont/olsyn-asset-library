<?php

use App\Http\Controllers\Drives\DriveManifestController;
use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\Prismfs\PrismfsAccessController;
use App\Http\Controllers\Prismfs\PrismfsManifestController;
use App\Http\Controllers\Tenants\SwitchTenantController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('materials.index') : view('welcome'))->name('home');

if (app()->environment(['local', 'testing'])) {
    Route::view('ui', 'ui.index')->name('ui.index');
}

Route::get('prismfs/drives/{drive:slug}/manifest.yaml', PrismfsManifestController::class)->name('prismfs.drives.manifest');
Route::post('prismfs/drives/{drive:slug}/accesses', PrismfsAccessController::class)->name('prismfs.drives.accesses');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::post('tenants/{tenant}/switch', SwitchTenantController::class)->name('tenants.switch');
});

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::livewire('materials', 'pages::materials.index')->name('materials.index');
    Route::livewire('materials/create', 'pages::materials.create')->name('materials.create');
    Route::livewire('materials/{material:code}', 'pages::materials.show')->name('materials.show');
    Route::livewire('drives', 'pages::drives.index')->name('drives.index');
    Route::livewire('jobs', 'pages::jobs.index')->name('jobs.index');
    Route::get('files/{file}/{name?}', FileController::class)->name('files.show');
    Route::get('drives/{drive:slug}/manifest.yaml', DriveManifestController::class)->name('drives.manifest');
});

require __DIR__.'/settings.php';
