<?php

use App\Http\Controllers\Backend\SuppressionController;
use Illuminate\Support\Facades\Route;

Route::controller(SuppressionController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/suppressions', 'index')->name('suppressions.index');
    Route::get('/suppressions/create', 'create')->name('suppressions.create');
    Route::post('/suppressions', 'store')->name('suppressions.store');
    Route::get('/suppressions/{id}', 'view')->name('suppressions.view');
    Route::get('/suppressions/{id}/edit', 'edit')->name('suppressions.edit');
    Route::put('/suppressions/{id}', 'update')->name('suppressions.update');
    Route::delete('/suppressions/{id}', 'delete')->name('suppressions.delete');
    Route::put('/suppressions/executeSwitch/{id}', 'executeSwitch')->name('suppressions.executeSwitch');
});
