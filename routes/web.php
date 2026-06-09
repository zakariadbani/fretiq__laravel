<?php

use App\Http\Controllers\Apps\PermissionManagementController;
use App\Http\Controllers\Apps\RoleManagementController;
use App\Http\Controllers\Apps\UserManagementController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UnsubscribeController;
use App\Helpers\Tools;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/', [DashboardController::class, 'index']);

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::name('user-management.')->group(function () {
        Route::resource('/user-management/users', UserManagementController::class);
        Route::resource('/user-management/roles', RoleManagementController::class);
        Route::resource('/user-management/permissions', PermissionManagementController::class);
    });

});

/*
|--------------------------------------------------------------------------
| Backend routes — auth + permission:backend.access guard
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified', 'permission:backend.access'])->group(function () {
    require __DIR__ . '/Backend/backend.php';
});

Route::get('/error', function () {
    abort(500);
});

/*
|--------------------------------------------------------------------------
| Public tracking & unsubscribe routes (no auth)
|--------------------------------------------------------------------------
| These routes are recipient-facing and must work without any session/auth.
| The unsubscribe route is protected by Laravel's signed URL middleware.
*/

// Email open-tracking pixel — always returns a 1×1 transparent GIF.
Route::get('/track/open/{token}', [TrackingController::class, 'open'])
    ->name('track.open');

// Unsubscribe landing page + RFC 8058 one-click POST handler.
Route::match(['get', 'post'], '/u/{contact}', [UnsubscribeController::class, 'show'])
    ->name('unsubscribe')
    ->middleware('signed');

Route::get('/auth/redirect/{provider}', [SocialiteController::class, 'redirect']);

require __DIR__ . '/auth.php';
