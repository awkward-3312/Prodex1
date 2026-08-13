<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CustomDomain;
use App\Services\CustomDomainVerifier;
use App\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Database\Models\Domain;

class CustomDomainController extends Controller
{
    public function __construct(private readonly CustomDomainVerifier $verifier)
    {
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            ],
        ]);

        $domain = $this->normalize($validated['domain']);

        if ($this->isCentralDomain($domain)) {
            return back()->withErrors(['domain' => __('super.custom_domains.errors.is_central')]);
        }

        if (Domain::where('domain', $domain)->exists()
            || CustomDomain::where('domain', $domain)->exists()) {
            return back()->withErrors(['domain' => __('super.custom_domains.errors.duplicate')]);
        }

        CustomDomain::create([
            'tenant_id'          => $tenant->id,
            'domain'             => $domain,
            'verification_token' => $this->verifier->generateToken(),
            'ssl_status'         => CustomDomain::SSL_PENDING,
        ]);

        return back()->with('success', __('super.custom_domains.flash.added'));
    }

    public function verify(Request $request, Tenant $tenant, int $id): JsonResponse|RedirectResponse
    {
        $record = $tenant->customDomains()->findOrFail($id);

        $result = $this->verifier->verify($record->domain, $record->verification_token);

        if ($result['ok']) {
            DB::connection('central')->transaction(function () use ($record) {
                $record->markVerified();

                // Mirror into Stancl's domains table so existing resolution middleware works.
                if (! Domain::where('domain', $record->domain)->exists()) {
                    Domain::create([
                        'domain'    => $record->domain,
                        'tenant_id' => $record->tenant_id,
                    ]);
                }
            });

            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'message' => __('super.custom_domains.flash.verified')]);
            }
            return back()->with('success', __('super.custom_domains.flash.verified'));
        }

        $record->markFailed($this->reasonMessage($result['reason']));

        // Capture DNS/verification failure for super admin monitoring
        \App\Models\Central\SystemLog::capture([
            'tenant_id'       => $tenant->id,
            'type'            => \App\Models\Central\SystemLog::TYPE_DNS,
            'severity'        => \App\Models\Central\SystemLog::SEVERITY_WARNING,
            'message'         => 'Custom domain verification failed for ' . $record->domain . ': ' . $this->reasonMessage($result['reason']),
            'context'         => [
                'domain'  => $record->domain,
                'reason'  => $result['reason'],
                'records' => $result['records'] ?? [],
            ],
            'suggested_cause' => $this->dnsSuggestedCause($result['reason']),
            'source'          => 'custom_domain_verify',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'reason'  => $result['reason'],
                'message' => $this->reasonMessage($result['reason']),
                'records' => $result['records'] ?? [],
            ], 422);
        }

        return back()->withErrors(['domain' => $this->reasonMessage($result['reason'])]);
    }

    private function dnsSuggestedCause(string $reason): string
    {
        return match ($reason) {
            'mismatch'      => 'The domain resolves, but not to this server. Update the CNAME/A record to point at the central host, or add the TXT verification record.',
            'no_records'    => 'No DNS records found for this domain. Verify the domain is registered and DNS is configured.',
            'lookup_failed' => 'DNS lookup failed. The resolver may be unreachable or the domain may be unregistered. Retry after DNS propagation (up to 48h).',
            default         => 'See log context for record details.',
        };
    }

    public function destroy(Tenant $tenant, int $id): RedirectResponse
    {
        $record = $tenant->customDomains()->findOrFail($id);

        DB::connection('central')->transaction(function () use ($record) {
            // Only remove the mirrored Stancl domain row; never remove a subdomain
            // that happens to share the same name (defensive — they'd differ anyway).
            Domain::where('domain', $record->domain)
                ->where('tenant_id', $record->tenant_id)
                ->delete();
            $record->delete();
        });

        return back()->with('success', __('super.custom_domains.flash.removed'));
    }

    public function makePrimary(Tenant $tenant, int $id): RedirectResponse
    {
        $record = $tenant->customDomains()->findOrFail($id);

        if (! $record->is_verified) {
            return back()->withErrors(['domain' => __('super.custom_domains.errors.not_verified')]);
        }

        DB::connection('central')->transaction(function () use ($tenant, $record) {
            $tenant->customDomains()->update(['is_primary' => false]);
            $record->forceFill(['is_primary' => true])->save();
        });

        return back()->with('success', __('super.custom_domains.flash.primary_set'));
    }

    private function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));
        return preg_replace('#^https?://#', '', $domain) ?: $domain;
    }

    private function isCentralDomain(string $domain): bool
    {
        $central = array_map('strtolower', (array) config('tenancy.central_domains', []));
        return in_array($domain, $central, true);
    }

    private function reasonMessage(string $reason): string
    {
        return match ($reason) {
            CustomDomainVerifier::RESULT_NO_RECORDS  => __('super.custom_domains.errors.no_records'),
            CustomDomainVerifier::RESULT_MISMATCH    => __('super.custom_domains.errors.mismatch'),
            CustomDomainVerifier::RESULT_LOOKUP_FAIL => __('super.custom_domains.errors.lookup_failed'),
            default                                  => __('super.custom_domains.errors.unknown'),
        };
    }
}
