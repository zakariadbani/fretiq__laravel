<?php

use App\Http\Controllers\Backend\CampaignController;
use Illuminate\Support\Facades\Route;

Route::controller(CampaignController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/campaigns', 'index')->name('campaigns.index');
    Route::get('/campaigns/create', 'create')->name('campaigns.create');
    Route::post('/campaigns', 'store')->name('campaigns.store');

    // Action routes — before {id} to avoid ambiguity
    Route::get('/campaigns/segment-count/{id}', 'segmentCount')->name('campaigns.segmentCount');
    Route::get('/campaigns/{id}/next-wave-preview', 'nextWavePreview')->name('campaigns.nextWavePreview');
    Route::post('/campaigns/audience-language-split', 'audienceLanguageSplit')->name('campaigns.audienceLanguageSplit');
    Route::get('/campaigns/{id}/dispatch-preview', 'dispatchPreview')->name('campaigns.dispatchPreview');
    Route::post('/campaigns/{id}/schedule', 'schedule')->name('campaigns.schedule');
    Route::post('/campaigns/{id}/send', 'sendNow')->name('campaigns.sendNow');
    Route::put('/campaigns/{id}/sequence-auto-enroll', 'sequenceAutoEnroll')->name('campaigns.sequenceAutoEnroll');
    Route::post('/campaigns/{id}/sync-zoho-list', 'syncZohoList')->name('campaigns.syncZohoList');
    Route::post('/campaigns/{id}/waves/{runId}/retry-zoho', 'retryZohoWave')->name('campaigns.retryZohoWave');
    Route::post('/campaigns/{id}/recipients/{recipientId}/replied', 'markReplied')->name('campaigns.markReplied');

    Route::get('/campaigns/{id}', 'view')->name('campaigns.view');
    Route::get('/campaigns/{id}/edit', 'edit')->name('campaigns.edit');
    Route::put('/campaigns/{id}', 'update')->name('campaigns.update');
    Route::delete('/campaigns/{id}', 'delete')->name('campaigns.delete');
    Route::put('/campaigns/executeSwitch/{id}', 'executeSwitch')->name('campaigns.executeSwitch');
});
