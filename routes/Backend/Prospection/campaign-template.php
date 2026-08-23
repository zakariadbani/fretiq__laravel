<?php

use App\Http\Controllers\Backend\CampaignTemplateController;
use Illuminate\Support\Facades\Route;

Route::controller(CampaignTemplateController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/campaign_templates', 'index')->name('campaign_templates.index');
    Route::get('/campaign_templates/import', 'importForm')->name('campaign_templates.import_form');
    Route::post('/campaign_templates/import/preview', 'importPreview')->name('campaign_templates.import_preview');
    Route::post('/campaign_templates/import', 'importStore')->name('campaign_templates.import_store');
    Route::get('/campaign_templates/create', 'create')->name('campaign_templates.create');
    Route::post('/campaign_templates', 'store')->name('campaign_templates.store');
    Route::post('/campaign_templates/import-zoho', 'importFromZoho')->name('campaign_templates.import_zoho');
    // Builder routes declared ABOVE the {id} routes — /campaign_templates/{id} etc.
    // would otherwise be eligible to match "builder" as a literal {id} segment.
    // Throttled (plan item 4): builder_suggest spends a paid Gemini call (60s
    // timeout, 4096 output tokens) per request — tight limit. builder_preview
    // only composes local Blade partials but fires on a 400ms keystroke
    // debounce — looser limit, same convention as segments.preview (see
    // routes/Backend/Prospection/segment.php).
    Route::post('/campaign_templates/builder/suggest', 'builderSuggest')->middleware('throttle:10,1')->name('campaign_templates.builder_suggest');
    Route::post('/campaign_templates/builder/preview', 'builderPreview')->middleware('throttle:60,1')->name('campaign_templates.builder_preview');
    Route::post('/campaign_templates/{id}/translate', 'translate')->name('campaign_templates.translate');
    Route::post('/campaign_templates/{id}/translation', 'saveTranslation')->name('campaign_templates.saveTranslation');
    Route::post('/campaign_templates/{id}/translation/review', 'markReviewed')->name('campaign_templates.markReviewed');
    Route::get('/campaign_templates/{id}', 'view')->name('campaign_templates.view');
    Route::get('/campaign_templates/{id}/edit', 'edit')->name('campaign_templates.edit');
    Route::put('/campaign_templates/{id}', 'update')->name('campaign_templates.update');
    Route::delete('/campaign_templates/{id}', 'delete')->name('campaign_templates.delete');
    Route::put('/campaign_templates/executeSwitch/{id}', 'executeSwitch')->name('campaign_templates.executeSwitch');
});
