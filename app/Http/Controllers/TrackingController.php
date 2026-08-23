<?php

namespace App\Http\Controllers;

use App\Services\Campaign\EmailTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * TrackingController — serves the 1×1 tracking pixel for email open tracking,
 * and the click-through redirect for link tracking.
 *
 * Public routes (no auth):
 *   GET /track/open/{token}          — pixel (unsigned; always 200 + GIF)
 *   GET /track/click/{token}?url=... — redirect (signed:relative route — the
 *                                       'url' param is part of the HMAC
 *                                       signature, so it cannot be swapped
 *                                       for an open redirect; relative
 *                                       validation ignores host/scheme so
 *                                       APP_URL drift cannot false-reject an
 *                                       already-sent link; tampering or a
 *                                       genuine signature failure is
 *                                       redirected to the app root by
 *                                       Handler::register(), never a bare
 *                                       403, before this controller runs)
 *
 * Always returns the transparent GIF regardless of token validity — never
 * throws, never exposes whether the token was found. This is intentional:
 *   - Invalid/expired tokens are silently ignored in EmailTrackingService.
 *   - A 404 or error would allow spam filters to detect the tracking endpoint.
 */
class TrackingController extends Controller
{
    /**
     * The 43-byte body of a 1×1 transparent GIF (GIF89a spec-minimal).
     * This is a well-known static byte sequence; no generation overhead.
     */
    private const TRANSPARENT_GIF = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00\x21\xf9\x04\x00\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function __construct(
        private readonly EmailTrackingService $trackingService,
    ) {}

    /**
     * Record the open event (if the token is valid) and return the pixel.
     *
     * Never throws — any exception is swallowed to avoid breaking the email
     * client's image rendering pipeline.
     */
    public function open(Request $request, string $token): Response
    {
        try {
            $this->trackingService->recordOpen(
                $token,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\Throwable) {
            // Silently ignore — always serve the pixel.
        }

        return response(self::TRANSPARENT_GIF, 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);
    }

    /**
     * Record the click event (if the token is valid) and redirect to the
     * destination carried in the signed 'url' query parameter.
     *
     * The 'signed:relative' route middleware has already rejected any
     * tampered token or url (redirected to the app root by
     * Handler::register(), not a bare 403) before this method runs, so
     * $destination is guaranteed to be exactly what RendersTrackedHtml
     * originally generated. The scheme check below is pure defense in
     * depth, not a security boundary.
     *
     * Never throws — a tracking failure must never strand the recipient's
     * click; on any error the redirect still happens.
     */
    public function click(Request $request, string $token): RedirectResponse
    {
        $destination = (string) $request->query('url', '');

        try {
            $this->trackingService->recordClick(
                $token,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\Throwable) {
            // Silently ignore — the redirect below must still happen.
        }

        if (! str_starts_with($destination, 'http://') && ! str_starts_with($destination, 'https://')) {
            $destination = config('app.url');
        }

        return redirect()->away($destination);
    }
}
