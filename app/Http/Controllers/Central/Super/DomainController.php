<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            // Accept bare subdomains (e.g. "myshop") or full domains (e.g. "shop.example.com")
            'domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i'],
        ]);

        $domain = strtolower(trim($validated['domain']));

        $existsGlobally = \Stancl\Tenancy\Database\Models\Domain::where('domain', $domain)->exists();
        if ($existsGlobally) {
            return back()->withErrors(['domain' => 'Este subdominio ya está siendo utilizado por otro tenant.']);
        }

        try {
            $tenant->createDomain($domain);
        } catch (\Throwable $e) {
            return back()->withErrors(['domain' => 'No se pudo agregar el subdominio: ' . $e->getMessage()]);
        }

        return back()->with('success', 'Subdominio agregado correctamente.');
    }

    public function destroy(Tenant $tenant, int $domainId): RedirectResponse
    {
        $domain = $tenant->domains()->findOrFail($domainId);

        if ($tenant->domains()->count() <= 1) {
            return back()->withErrors(['domain' => 'No se puede eliminar el último dominio del tenant.']);
        }

        $domain->delete();

        return back()->with('success', 'Dominio eliminado correctamente.');
    }
}