<?php

use App\Http\Controllers\Backend\Apps\RoleManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Role management routes — /admin/user-management/roles
|--------------------------------------------------------------------------
| Gated by: auth + verified + permission:backend.access (from web.php group)
| Per-action permission check (manage roles) is in RoleManagementController.
|
| Named: user-management.roles.{action}
*/

Route::prefix('admin')->name('user-management.')->group(function () {
    // AJAX endpoint: GET /admin/user-management/roles/{role}/permissions
    Route::get('/user-management/roles/{role}/permissions', [RoleManagementController::class, 'permissions'])
        ->name('roles.permissions');

    Route::resource('/user-management/roles', RoleManagementController::class);
});
