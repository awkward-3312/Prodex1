<?php

namespace App\Http\Controllers;

use App\Models\WhatsappLog;
use Illuminate\Http\Request;

class WhatsAppLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', WhatsappLog::class);

        $perPage = (int) ($request->per_page ?? 15);

        $query = WhatsappLog::orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('message_type')) {
            $query->where('message_type', $request->message_type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('recipient', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%")
                  ->orWhere('template_key', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate($perPage);

        return response()->json([
            'logs'  => $logs->items(),
            'total' => $logs->total(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', WhatsappLog::class);

        WhatsappLog::findOrFail($id)->delete();

        return response()->json(['status' => 'success']);
    }
}
