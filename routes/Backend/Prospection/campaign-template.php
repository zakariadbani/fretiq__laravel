<?php

use App\Http\Controllers\Backend\CampaignTemplateController;
use Illuminate\Support\Facades\Route;

Route::controller(CampaignTemplateController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/campaign_templates', 'index')->name('campaign_templates.index');
    Route::get('/campaign_templates/create', 'create')->name('campaign_templates.create');
    Route::post('/campaign_templates', 'store')->name('campaign_templates.store');
    Route::get('/campaign_templates/{id}', 'view')->name('campaign_templates.view');
    Route::get('/campaign_templates/{id}/edit', 'edit')->name('campaign_templates.edit');
    Route::put('/campaign_templates/{id}', 'update')->name('campaign_templates.update');
    Route::delete('/campaign_templates/{id}', 'delete')->name('campaign_templates.delete');
    Route::put('/campaign_templates/executeSwitch/{id}', 'executeSwitch')->name('campaign_templates.executeSwitch');
});
