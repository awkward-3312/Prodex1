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

        // Check across ALL tenants, not just this one
        $existsGlobally = \Stancl\Tenancy\Database\Models\Domain::where('domain', $domain)->exists();
        if ($existsGlobally) {
            return back()->withErrors(['domain' => 'This subdomain is already in use by another tenant.']);
        }

        try {
            $tenant->createDomain($domain);
        } catch (\Throwable $e) {
            return back()->withErrors(['domain' => 'Could not add subdomain: ' . $e->getMessage()]);
        }

        return back()->with('success', 'Subdomain added successfully.');
    }

    public function destroy(Tenant $tenant, int $domainId): RedirectResponse
    {
        $domain = $tenant->domains()->findOrFail($domainId);

        if ($tenant->domains()->count() <= 1) {
            return back()->withErrors(['domain' => 'Cannot remove the last domain.']);
        }

        $domain->delete();

        return back()->with('success', 'Domain removed.');
    }
}
