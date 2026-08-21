<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Designation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DesignationsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Designation::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $data = [];

        $designations = Designation::with('department', 'company')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('designation', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $designations->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $designations = $designations->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($designations as $designation) {
            $data[] = [
                'id' => $designation->id,
                'designation' => $designation->designation,
                'code' => $designation->code,
                'description' => $designation->description,
                'suggested_role_key' => $designation->suggested_role_key,
                'is_system_default' => (bool) $designation->is_system_default,
                'company_name' => optional($designation->company)->name,
                'company_id' => optional($designation->company)->id,
                'department_name' => optional($designation->department)->department,
                'department_id' => optional($designation->department)->id,
            ];
        }

        return response()->json([
            'designations' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Designation::class);

        return response()->json([
            'companies' => Company::whereNull('deleted_at')->get(['id', 'name']),
            'templates' => Designation::defaultTemplates(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Designation::class);

        $validated = $request->validate([
            'designation' => 'nullable|string|max:192|required_without:template_code',
            'template_code' => 'nullable|string|max:60',
            'company_id' => 'required|integer',
            'department' => 'required|integer',
            'description' => 'nullable|string|max:500',
        ]);

        $template = $this->template($validated['template_code'] ?? null);
        if (! empty($validated['template_code']) && ! $template) {
            return response()->json(['message' => 'El puesto predeterminado seleccionado no existe.'], 422);
        }

        $name = trim((string) ($validated['designation'] ?? '')) ?: ($template['name'] ?? null);
        abort_unless($name, 422, 'Debes seleccionar un puesto predeterminado o escribir un puesto personalizado.');

        $exists = Designation::whereNull('deleted_at')
            ->where('company_id', $validated['company_id'])
            ->where('department_id', $validated['department'])
            ->whereRaw('LOWER(designation) = ?', [mb_strtolower($name)])
            ->exists();
        abort_if($exists, 422, 'Este puesto ya existe en el departamento seleccionado.');

        Designation::create([
            'designation' => $name,
            'code' => $template['code'] ?? null,
            'description' => $validated['description'] ?? ($template['description'] ?? null),
            'is_system_default' => (bool) $template,
            'is_active' => true,
            'suggested_role_key' => $template['role'] ?? null,
            'company_id' => $validated['company_id'],
            'department_id' => $validated['department'],
        ]);

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Designation::class);

        return response()->json([
            'companies' => Company::whereNull('deleted_at')->get(['id', 'name']),
            'templates' => Designation::defaultTemplates(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Designation::class);

        $validated = $request->validate([
            'designation' => 'required|string|max:192',
            'company_id' => 'required|integer',
            'department' => 'required|integer',
            'description' => 'nullable|string|max:500',
        ]);

        Designation::whereId($id)->update([
            'designation' => $validated['designation'],
            'description' => $validated['description'] ?? null,
            'company_id' => $validated['company_id'],
            'department_id' => $validated['department'],
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Designation::class);

        \DB::transaction(function () use ($id) {
            // Positions are deactivated/soft-deleted, never hard deleted, because
            // historical employees may still reference them.
            Designation::whereId($id)->update([
                'is_active' => false,
                'deleted_at' => Carbon::now(),
            ]);
        }, 10);

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Designation::class);

        foreach ((array) $request->selectedIds as $designationId) {
            Designation::whereId($designationId)->update([
                'is_active' => false,
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function Get_designations_by_department(Request $request)
    {
        return response()->json(
            Designation::where('department_id', $request->id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('designation')
                ->get()
        );
    }

    private function template(?string $code): ?array
    {
        if (! $code) return null;
        foreach (Designation::defaultTemplates() as $template) {
            if ($template['code'] === $code) return $template;
        }
        return null;
    }
}
