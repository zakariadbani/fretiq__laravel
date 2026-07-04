<?php

use App\Http\Controllers\Backend\ConsumptionController;
use Illuminate\Support\Facades\Route;

Route::controller(ConsumptionController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/consumption', 'index')->name('consumption.index');
});
