<?php

use App\Helpers\Tools;
use App\Http\Controllers\Backend\MarketingDashboardController;
use App\Http\Controllers\Backend\ProspectionDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Backend route loader
|--------------------------------------------------------------------------
|
| Each call to Tools::includeRoutes() recursively requires all .php files
| under routes/{dir}/. Add a new domain directory here to auto-load it.
| The Prospection directory is scanned; it returns silently when empty.
|
*/

Route::get('admin/dashboard', [ProspectionDashboardController::class, 'index'])
    ->name('admin.dashboard');
Route::get('admin/dashboard/marketing', [MarketingDashboardController::class, 'index'])
    ->name('admin.dashboard.marketing');

Tools::includeRoutes('Backend/Prospection');
Tools::includeRoutes('Backend/Administration');
Tools::includeRoutes('Backend/Zoho');
