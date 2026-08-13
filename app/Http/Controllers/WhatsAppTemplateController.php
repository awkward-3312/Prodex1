<?php

namespace App\Http\Controllers;

use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WhatsAppTemplateController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', WhatsappTemplate::class);

        $perPage = (int) ($request->per_page ?? 25);

        $query = WhatsappTemplate::orderByDesc('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('key', 'like', "%{$search}%");
            });
        }

        $templates = $query->paginate($perPage);

        return response()->json([
            'templates' => $templates->items(),
            'total'     => $templates->total(),
            'variables' => WhatsappTemplate::AVAILABLE_VARIABLES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', WhatsappTemplate::class);

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:150'],
            'key'       => ['nullable', 'string', 'max:100'],
            'body'      => ['required', 'string', 'max:4000'],
            'language'  => ['nullable', 'string', 'max:10'],
            'category'  => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['key'] = $this->uniqueKey($validated['key'] ?? $validated['name']);
        $validated['language'] = $validated['language'] ?? 'en';
        $validated['is_active'] = $request->boolean('is_active', true);

        $template = WhatsappTemplate::create($validated);

        return response()->json(['status' => 'success', 'template' => $template], 201);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', WhatsappTemplate::class);

        return response()->json(WhatsappTemplate::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', WhatsappTemplate::class);

        $template = WhatsappTemplate::findOrFail($id);

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:150'],
            'key'       => ['required', 'string', 'max:100', Rule::unique('whatsapp_templates', 'key')->ignore($template->id)],
            'body'      => ['required', 'string', 'max:4000'],
            'language'  => ['nullable', 'string', 'max:10'],
            'category'  => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['key'] = Str::slug($validated['key'], '_');
        $validated['language'] = $validated['language'] ?? 'en';
        $validated['is_active'] = $request->boolean('is_active', true);

        $template->update($validated);

        return response()->json(['status' => 'success', 'template' => $template]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', WhatsappTemplate::class);

        WhatsappTemplate::findOrFail($id)->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * Produce a slugged key that does not collide with an existing template.
     */
    protected function uniqueKey(string $source): string
    {
        $base = Str::slug($source, '_') ?: 'template';
        $key = $base;
        $i = 1;

        while (WhatsappTemplate::where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }
}
