<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\SystemLog;
use App\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

class LogsHealthController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'type'   => $request->input('type', ''),
            'status' => $request->input('status', ''),
            'tenant' => $request->input('tenant', ''),
            'from'   => $request->input('from', ''),
            'to'     => $request->input('to', ''),
        ];

        $query = SystemLog::query()->visible()->with(['tenant', 'resolver:id,name']);

        if ($filters['search'] !== '') {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('message', 'like', "%{$s}%")
                    ->orWhere('tenant_id', 'like', "%{$s}%")
                    ->orWhere('source', 'like', "%{$s}%");
            });
        }
        if ($filters['type'] !== '' && in_array($filters['type'], array_keys(SystemLog::types()), true)) {
            $query->where('type', $filters['type']);
        }
        if ($filters['status'] !== '' && in_array($filters['status'], [SystemLog::STATUS_UNRESOLVED, SystemLog::STATUS_RESOLVED], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['tenant'] !== '') {
            $query->where('tenant_id', $filters['tenant']);
        }
        if ($filters['from'] !== '') {
            try { $query->where('occurred_at', '>=', Carbon::parse($filters['from'])->startOfDay()); } catch (Throwable) {}
        }
        if ($filters['to'] !== '') {
            try { $query->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay()); } catch (Throwable) {}
        }

        $logs = $query->orderByDesc('occurred_at')->paginate(25)->withQueryString();

        $stats = [
            'total'       => SystemLog::visible()->count(),
            'unresolved'  => SystemLog::visible()->unresolved()->count(),
            'critical'    => SystemLog::visible()->unresolved()->critical()->count(),
            'resolved'    => SystemLog::visible()->resolved()->count(),
            'last_24h'    => SystemLog::visible()->where('occurred_at', '>=', now()->subDay())->count(),
            'by_type'     => SystemLog::selectRaw('type, count(*) as c')
                ->visible()
                ->unresolved()
                ->groupBy('type')
                ->pluck('c', 'type')
                ->toArray(),
        ];

        $healthStatus = $this->computeHealthStatus($stats);

        $tenantOptions = Tenant::orderBy('created_at', 'desc')->limit(500)->get();

        return view('central.super.logs-health.index', [
            'logs'          => $logs,
            'filters'       => $filters,
            'stats'         => $stats,
            'healthStatus'  => $healthStatus,
            'tenantOptions' => $tenantOptions,
            'types'         => SystemLog::types(),
        ]);
    }

    public function show(SystemLog $log)
    {
        $log->load(['tenant', 'resolver:id,name,email']);

        $tenantHistory = SystemLog::visible()
            ->where('tenant_id', $log->tenant_id)
            ->where('id', '!=', $log->id)
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get();

        return view('central.super.logs-health.show', [
            'log'           => $log,
            'tenantHistory' => $tenantHistory,
            'types'         => SystemLog::types(),
        ]);
    }

    public function tenantHistory(Tenant $tenant)
    {
        $logs = SystemLog::visible()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('occurred_at')
            ->paginate(30);

        $stats = [
            'total'      => SystemLog::visible()->where('tenant_id', $tenant->id)->count(),
            'unresolved' => SystemLog::visible()->where('tenant_id', $tenant->id)->unresolved()->count(),
            'critical'   => SystemLog::visible()->where('tenant_id', $tenant->id)->critical()->count(),
        ];

        return view('central.super.logs-health.tenant', [
            'tenant' => $tenant,
            'logs'   => $logs,
            'stats'  => $stats,
            'types'  => SystemLog::types(),
        ]);
    }

    public function resolve(Request $request, SystemLog $log): JsonResponse
    {
        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = Auth::guard('central')->user();

        $log->update([
            'status'          => SystemLog::STATUS_RESOLVED,
            'resolved_at'     => now(),
            'resolved_by'     => $user?->id,
            'resolution_note' => $request->input('note'),
        ]);

        return response()->json(['success' => true, 'message' => 'Log marked as resolved.']);
    }

    public function reopen(SystemLog $log): JsonResponse
    {
        $log->update([
            'status'          => SystemLog::STATUS_UNRESOLVED,
            'resolved_at'     => null,
            'resolved_by'     => null,
            'resolution_note' => null,
        ]);

        return response()->json(['success' => true, 'message' => 'Log reopened.']);
    }

    public function destroy(SystemLog $log): JsonResponse
    {
        $log->delete();
        return response()->json(['success' => true, 'message' => 'Log deleted.']);
    }

    public function clearResolved(): JsonResponse
    {
        $count = SystemLog::resolved()->count();
        SystemLog::resolved()->delete();
        return response()->json(['success' => true, 'message' => "Deleted {$count} resolved logs."]);
    }

    /**
     * Re-check the underlying condition that produced this log.
     * Dispatches on log type: DNS, SSL, database. Exceptions/general logs have no automated re-check.
     */
    public function recheck(SystemLog $log): JsonResponse
    {
        $result = match ($log->type) {
            SystemLog::TYPE_DNS          => $this->recheckDns($log),
            SystemLog::TYPE_SSL          => $this->recheckSsl($log),
            SystemLog::TYPE_DATABASE     => $this->recheckDatabase($log),
            SystemLog::TYPE_PROVISIONING => $this->recheckDatabase($log),
            default                      => ['success' => false, 'status' => 'manual', 'message' => 'This log type has no automated re-check. Please investigate and resolve manually.'],
        };

        if (($result['status'] ?? null) === 'ok') {
            $log->update([
                'status'          => SystemLog::STATUS_RESOLVED,
                'resolved_at'     => now(),
                'resolved_by'     => Auth::guard('central')->id(),
                'resolution_note' => 'Auto-resolved by re-check: ' . ($result['message'] ?? 'ok'),
            ]);
        }

        return response()->json($result);
    }

    // ─────────────────────────────────────────────────────────────
    // Re-check helpers
    // ─────────────────────────────────────────────────────────────

    private function recheckDns(SystemLog $log): array
    {
        $domain = $log->context['domain'] ?? null;
        if (! $domain) {
            return ['success' => false, 'status' => 'missing', 'message' => 'No domain stored on this log.'];
        }

        try {
            $records = @dns_get_record($domain, DNS_A + DNS_AAAA + DNS_CNAME);
            if (empty($records)) {
                return ['success' => true, 'status' => 'fail', 'message' => "No DNS records found for {$domain}."];
            }
            return ['success' => true, 'status' => 'ok', 'message' => "DNS resolves for {$domain} (" . count($records) . ' records).'];
        } catch (Throwable $e) {
            return ['success' => false, 'status' => 'fail', 'message' => 'DNS lookup failed: ' . $e->getMessage()];
        }
    }

    private function recheckSsl(SystemLog $log): array
    {
        $domain = $log->context['domain'] ?? null;
        if (! $domain) {
            return ['success' => false, 'status' => 'missing', 'message' => 'No domain stored on this log.'];
        }

        try {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $fp  = @stream_socket_client("ssl://{$domain}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            if (! $fp) {
                return ['success' => true, 'status' => 'fail', 'message' => "Unable to connect to {$domain}: {$errstr}"];
            }
            $params = stream_context_get_params($fp);
            fclose($fp);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if (! $cert) {
                return ['success' => true, 'status' => 'fail', 'message' => 'No certificate presented.'];
            }
            $info = openssl_x509_parse($cert);
            $validTo = $info['validTo_time_t'] ?? 0;
            if ($validTo < time()) {
                return ['success' => true, 'status' => 'fail', 'message' => 'Certificate is expired.'];
            }
            $daysLeft = (int) floor(($validTo - time()) / 86400);
            return ['success' => true, 'status' => 'ok', 'message' => "Certificate valid. Expires in {$daysLeft} days."];
        } catch (Throwable $e) {
            return ['success' => false, 'status' => 'fail', 'message' => 'SSL check failed: ' . $e->getMessage()];
        }
    }

    private function recheckDatabase(SystemLog $log): array
    {
        if (! $log->tenant_id) {
            return ['success' => false, 'status' => 'missing', 'message' => 'Log has no tenant associated.'];
        }

        $tenant = Tenant::find($log->tenant_id);
        if (! $tenant) {
            return ['success' => false, 'status' => 'missing', 'message' => 'Tenant not found.'];
        }

        $creds = $tenant->getEffectiveDatabaseCredentials();

        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $creds['host'], $creds['port'], $creds['database']);
            new PDO($dsn, $creds['username'], $creds['password'], [
                PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT    => 4,
            ]);
            return ['success' => true, 'status' => 'ok', 'message' => 'Database connection succeeded.'];
        } catch (Throwable $e) {
            return ['success' => true, 'status' => 'fail', 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    private function computeHealthStatus(array $stats): array
    {
        if ($stats['critical'] > 0) {
            return ['level' => 'critical', 'label' => 'Critical'];
        }
        if ($stats['unresolved'] > 0) {
            return ['level' => 'warning', 'label' => 'Warning'];
        }
        return ['level' => 'healthy', 'label' => 'Healthy'];
    }
}
