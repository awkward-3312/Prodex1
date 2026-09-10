<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Stancl\Tenancy\Database\Models\Domain;

class MobileTenantResolverController extends Controller
{
    public function resolve(Request $request)
    {
        $input = [
            'workspace' => strtolower(trim((string) $request->input('workspace'))),
        ];

        $validator = Validator::make($input, [
            'workspace' => [
                'required',
                'string',
                'min:2',
                'max:63',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
            ],
        ]);

        if ($validator->fails()) {
            return $this->error('invalid_workspace', 422);
        }

        $workspace = $input['workspace'];
        $domain = Domain::query()
            ->where('domain', $workspace)
            ->with('tenant')
            ->first();

        $tenant = $domain?->tenant;
        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            return $this->error('workspace_not_found', 404);
        }

        return response()->json([
            'data' => [
                'workspace' => $workspace,
                'base_url' => $this->tenantBaseUrl($domain->domain),
                'name' => $tenant->company_name ?? $tenant->name ?? null,
                'logo' => $tenant->login_logo_path ? $tenant->loginLogoUrl() : null,
            ],
        ]);
    }

    private function tenantBaseUrl(string $storedDomain): string
    {
        $host = $storedDomain;
        if (! str_contains($host, '.')) {
            $baseHost = parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
            $host = $host.'.'.preg_replace('/^www\./i', '', $baseHost);
        }

        $scheme = parse_url(config('app.url', 'http://localhost'), PHP_URL_SCHEME) ?: request()->getScheme();
        $port = parse_url(config('app.url', ''), PHP_URL_PORT);
        $portSuffix = $port && ! in_array((int) $port, [80, 443], true) ? ':'.$port : '';

        return $scheme.'://'.$host.$portSuffix;
    }

    private function error(string $code, int $status)
    {
        return response()->json([
            'error' => [
                'code' => $code,
            ],
        ], $status);
    }
}

