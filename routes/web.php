<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UnsubscribeController;
use App\Models\CampaignRun;
use App\Services\Campaign\ZohoCampaignsDriver;
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

// Public landing page. Logged-in users skip straight to the app.
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return response()->file(resource_path('landing/index.html'));
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/dashboard', fn () => redirect()->route('admin.dashboard'))
        ->middleware('permission:backend.access')
        ->name('dashboard');

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

// Click-through redirect. The destination lives in the signed 'url' query
// param (HMAC-covered, not a free-form open-redirect param) — tampering with
// either the token or the url is rejected before the controller runs.
// 'signed:relative' validates the path+query only, not the host/scheme, so
// APP_URL drift between the process that minted the link and the one
// serving it (proxy, TLS termination, an APP_URL edit) cannot turn an
// already-sent link into a false rejection — see Handler::register() for
// the redirect-to-home fallback on genuine signature failure.
Route::get('/track/click/{token}', [TrackingController::class, 'click'])
    ->name('track.click')
    ->middleware('signed:relative');

// Unsubscribe confirmation GET, CSRF form POST, and RFC 8058 one-click POST.
Route::get('/u/{contact}', [UnsubscribeController::class, 'show'])
    ->name('unsubscribe')
    ->middleware('signed');
Route::post('/u/{contact}', [UnsubscribeController::class, 'confirm'])
    ->name('unsubscribe.confirm')
    ->middleware('signed');
Route::post('/u/{contact}/one-click', [UnsubscribeController::class, 'oneClick'])
    ->name('unsubscribe.one-click')
    ->middleware('signed');

// Public, signed HTML import URL used by Zoho Campaigns createCampaign.
Route::get('/campaign-runs/{run}/zoho-content', function (CampaignRun $run) {
    $run->load(['campaign.template', 'sequenceStep.template']);

    $template = $run->sequenceStep?->template ?? $run->campaign?->template;
    abort_unless($template, 404);

    return response(
        ZohoCampaignsDriver::prepareHtmlContent($template->html_content),
        200,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    );
})->name('campaigns.zoho-content')->middleware('signed');

Route::get('/auth/redirect/{provider}', [SocialiteController::class, 'redirect']);

require __DIR__ . '/auth.php';
