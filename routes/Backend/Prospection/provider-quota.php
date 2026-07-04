<?php

use App\Http\Controllers\Backend\ProviderQuotaController;
use Illuminate\Support\Facades\Route;

Route::controller(ProviderQuotaController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/provider-quota', 'index')->name('provider-quota.index');
});