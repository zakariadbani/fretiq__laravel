<?php

use App\Http\Controllers\Backend\ZohoController;
use Illuminate\Support\Facades\Route;

Route::controller(ZohoController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/zoho', 'index')->name('zoho.index');
    Route::post('/zoho/sync', 'sync')->name('zoho.sync');
    Route::post('/zoho/sync-templates', 'syncTemplates')->name('zoho.sync_templates');
    Route::post('/zoho/v2/sync', 'v2Sync')->name('zoho.v2.sync');
    Route::post('/zoho/v2/backfill', 'v2Backfill')->name('zoho.v2.backfill');
    Route::post('/zoho/v2/retry', 'retry')->name('zoho.v2.retry');
    Route::post('/zoho/v2/mappings', 'mapping')->name('zoho.v2.mapping');
});
