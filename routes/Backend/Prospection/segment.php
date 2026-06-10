<?php

use App\Http\Controllers\Backend\SegmentController;
use Illuminate\Support\Facades\Route;

Route::controller(SegmentController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/segments', 'index')->name('segments.index');
    Route::get('/segments/create', 'create')->name('segments.create');
    Route::post('/segments', 'store')->name('segments.store');
    Route::post('/segments/preview', 'preview')->middleware('throttle:60,1')->name('segments.preview');
    Route::get('/segments/{id}', 'view')->name('segments.view');
    Route::get('/segments/{id}/edit', 'edit')->name('segments.edit');
    Route::put('/segments/{id}', 'update')->name('segments.update');
    Route::delete('/segments/{id}', 'delete')->name('segments.delete');
    Route::put('/segments/executeSwitch/{id}', 'executeSwitch')->name('segments.executeSwitch');
});
