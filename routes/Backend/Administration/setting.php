<?php

use App\Http\Controllers\Backend\SettingController;
use Illuminate\Support\Facades\Route;

Route::controller(SettingController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/settings', 'index')->name('settings.index');
    Route::post('/settings/save', 'save')->name('settings.save');
});
