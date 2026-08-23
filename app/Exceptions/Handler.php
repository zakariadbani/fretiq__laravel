<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Psr\Log\LogLevel;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        // Expected/benign domain exceptions — no stack-trace spam at 'error'.
        QuotaExhaustedException::class => LogLevel::WARNING,
        DiscoveryRunInFlightException::class => LogLevel::WARNING,
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (InvalidSignatureException $e, Request $request) {
            if ($request->is('u/*')) {
                return response()->view('public.unsubscribed', ['state' => 'error'], 403);
            }

            // A human recipient clicking an old/drifted tracking link must
            // land somewhere useful, never a bare 403 — and never the 'url'
            // query param (that would reopen the exact open-redirect the
            // signature exists to prevent).
            if ($request->is('track/click/*')) {
                return redirect()->to(url('/'));
            }
        });

        $this->renderable(function (TokenMismatchException $e, Request $request) {
            if ($request->is('u/*')) {
                return response()->view('public.unsubscribed', ['state' => 'error'], 419);
            }
        });
    }
}
