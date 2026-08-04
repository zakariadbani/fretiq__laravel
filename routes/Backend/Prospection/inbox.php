<?php

use App\Http\Controllers\Backend\InboxEmailController;
use Illuminate\Support\Facades\Route;

Route::controller(InboxEmailController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/inbox', 'index')->name('inbox.index');
    Route::post('/inbox/resync', 'resync')->name('inbox.resync');
    Route::get('/inbox/{id}', 'view')->whereNumber('id')->name('inbox.view');
    Route::post('/inbox/{id}/status', 'updateStatus')->whereNumber('id')->name('inbox.status');
    Route::post('/inbox/{id}/triage', 'triage')->whereNumber('id')->name('inbox.triage');
});
