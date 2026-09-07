<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline production security headers for every `web` response (central
 * marketing site + tenant app). Deliberately conservative:
 *
 *  - No Content-Security-Policy is enforced here. A strict CSP has to be built
 *    from a real origin inventory (Tailwind runtime, iconify, payment gateways,
 *    tenant-uploaded assets) or it silently breaks the app. A Report-Only
 *    baseline is emitted so violations can be collected first; enforcement is
 *    tracked as follow-up work.
 *  - HSTS is only sent for genuine HTTPS requests in production, so local /
 *    proxied HTTP never gets pinned to https.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none', false);
        $headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(self), payment=(self), usb=(), interest-cohort=()',
            false
        );

        if (! $headers->has('Content-Security-Policy') && ! $headers->has('Content-Security-Policy-Report-Only')) {
            // Report-Only baseline, tuned to what the public site legitimately
            // loads today (self, Google Fonts, Google Analytics once consented,
            // inline styles/scripts from the landing templates). Enforcement is
            // tracked as follow-up once violation reports are reviewed.
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://www.google-analytics.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: https: blob:",
                "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "form-action 'self'",
            ]);
            $headers->set('Content-Security-Policy-Report-Only', $csp, false);
        }

        if ($request->isSecure() && app()->environment('production')) {
            $headers->set('Strict-Transport-Security', 'max-age=15552000', false);
        }

        return $response;
    }
}
