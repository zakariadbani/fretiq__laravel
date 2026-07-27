<?php

use App\Http\Controllers\Backend\ObservabilityController;
use Illuminate\Support\Facades\Route;

Route::controller(ObservabilityController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/observability', 'index')->name('observability.index');
    Route::post('/observability/jobs/{job}/cancel', 'cancelJob')->whereNumber('job')->name('observability.jobs.cancel');
    Route::post('/observability/failed-jobs/retry-all', 'retryAllFailedJobs')->name('observability.failed-jobs.retry-all');
    Route::post('/observability/failed-jobs/{uuid}/retry', 'retryFailedJob')->whereUuid('uuid')->name('observability.failed-jobs.retry');
    Route::delete('/observability/failed-jobs/{uuid}', 'forgetFailedJob')->whereUuid('uuid')->name('observability.failed-jobs.forget');
    Route::patch('/observability/scheduler', 'toggleCron')->name('observability.scheduler.toggle');
    Route::patch('/observability/scheduler/tasks/{task}', 'toggleTask')->where('task', '[a-z0-9_]+')->name('observability.scheduler.tasks.toggle');
    Route::post('/observability/scheduler/tasks/{task}/run', 'runTask')->where('task', '[a-z0-9_]+')->name('observability.scheduler.tasks.run');
});
