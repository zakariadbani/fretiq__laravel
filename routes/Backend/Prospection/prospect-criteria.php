<?php

use App\Http\Controllers\Backend\ProspectCriteriaController;
use Illuminate\Support\Facades\Route;

Route::controller(ProspectCriteriaController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/prospect_criteria', 'index')->name('prospect_criteria.index');
    Route::get('/prospect_criteria/import', 'importForm')->name('prospect_criteria.import_form');
    Route::post('/prospect_criteria/import/preview', 'importPreview')->name('prospect_criteria.import_preview');
    Route::post('/prospect_criteria/import', 'importStore')->name('prospect_criteria.import_store');
    Route::get('/prospect_criteria/import/template', 'downloadImportTemplate')->name('prospect_criteria.import_template');
    Route::get('/prospect_criteria/create', 'create')->name('prospect_criteria.create');
    Route::post('/prospect_criteria', 'store')->name('prospect_criteria.store');
    Route::post('/prospect_criteria/{id}/hunter-discover/preview', 'hunterDiscoverPreview')->middleware('throttle:5,1')->name('prospect_criteria.hunter_discover_preview');
    Route::post('/prospect_criteria/{id}/hunter-discover/import', 'hunterDiscoverImport')->name('prospect_criteria.hunter_discover_import');
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
