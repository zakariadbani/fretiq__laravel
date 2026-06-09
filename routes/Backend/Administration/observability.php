<?php

use App\Http\Controllers\Backend\ObservabilityController;
use Illuminate\Support\Facades\Route;

Route::controller(ObservabilityController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/observability', 'index')->name('observability.index');
});
