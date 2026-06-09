<?php

use App\Http\Controllers\Backend\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User management routes — /admin/users
|--------------------------------------------------------------------------
| Gated by: auth + verified + permission:backend.access (from web.php group)
| Per-action permission checks are in UserController constructor.
|
| Naming: admin.users.{action}  — matches BackendResource modelName='users'.
*/

Route::controller(UserController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', 'index')->name('users.index');
    Route::get('/users/create', 'create')->name('users.create');
    Route::post('/users', 'store')->name('users.store');
    Route::get('/users/{id}', 'view')->name('users.view');
    Route::get('/users/{id}/edit', 'edit')->name('users.edit');
    Route::put('/users/{id}', 'update')->name('users.update');
    Route::delete('/users/{id}', 'delete')->name('users.delete');
});
