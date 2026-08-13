<?php

use App\Http\Controllers\Backend\ContactController;
use Illuminate\Support\Facades\Route;

Route::controller(ContactController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/contacts', 'index')->name('contacts.index');
    Route::get('/contacts/create', 'create')->name('contacts.create');
    Route::post('/contacts', 'store')->name('contacts.store');
    Route::post('/contacts/{id}/verify-email', 'verifyEmail')->name('contacts.verify-email');
    Route::get('/contacts/{id}', 'view')->name('contacts.view');
    Route::get('/contacts/{id}/edit', 'edit')->name('contacts.edit');
    Route::put('/contacts/{id}', 'update')->name('contacts.update');
    Route::delete('/contacts/{id}', 'delete')->name('contacts.delete');
    Route::put('/contacts/executeSwitch/{id}', 'executeSwitch')->name('contacts.executeSwitch');
});
