<?php

use App\Http\Controllers\Backend\SenderIdentityController;
use Illuminate\Support\Facades\Route;

Route::controller(SenderIdentityController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/sender_identities', 'index')->name('sender_identities.index');
    Route::get('/sender_identities/create', 'create')->name('sender_identities.create');
    Route::post('/sender_identities', 'store')->name('sender_identities.store');
    Route::get('/sender_identities/{id}', 'view')->name('sender_identities.view');
    Route::get('/sender_identities/{id}/edit', 'edit')->name('sender_identities.edit');
    Route::put('/sender_identities/{id}', 'update')->name('sender_identities.update');
    Route::delete('/sender_identities/{id}', 'delete')->name('sender_identities.delete');
    Route::put('/sender_identities/executeSwitch/{id}', 'executeSwitch')->name('sender_identities.executeSwitch');
});
