<?php

use App\Http\Controllers\Backend\PlannerController;
use Illuminate\Support\Facades\Route;

Route::controller(PlannerController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/planner',      'index')->name('planner.index');
    Route::get('/planner/feed', 'feed')->name('planner.feed');
});
