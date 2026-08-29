<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
        \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException::class,
    ];

    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    public function report(Throwable $exception)
    {
        // Bots and misconfigured DNS constantly hit the server on unknown Host
        // headers. render() already answers those with a 404 / redirect to the
        // central URL, so logging every hit as production.ERROR (with a full
        // stack trace) is pure noise that bloats storage/logs. Drop it here.
        if ($exception instanceof \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException) {
            return;
        }

        try {
            if ($this->shouldReport($exception)) {
                $tenantId = null;
                try {
                    if (function_exists('tenant') && tenant()) {
                        $tenantId = (string) tenant()->id;
                    }
                } catch (\Throwable $ignored) {
                }

                \App\Models\Central\SystemLog::fromThrowable($exception, [
                    'tenant_id' => $tenantId,
                    'source'    => 'exception_handler',
                ]);
            }
        } catch (\Throwable $ignored) {
        }

        parent::report($exception);
    }

    public function render($request, Throwable $exception)
    {
        if ($exception instanceof \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException) {
            $centralUrl = config('app.url', '/');

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Este dominio no está configurado. Usa la URL principal de la aplicación.',
                    'redirect' => $centralUrl,
                ], 404);
            }

            if ($request->getHost() === parse_url($centralUrl, PHP_URL_HOST)) {
                return response('Página no encontrada', 404);
            }

            return redirect($centralUrl);
        }

        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'message' => 'No encontrado',
                'status' => 404,
            ], 404);

        } elseif ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return response()->json([
                'message' => 'No tienes autorización para realizar esta acción',
                'status' => 403,
            ], 403);
        }

        return parent::render($request, $exception);
    }
}
