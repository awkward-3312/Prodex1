<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * PRODEX runs behind Nginx in production (TLS terminates at the proxy). Without
 * trusting the forwarded headers Laravel sees plain HTTP and can build http://
 * URLs / mis-detect secure cookies behind the proxy.
 *
 * `TRUSTED_PROXIES` (comma separated, or `*`) is read from the environment.
 * Default `*` is safe here because the app is only ever reachable through the
 * Nginx front end; tighten to the proxy IP/CIDR if the topology changes.
 */
class TrustProxies extends Middleware
{
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $value = env('TRUSTED_PROXIES', '*');

        $this->proxies = $value === '*'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
