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
        // Filters
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

        return response()->json([
            'data' => $rows,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        $this->validate($request, [
            'code' => 'required|max:64',
            'name' => 'required|max:192',
            'type' => 'required|in:asset,liability,equity,income,expense',
        ]);
        $coa = ChartOfAccount::create([
            'account_id' => $request->get('account_id'),
            'code' => $request->get('code'),
            'name' => $request->get('name'),
            'type' => $request->get('type'),
            'parent_id' => $request->get('parent_id'),
            'level' => (int) $request->get('level', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($coa, 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        $coa = ChartOfAccount::findOrFail($id);
        $this->validate($request, [
            'code' => 'sometimes|max:64',
            'name' => 'sometimes|max:192',
            'type' => 'sometimes|in:asset,liability,equity,income,expense',
        ]);
        $coa->update($request->only(['account_id', 'code', 'name', 'type', 'parent_id', 'level', 'is_active']));

        return response()->json($coa);
    }

    public function import(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        if (! Schema::hasTable('acc_chart_of_accounts')) {
            return response()->json([
                'status' => false,
                'message' => 'Chart of accounts is not available.',
                'errors' => ['Chart of accounts table does not exist. Run tenant migrations first.'],
            ], 422);
        }

        $v = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB
        ]);
        if ($v->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $v->errors()->all(),
            ], 422);
        }

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to read the uploaded file.',
                'errors' => ['Unable to read the uploaded file.'],
            ], 422);
        }

        // Header row (strip UTF-8 BOM, normalize to snake_case)
        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);

            return response()->json([
                'status' => false,
                'message' => 'The imported file is empty.',
                'errors' => ['No data found in the uploaded file.'],
            ], 422);
        }
        $header = array_map(function ($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);

            return str_replace([' ', '-'], '_', strtolower(trim($h)));
        }, $header);

        $required = ['code', 'name', 'type'];
        $missing = array_diff($required, $header);
        if (! empty($missing)) {
            fclose($handle);

            return response()->json([
                'status' => false,
                'message' => 'Invalid file header',
                'errors' => ['Missing required column(s): '.implode(', ', $missing).'. Expected columns: code, name, type, parent_code (optional), is_active (optional).'],
            ], 422);
        }

        $types = ['asset', 'liability', 'equity', 'income', 'expense'];
        $errors = [];
        $prepared = [];
        $codesInFile = [];
        $line = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            if ($raw === [null] || count(array_filter($raw, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // skip blank lines
            }
            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = isset($raw[$idx]) ? trim((string) $raw[$idx]) : '';
            }

            $code = $row['code'] ?? '';
            $name = $row['name'] ?? '';
            $type = strtolower($row['type'] ?? '');
            $parentCode = $row['parent_code'] ?? '';
            $activeRaw = strtolower($row['is_active'] ?? '');

            if ($code === '') {
                $errors[] = "Row {$line}: code is required.";
            } elseif (mb_strlen($code) > 64) {
                $errors[] = "Row {$line}: code must not exceed 64 characters.";
            } elseif (isset($codesInFile[$code])) {
                $errors[] = "Row {$line}: duplicate code '{$code}' found in the file (also on row {$codesInFile[$code]}).";
            } else {
                $codesInFile[$code] = $line;
            }

            if ($name === '') {
                $errors[] = "Row {$line}: name is required.";
            } elseif (mb_strlen($name) > 192) {
                $errors[] = "Row {$line}: name must not exceed 192 characters.";
            }

            if (! in_array($type, $types, true)) {
                $errors[] = "Row {$line}: type '{$row['type']}' is invalid. Allowed: ".implode(', ', $types).'.';
            }

            if ($parentCode !== '' && $parentCode === $code) {
                $errors[] = "Row {$line}: parent_code cannot be the same as code.";
            }

            $isActive = true;
            if ($activeRaw !== '') {
                if (in_array($activeRaw, ['1', 'yes', 'true', 'active'], true)) {
                    $isActive = true;
                } elseif (in_array($activeRaw, ['0', 'no', 'false', 'inactive'], true)) {
                    $isActive = false;
                } else {
                    $errors[] = "Row {$line}: is_active '{$row['is_active']}' is invalid. Use 1/0, yes/no, true/false.";
                }
            }

            $prepared[] = [
                'line' => $line,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_code' => $parentCode,
                'is_active' => $isActive,
            ];
        }
        fclose($handle);

        if (empty($prepared) && empty($errors)) {
            return response()->json([
                'status' => false,
                'message' => 'The imported file is empty.',
                'errors' => ['No data rows found in the uploaded file.'],
            ], 422);
        }

        // Parent codes must exist in the file or in the database
        $existing = ChartOfAccount::query()->get(['id', 'code', 'parent_id']);
        $existingCodes = $existing->keyBy(fn ($a) => (string) $a->code);
        foreach ($prepared as $p) {
            if ($p['parent_code'] !== '' && ! isset($codesInFile[$p['parent_code']]) && ! isset($existingCodes[$p['parent_code']])) {
                $errors[] = "Row {$p['line']}: parent_code '{$p['parent_code']}' does not match any account code in the file or in the existing chart of accounts.";
            }
        }

        // Detect circular parent relationships (file rows override existing parents)
        $codeById = $existing->keyBy('id');
        $parentCodeOf = [];
        foreach ($existing as $acc) {
            $parentCodeOf[(string) $acc->code] = ($acc->parent_id && isset($codeById[$acc->parent_id])) ? (string) $codeById[$acc->parent_id]->code : null;
        }
        foreach ($prepared as $p) {
            $parentCodeOf[$p['code']] = $p['parent_code'] !== '' ? $p['parent_code'] : null;
        }
        foreach ($prepared as $p) {
            $seen = [];
            $cur = $p['code'];
            while ($cur !== null) {
                if (isset($seen[$cur])) {
                    $errors[] = "Row {$p['line']}: circular parent relationship detected for code '{$p['code']}'.";
                    break;
                }
                $seen[$cur] = true;
                $cur = $parentCodeOf[$cur] ?? null;
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed. Fix the errors below and try again.',
                'errors' => $errors,
            ], 422);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($prepared, $parentCodeOf, &$created, &$updated) {
            // Pass 1: create/update accounts by code
            foreach ($prepared as $p) {
                $coa = ChartOfAccount::query()->where('code', $p['code'])->orderBy('id')->first();
                $attributes = [
                    'name' => $p['name'],
                    'type' => $p['type'],
                    'is_active' => $p['is_active'],
                ];
                if ($coa) {
                    $coa->update($attributes);
                    $updated++;
                } else {
                    ChartOfAccount::create($attributes + ['code' => $p['code'], 'level' => 0]);
                    $created++;
                }
            }

            // Pass 2: resolve parent relationships; level = depth of the parent chain.
            // Descending order so keyBy() keeps the lowest id per code, matching pass 1.
            $byCode = ChartOfAccount::query()->orderByDesc('id')->get()->keyBy(fn ($a) => (string) $a->code);
            foreach ($prepared as $p) {
                $coa = $byCode[$p['code']] ?? null;
                if (! $coa) {
                    continue;
                }
                $parent = $p['parent_code'] !== '' ? ($byCode[$p['parent_code']] ?? null) : null;
                $depth = 0;
                $cur = $parentCodeOf[$p['code']] ?? null;
                while ($cur !== null && $depth < 100) {
                    $depth++;
                    $cur = $parentCodeOf[$cur] ?? null;
                }
                $coa->update([
                    'parent_id' => $parent ? $parent->id : null,
                    'level' => $depth,
                ]);
            }
        });

        return response()->json([
            'status' => true,
            'message' => 'Successfully Imported',
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        $coa = ChartOfAccount::findOrFail($id);
        $coa->delete();

        return response()->json(['success' => true]);
    }
}
