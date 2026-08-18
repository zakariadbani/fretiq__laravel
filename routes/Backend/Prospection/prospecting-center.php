<?php

use App\Http\Controllers\Backend\ProspectBatchController;
use App\Http\Controllers\Backend\ProspectReviewController;
use App\Http\Controllers\Backend\ProviderActivityController;
use Illuminate\Support\Facades\Route;

Route::controller(ProspectBatchController::class)->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/prospect_batches', 'index')->name('prospect_batches.index');
    Route::get('/prospect_batches/create', 'create')->name('prospect_batches.create');
    Route::post('/prospect_batches', 'store')->name('prospect_batches.store');
    Route::post('/prospect_batches/{id}/estimate', 'estimate')->name('prospect_batches.estimate');
    Route::post('/prospect_batches/{id}/confirm', 'confirm')->name('prospect_batches.confirm');
    Route::post('/prospect_batches/{id}/resume-discovery', 'resumeDiscovery')->name('prospect_batches.resume_discovery');
    Route::get('/prospect_batches/{id}/status', 'status')->name('prospect_batches.status');
    Route::get('/prospect_batches/{id}/contact-enrichment/preview', 'contactEnrichmentPreview')->name('prospect_batches.contact_enrichment_preview');
    Route::post('/prospect_batches/{id}/contact-enrichment', 'dispatchContactEnrichment')->name('prospect_batches.contact_enrichment_dispatch');
    Route::post('/prospect_batches/{id}/rescore', 'rescoreDispatch')->name('prospect_batches.rescore_dispatch');
    Route::get('/prospect_batches/{id}/rescore/status', 'rescoreStatus')->name('prospect_batches.rescore_status');
    Route::get('/prospect_batches/{id}', 'view')->name('prospect_batches.view');
    Route::get('/prospect_batches/{id}/edit', 'edit')->name('prospect_batches.edit');
    Route::put('/prospect_batches/{id}', 'update')->name('prospect_batches.update');
    Route::delete('/prospect_batches/{id}', 'delete')->name('prospect_batches.delete');
});

// Review is per-lot only — the "À vérifier" pane lives inline on the batch view
// page (ProspectBatchController::view(), #prospect_batch_review). These routes
// are just the item-level actions that pane's forms/fetches call: no standalone
// index/list route exists. Still gets auth+verified+permission:backend.access
// from the enclosing Route::middleware group in routes/web.php that wraps the
// whole routes/Backend/backend.php require (and this file via
// Tools::includeRoutes('Backend/Prospection')) — no extra ->middleware() needed
// here.
Route::controller(ProspectReviewController::class)->prefix('admin')->name('admin.prospect_review.')->group(function (): void {
    Route::get('/prospect-review/items/{item}/status', 'itemStatus')->name('items.status');
    Route::post('/prospect-review/items/{item}/decide', 'decideItem')->name('items.decide');
    Route::post('/prospect-review/retry-drain/preview', 'drainPreview')->name('retry_drain.preview');
    Route::post('/prospect-review/retry-drain', 'drainRetryable')->name('retry_drain.store');
    Route::get('/prospect-review/retry-drain/{token}/status', 'drainStatus')->name('retry_drain.status');
});

Route::controller(ProviderActivityController::class)->prefix('admin')->name('admin.provider_activity.')->group(function (): void {
    Route::get('/provider-activity', 'index')->name('index');
});
