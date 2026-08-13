<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        // Setup wizard: runs before app is fully configured; sessions/cookies may not
        // be reliable yet. One-time operation on fresh install, no security risk.
        'setup',
        'setup/*',
        // Central (super admin) login can hit session cookie edge-cases on some local setups
        // (Laragon, custom hosts). Exclude only the login submit endpoint.
        'super/login',
        // Client portal API: session-based auth; CSRF token can mismatch when portal
        // is opened in another tab or page is cached. SameSite session cookie is used.
        'api/portal',
        'api/portal/*',
        // Payment gateway webhooks: signed by the gateway; no session/CSRF context.
        'webhook/*',
        // WhatsApp Cloud API webhook: signed by Meta (X-Hub-Signature-256); no session/CSRF context.
        'whatsapp/webhook/*',
    ];
}
