<?php

use App\Http\Controllers\Backend\SequenceController;
use Illuminate\Support\Facades\Route;

Route::controller(SequenceController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/sequences', 'index')->name('sequences.index');
    Route::get('/sequences/create', 'create')->name('sequences.create');
    Route::post('/sequences', 'store')->name('sequences.store');

    // Step management — before {id} to avoid ambiguity
    Route::post('/sequences/{id}/steps', 'addStep')->name('sequences.addStep');
    Route::put('/sequences/{id}/steps/{stepId}', 'updateStep')->name('sequences.updateStep');
    Route::delete('/sequences/{id}/steps/{stepId}', 'deleteStep')->name('sequences.deleteStep');
    Route::post('/sequences/{id}/steps/{stepId}/move-up', 'moveStepUp')->name('sequences.moveStepUp');
    Route::post('/sequences/{id}/steps/{stepId}/move-down', 'moveStepDown')->name('sequences.moveStepDown');

    // Enrollment management
    Route::post('/sequences/{id}/enrollments/{enrId}/pause', 'pauseEnrollment')->name('sequences.pauseEnrollment');
    Route::post('/sequences/{id}/enrollments/{enrId}/resume', 'resumeEnrollment')->name('sequences.resumeEnrollment');
    Route::post('/sequences/{id}/enrollments/{enrId}/stop', 'stopEnrollment')->name('sequences.stopEnrollment');

    Route::put('/sequences/executeSwitch/{id}', 'executeSwitch')->name('sequences.executeSwitch');

    Route::get('/sequences/{id}', 'view')->name('sequences.view');
    Route::get('/sequences/{id}/edit', 'edit')->name('sequences.edit');
    Route::put('/sequences/{id}', 'update')->name('sequences.update');
    Route::delete('/sequences/{id}', 'delete')->name('sequences.delete');
});
