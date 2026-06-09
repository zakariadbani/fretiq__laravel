<?php

use App\Http\Controllers\Backend\Apps\PermissionManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Permission management routes — /admin/user-management/permissions
|--------------------------------------------------------------------------
| Gated by: auth + verified + permission:backend.access (from web.php group)
| Per-action permission check (manage permissions) is in PermissionManagementController.
|
| Named: user-management.permissions.{action}
*/

Route::prefix('admin')->name('user-management.')->group(function () {
    Route::resource('/user-management/permissions', PermissionManagementController::class);
});
