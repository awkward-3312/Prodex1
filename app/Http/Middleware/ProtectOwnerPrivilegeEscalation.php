<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProtectOwnerPrivilegeEscalation
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user('api');
        if (! $actor || (int) $actor->role_id === 1) return $next($request);

        $action = (string) optional($request->route())->getActionName();
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $response = $next($request);
            return $this->filterOwnerRoleFromSelectionResponses($response, $action);
        }

        if (! $this->isUserAccessMutation($action)) return $next($request);

        $requestedRoleId = $request->input('role_id', $request->input('role'));
        abort_if((int) $requestedRoleId === 1, 403, 'Solo el propietario puede asignar el rol propietario.');

        $targetId = $request->route('user') ?: $request->route('id');
        if (is_object($targetId)) $targetId = $targetId->id ?? null;
        if ($targetId) {
            $target = User::whereNull('deleted_at')->find((int) $targetId);
            abort_if($target && (int) $target->role_id === 1, 403, 'La cuenta propietaria no puede ser administrada por otro usuario.');
        }

        return $next($request);
    }

    private function filterOwnerRoleFromSelectionResponses($response, string $action)
    {
        if (! $response instanceof JsonResponse || ! $this->isRoleSelectionResponse($action)) return $response;

        $payload = $response->getData(true);
        if (! is_array($payload) || ! isset($payload['roles']) || ! is_array($payload['roles'])) return $response;

        $payload['roles'] = array_values(array_filter($payload['roles'], fn ($role) => (int) ($role['id'] ?? 0) !== 1));
        $response->setData($payload);
        return $response;
    }

    private function isUserAccessMutation(string $action): bool
    {
        foreach ([
            'UserController@store', 'UserController@update',
            'Organization\\UserAccessController@store',
            'Organization\\UserAccessEditController@update',
            'Organization\\EmployeeAccessController@create',
        ] as $needle) {
            if (str_contains($action, $needle)) return true;
        }
        return false;
    }

    private function isRoleSelectionResponse(string $action): bool
    {
        foreach ([
            'UserController@index', 'UserController@edit',
            'Organization\\UserAccessController@options',
            'Organization\\UserAccessEditController@show',
            'Organization\\EmployeeAccessController@index',
        ] as $needle) {
            if (str_contains($action, $needle)) return true;
        }
        return false;
    }
}
