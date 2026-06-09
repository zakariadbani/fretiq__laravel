<?php

namespace App\Http\Controllers;

use App\Services\Campaign\EmailTrackingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * TrackingController — serves the 1×1 tracking pixel for email open tracking.
 *
 * Public route (no auth): GET /track/open/{token}
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
}
