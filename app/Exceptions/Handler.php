<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var arrays
     */
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
        // Unknown hosts (server IP, bot Host headers, unregistered domains) are
        // expected traffic on a SaaS and already handled in render() with a
        // redirect to the central app — don't spam laravel.log / system_logs.
        \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
    {
        // Capture reportable exceptions to the central system_logs table so super
        // admins can review them. Wrapped in a try/catch so logging failures
        // never obscure the original exception.
        try {
            if ($this->shouldReport($exception)) {
                $tenantId = null;
                try {
                    if (function_exists('tenant') && tenant()) {
                        $tenantId = (string) tenant()->id;
                    }
                } catch (\Throwable $ignored) {
                    // tenancy not initialised — leave tenantId null
                }

                \App\Models\Central\SystemLog::fromThrowable($exception, [
                    'tenant_id' => $tenantId,
                    'source'    => 'exception_handler',
                ]);
            }
        } catch (\Throwable $ignored) {
            // Never let log capture break the exception pipeline.
        }

        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */

    // public function destroyCookie($cookie_name){

    //     $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];

    //     if (isset($_COOKIE[$cookie_name])) {
    //         unset($_COOKIE[$cookie_name]);
    //         setcookie($cookie_name, '', time() - 2147483647, '/', $domain); // empty value and old timestamp
    //     }
    // }
    // protected function unauthenticated($request, AuthenticationException $exception)
    // {
    //     if ($request->expectsJson()) {
    //         $this->destroyCookie('Stocky_token');

    //         return response()->json([
    //             'message' => 'Unauthenticated.',
    //             'status' => 401,
    //         ], 401);
    //     }

    // }

    public function render($request, Throwable $exception)
    {
        // Unrecognised domain/subdomain (e.g. server IP) — redirect to the central app URL
        if ($exception instanceof \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException) {
            $centralUrl = config('app.url', '/');

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This domain is not configured. Please use the main application URL.',
                    'redirect' => $centralUrl,
                ], 404);
            }

            // Redirecting to ourselves would loop (possible with a misconfigured
            // CENTRAL_DOMAINS override) — serve a plain 404 instead.
            if ($request->getHost() === parse_url($centralUrl, PHP_URL_HOST)) {
                return response('Not Found', 404);
            }

            return redirect($centralUrl);
        }

        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'message' => 'Not found',
                'status' => 404,
            ], 404);

        } elseif ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return response()->json([
                'message' => 'You are not authorized',
                'status' => 403,
            ], 403);
        }

        return parent::render($request, $exception);
    }
}
