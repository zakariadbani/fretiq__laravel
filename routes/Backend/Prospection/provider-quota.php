<?php

use App\Http\Controllers\Backend\ProviderQuotaController;
use Illuminate\Support\Facades\Route;

Route::controller(ProviderQuotaController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/provider-quota', 'index')->name('provider-quota.index');
    Route::post('/provider-quota/refresh', 'refresh')->name('provider-quota.refresh');
    Route::post('/provider-quota/drain/preview', 'drainPreview')->name('provider-quota.drain-preview');
    Route::post('/provider-quota/drain', 'drain')->name('provider-quota.drain');
});