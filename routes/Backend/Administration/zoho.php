<?php

use App\Http\Controllers\Backend\ZohoController;
use Illuminate\Support\Facades\Route;

Route::controller(ZohoController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/zoho', 'index')->name('zoho.index');
    Route::post('/zoho/sync', 'sync')->name('zoho.sync');
    Route::post('/zoho/sync-templates', 'syncTemplates')->name('zoho.sync_templates');
});
