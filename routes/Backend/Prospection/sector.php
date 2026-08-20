<?php

use App\Http\Controllers\Backend\SectorController;
use Illuminate\Support\Facades\Route;

Route::controller(SectorController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/sectors', 'index')->name('sectors.index');
    Route::get('/sectors/create', 'create')->name('sectors.create');
    Route::post('/sectors', 'store')->name('sectors.store');
    Route::get('/sectors/{id}', 'view')->name('sectors.view');
    Route::get('/sectors/{id}/edit', 'edit')->name('sectors.edit');
    Route::put('/sectors/{id}', 'update')->name('sectors.update');
    Route::delete('/sectors/{id}', 'delete')->name('sectors.delete');
    Route::put('/sectors/executeSwitch/{id}', 'executeSwitch')->name('sectors.executeSwitch');
    Route::post('/sectors/{id}/toggle', 'toggle')->name('sectors.toggle');
});
