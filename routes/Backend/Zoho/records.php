<?php

use App\Http\Controllers\Backend\ZohoRecordsController;
use Illuminate\Support\Facades\Route;

Route::controller(ZohoRecordsController::class)->prefix('admin/zoho/records')->name('admin.zoho_records.')->group(function (): void {
    Route::get('/{module}', 'index')->whereIn('module', ['leads', 'accounts', 'contacts', 'deals', 'quotes'])->name('index');
    Route::get('/{module}/data', 'data')->whereIn('module', ['leads', 'accounts', 'contacts', 'deals', 'quotes'])->name('data');
    Route::get('/{module}/export', 'export')->whereIn('module', ['leads', 'accounts', 'contacts', 'deals', 'quotes'])->name('export');
    Route::get('/{module}/{zohoId}', 'show')->whereIn('module', ['leads', 'accounts', 'contacts', 'deals', 'quotes'])->name('show');
});
