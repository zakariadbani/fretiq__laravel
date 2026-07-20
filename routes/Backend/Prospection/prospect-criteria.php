<?php

use App\Http\Controllers\Backend\ProspectCriteriaController;
use Illuminate\Support\Facades\Route;

Route::controller(ProspectCriteriaController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/prospect_criteria', 'index')->name('prospect_criteria.index');
    Route::get('/prospect_criteria/create', 'create')->name('prospect_criteria.create');
    Route::post('/prospect_criteria', 'store')->name('prospect_criteria.store');
    Route::post('/prospect_criteria/{id}/discover', 'discover')->name('prospect_criteria.discover');
    Route::get('/prospect_criteria/{id}/contact-enrichment/preview', 'contactEnrichmentPreview')->name('prospect_criteria.contact_enrichment_preview');
    Route::post('/prospect_criteria/{id}/contact-enrichment', 'dispatchContactEnrichment')->name('prospect_criteria.contact_enrichment_dispatch');
    Route::get('/prospect_criteria/{id}/discovery-status', 'discoveryStatus')->name('prospect_criteria.discovery_status');
    Route::post('/prospect_criteria/{prospect_criteria}/duplicate', 'duplicate')->name('prospect_criteria.duplicate');
    Route::get('/prospect_criteria/{prospect_criteria}/preview-queries', 'previewQueries')->name('prospect_criteria.preview_queries');
    Route::post('/prospect_criteria/{id}/generate-queries', 'generateQueries')->name('prospect_criteria.generate_queries');
    Route::get('/prospect_criteria/{id}', 'view')->name('prospect_criteria.view');
    Route::get('/prospect_criteria/{id}/edit', 'edit')->name('prospect_criteria.edit');
    Route::put('/prospect_criteria/{id}', 'update')->name('prospect_criteria.update');
    Route::delete('/prospect_criteria/{id}', 'delete')->name('prospect_criteria.delete');
    Route::put('/prospect_criteria/executeSwitch/{id}', 'executeSwitch')->name('prospect_criteria.executeSwitch');
});
