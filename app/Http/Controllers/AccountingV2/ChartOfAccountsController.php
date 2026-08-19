<?php

namespace App\Http\Controllers\AccountingV2;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingV2\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class ChartOfAccountsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        if (! Schema::hasTable('acc_chart_of_accounts')) {
            return response()->json(['data' => [], 'totalRows' => 0]);
        }
        $query = ChartOfAccount::query();
        if ($request->filled('type')) {
            $query->where('type', $request->get('type'));
        }
        if ($request->has('active')) {
            $query->where('is_active', (int) $request->get('active') === 1);
        }
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")->orWhere('name', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        $perPage = (int) ($request->get('limit') ?: 10);
        if ($perPage === -1) {
            $perPage = $totalRows > 0 ? $totalRows : 10;
        }
        $page = max(1, (int) ($request->get('page') ?: 1));
        $offSet = ($page * $perPage) - $perPage;
        $order = $request->get('SortField', 'code');
        $dir = strtolower((string) $request->input('SortType')) === 'desc' ? 'desc' : 'asc';

        $rows = $query->orderBy($order, $dir)->offset($offSet)->limit($perPage)->get();

        return response()->json(['data' => $rows, 'totalRows' => $totalRows]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);
        $this->validate($request, ['code' => 'required|max:64', 'name' => 'required|max:192', 'type' => 'required|in:asset,liability,equity,income,expense']);
        $coa = ChartOfAccount::create([
            'account_id' => $request->get('account_id'), 'code' => $request->get('code'), 'name' => $request->get('name'),
            'type' => $request->get('type'), 'parent_id' => $request->get('parent_id'), 'level' => (int) $request->get('level', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);
        return response()->json($coa, 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);
        $coa = ChartOfAccount::findOrFail($id);
        $this->validate($request, ['code' => 'sometimes|max:64', 'name' => 'sometimes|max:192', 'type' => 'sometimes|in:asset,liability,equity,income,expense']);
        $coa->update($request->only(['account_id', 'code', 'name', 'type', 'parent_id', 'level', 'is_active']));
        return response()->json($coa);
    }

    public function import(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        if (! Schema::hasTable('acc_chart_of_accounts')) {
            return response()->json(['status' => false, 'message' => 'El catálogo de cuentas no está disponible.', 'errors' => ['La tabla del catálogo de cuentas no existe. Ejecuta primero las migraciones del tenant.']], 422);
        }

        $v = Validator::make($request->all(), ['file' => 'required|file|mimes:csv,txt|max:10240']);
        if ($v->fails()) {
            return response()->json(['status' => false, 'message' => 'La validación falló.', 'errors' => $v->errors()->all()], 422);
        }

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['status' => false, 'message' => 'No se pudo leer el archivo cargado.', 'errors' => ['No se pudo leer el archivo cargado.']], 422);
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);
            return response()->json(['status' => false, 'message' => 'El archivo importado está vacío.', 'errors' => ['No se encontraron datos en el archivo cargado.']], 422);
        }
        $header = array_map(function ($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);
            return str_replace([' ', '-'], '_', strtolower(trim($h)));
        }, $header);

        $required = ['code', 'name', 'type'];
        $missing = array_diff($required, $header);
        if (! empty($missing)) {
            fclose($handle);
            return response()->json(['status' => false, 'message' => 'El encabezado del archivo no es válido.', 'errors' => ['Faltan columnas obligatorias: '.implode(', ', $missing).'. Columnas esperadas: code, name, type, parent_code (opcional), is_active (opcional).']], 422);
        }

        $types = ['asset', 'liability', 'equity', 'income', 'expense'];
        $errors = []; $prepared = []; $codesInFile = []; $line = 1;
        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            if ($raw === [null] || count(array_filter($raw, fn ($c) => trim((string) $c) !== '')) === 0) continue;
            $row = [];
            foreach ($header as $idx => $key) $row[$key] = isset($raw[$idx]) ? trim((string) $raw[$idx]) : '';
            $code = $row['code'] ?? ''; $name = $row['name'] ?? ''; $type = strtolower($row['type'] ?? '');
            $parentCode = $row['parent_code'] ?? ''; $activeRaw = strtolower($row['is_active'] ?? '');

            if ($code === '') $errors[] = "Fila {$line}: el código es obligatorio.";
            elseif (mb_strlen($code) > 64) $errors[] = "Fila {$line}: el código no puede exceder 64 caracteres.";
            elseif (isset($codesInFile[$code])) $errors[] = "Fila {$line}: el código duplicado '{$code}' también aparece en la fila {$codesInFile[$code]}.";
            else $codesInFile[$code] = $line;

            if ($name === '') $errors[] = "Fila {$line}: el nombre es obligatorio.";
            elseif (mb_strlen($name) > 192) $errors[] = "Fila {$line}: el nombre no puede exceder 192 caracteres.";
            if (! in_array($type, $types, true)) $errors[] = "Fila {$line}: el tipo '{$row['type']}' no es válido. Valores permitidos: ".implode(', ', $types).'.';
            if ($parentCode !== '' && $parentCode === $code) $errors[] = "Fila {$line}: parent_code no puede ser igual a code.";

            $isActive = true;
            if ($activeRaw !== '') {
                if (in_array($activeRaw, ['1', 'yes', 'true', 'active'], true)) $isActive = true;
                elseif (in_array($activeRaw, ['0', 'no', 'false', 'inactive'], true)) $isActive = false;
                else $errors[] = "Fila {$line}: is_active '{$row['is_active']}' no es válido. Usa 1/0, yes/no o true/false.";
            }
            $prepared[] = ['line' => $line, 'code' => $code, 'name' => $name, 'type' => $type, 'parent_code' => $parentCode, 'is_active' => $isActive];
        }
        fclose($handle);

        if (empty($prepared) && empty($errors)) return response()->json(['status' => false, 'message' => 'El archivo importado está vacío.', 'errors' => ['No se encontraron filas de datos en el archivo cargado.']], 422);

        $existing = ChartOfAccount::query()->get(['id', 'code', 'parent_id']);
        $existingCodes = $existing->keyBy(fn ($a) => (string) $a->code);
        foreach ($prepared as $p) {
            if ($p['parent_code'] !== '' && ! isset($codesInFile[$p['parent_code']]) && ! isset($existingCodes[$p['parent_code']])) $errors[] = "Fila {$p['line']}: parent_code '{$p['parent_code']}' no coincide con ninguna cuenta del archivo ni del catálogo existente.";
        }
        $codeById = $existing->keyBy('id'); $parentCodeOf = [];
        foreach ($existing as $acc) $parentCodeOf[(string) $acc->code] = ($acc->parent_id && isset($codeById[$acc->parent_id])) ? (string) $codeById[$acc->parent_id]->code : null;
        foreach ($prepared as $p) $parentCodeOf[$p['code']] = $p['parent_code'] !== '' ? $p['parent_code'] : null;
        foreach ($prepared as $p) {
            $seen = []; $cur = $p['code'];
            while ($cur !== null) {
                if (isset($seen[$cur])) { $errors[] = "Fila {$p['line']}: se detectó una relación circular de cuentas para el código '{$p['code']}'."; break; }
                $seen[$cur] = true; $cur = $parentCodeOf[$cur] ?? null;
            }
        }
        if (! empty($errors)) return response()->json(['status' => false, 'message' => 'La importación falló. Corrige los errores indicados e inténtalo de nuevo.', 'errors' => $errors], 422);

        $created = 0; $updated = 0;
        DB::transaction(function () use ($prepared, $parentCodeOf, &$created, &$updated) {
            foreach ($prepared as $p) {
                $coa = ChartOfAccount::query()->where('code', $p['code'])->orderBy('id')->first();
                $attributes = ['name' => $p['name'], 'type' => $p['type'], 'is_active' => $p['is_active']];
                if ($coa) { $coa->update($attributes); $updated++; } else { ChartOfAccount::create($attributes + ['code' => $p['code'], 'level' => 0]); $created++; }
            }
            $byCode = ChartOfAccount::query()->orderByDesc('id')->get()->keyBy(fn ($a) => (string) $a->code);
            foreach ($prepared as $p) {
                $coa = $byCode[$p['code']] ?? null; if (! $coa) continue;
                $parent = $p['parent_code'] !== '' ? ($byCode[$p['parent_code']] ?? null) : null;
                $depth = 0; $cur = $parentCodeOf[$p['code']] ?? null;
                while ($cur !== null && $depth < 100) { $depth++; $cur = $parentCodeOf[$cur] ?? null; }
                $coa->update(['parent_id' => $parent ? $parent->id : null, 'level' => $depth]);
            }
        });

        return response()->json(['status' => true, 'message' => 'Importación completada correctamente.', 'created' => $created, 'updated' => $updated]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);
        $coa = ChartOfAccount::findOrFail($id); $coa->delete();
        return response()->json(['success' => true]);
    }
}
