<?php

use App\Http\Controllers\Backend\PackageController;
use Illuminate\Support\Facades\Route;

Route::controller(PackageController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/packages',                    'index')->name('packages.index');
    Route::get('/packages/create',             'create')->name('packages.create');
    Route::post('/packages',                   'store')->name('packages.store');
    Route::get('/packages/{id}',               'view')->name('packages.view');
    Route::get('/packages/{id}/edit',          'edit')->name('packages.edit');
    Route::put('/packages/{id}',               'update')->name('packages.update');
    Route::delete('/packages/{id}',            'delete')->name('packages.delete');
    Route::put('/packages/executeSwitch/{id}', 'executeSwitch')->name('packages.executeSwitch');
    Route::post('/packages/assign',            'assign')->name('packages.assign');
});
