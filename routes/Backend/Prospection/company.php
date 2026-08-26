<?php

use App\Http\Controllers\Backend\CompanyController;
use Illuminate\Support\Facades\Route;

Route::controller(CompanyController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/companies', 'index')->name('companies.index');
    Route::get('/companies/archive', 'index')->name('companies.archive');
    Route::get('/companies/import', 'importForm')->name('companies.import_form');
    Route::post('/companies/import/preview', 'importPreview')->name('companies.import_preview');
    Route::post('/companies/import', 'importStore')->name('companies.import_store');
    Route::get('/companies/create', 'create')->name('companies.create');
    Route::post('/companies', 'store')->name('companies.store');
    Route::get('/companies/{id}', 'view')->name('companies.view');
    Route::get('/companies/{id}/edit', 'edit')->name('companies.edit');
    Route::put('/companies/{id}', 'update')->name('companies.update');
    Route::delete('/companies/{id}', 'delete')->name('companies.delete');
    Route::put('/companies/executeSwitch/{id}', 'executeSwitch')->name('companies.executeSwitch');
    Route::post('/companies/{id}/restore', 'restore')->name('companies.restore');
    Route::post('/companies/{id}/enrich', 'enrich')->name('companies.enrich');
    Route::post('/companies/{id}/explain-score', 'explainScore')->name('companies.explainScore');
});
