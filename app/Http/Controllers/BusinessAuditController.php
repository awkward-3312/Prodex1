<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessAuditController extends Controller
{
    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user, 401);

        $allowed = (int) $user->role_id === 1 || $user->hasPermissionName('setting_system');
        abort_unless($allowed, 403);

        if (! Schema::hasTable('business_audit_logs')) {
            return response()->json([
                'logs' => [],
                'totalRows' => 0,
            ]);
        }

        $perPage = (int) $request->get('limit', 25);
        $perPage = max(1, min($perPage, 100));
        $page = max(1, (int) $request->get('page', 1));

        $query = DB::table('business_audit_logs');

        if ($request->filled('event')) {
            $query->where('event', (string) $request->event);
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', (string) $request->auditable_type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->branch_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('actor_name', 'like', $like)
                    ->orWhere('auditable_type', 'like', $like)
                    ->orWhere('auditable_id', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('request_path', 'like', $like)
                    ->orWhere('ip_address', 'like', $like);
            });
        }

        $totalRows = (clone $query)->count();

        $logs = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'user_id' => $row->user_id,
                    'actor_name' => $row->actor_name,
                    'event' => $row->event,
                    'auditable_type' => $row->auditable_type,
                    'auditable_id' => $row->auditable_id,
                    'reference' => $row->reference,
                    'branch_id' => $row->branch_id,
                    'inventory_location_id' => $row->inventory_location_id,
                    'cash_drawer_id' => $row->cash_drawer_id,
                    'before_values' => $this->decodeJson($row->before_values),
                    'after_values' => $this->decodeJson($row->after_values),
                    'http_method' => $row->http_method,
                    'request_path' => $row->request_path,
                    'ip_address' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                    'created_at' => $row->created_at,
                ];
            })
            ->values();

        return response()->json([
            'logs' => $logs,
            'totalRows' => $totalRows,
            'page' => $page,
            'limit' => $perPage,
        ]);
    }

    private function decodeJson($value): array
    {
        if (! $value) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
