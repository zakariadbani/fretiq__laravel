<?php

use App\Http\Controllers\Backend\DemandeController;
use Illuminate\Support\Facades\Route;

Route::controller(DemandeController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/demandes', 'index')->name('demandes.index');
    Route::get('/demandes/create', 'create')->name('demandes.create');
    Route::post('/demandes', 'store')->name('demandes.store');
    Route::get('/demandes/contacts/search', 'contactsSearch')->name('demandes.contacts.search');
    Route::get('/demandes/{id}', 'view')->name('demandes.view');
    Route::get('/demandes/{id}/edit', 'edit')->name('demandes.edit');
    Route::put('/demandes/{id}', 'update')->name('demandes.update');
    Route::delete('/demandes/{id}', 'delete')->name('demandes.delete');
    Route::put('/demandes/executeSwitch/{id}', 'executeSwitch')->name('demandes.executeSwitch');
});
