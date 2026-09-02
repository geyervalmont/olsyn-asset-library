<?php

use App\Http\Controllers\Tenants\SwitchTenantController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

if (app()->environment(['local', 'testing'])) {
    Route::view('ui', 'ui.index')->name('ui.index');
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::post('tenants/{tenant}/switch', SwitchTenantController::class)->name('tenants.switch');
});

require __DIR__.'/settings.php';
