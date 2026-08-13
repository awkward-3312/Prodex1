<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PayrollController extends Controller
{
    // ----------- GET ALL Holidays --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Payroll::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $data = [];

        $payrolls = Payroll::with('account', 'employee', 'payment_method')->where('deleted_at', '=', null)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('account', function ($q) use ($request) {
                                $q->where('account_name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('employee', function ($q) use ($request) {
                                $q->where('username', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
        $totalRows = $payrolls->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $payrolls_data = $payrolls->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($payrolls_data as $payroll) {

            $item['id'] = $payroll->id;
            $item['Ref'] = $payroll->Ref;
            $item['account_id'] = $payroll->account_id;
            $item['employee_id'] = $payroll->employee_id;
            $item['account_name'] = $payroll['account'] ? $payroll['account']->account_name : '---';
            $item['employee_name'] = $payroll['employee']->username;
            $item['date'] = $payroll->date;
            $item['amount'] = $payroll->amount;
            $item['payment_method_id'] = $payroll->payment_method_id;
            $item['payment_method'] = $payroll['payment_method'] ? $payroll['payment_method']->name : '---';
            $item['payment_status'] = $payroll->payment_status;
            $item['receiver_account_number'] = $payroll->receiver_account_number;
            $item['payment_reference_number'] = $payroll->payment_reference_number;
            $item['documents_count'] = DB::table('payroll_documents')
                ->where('payroll_id', $payroll->id)
                ->whereNull('deleted_at')
                ->count();

            $data[] = $item;
        }

        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);
        $employees = Employee::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'username']);
        $payment_methods = PaymentMethod::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'payrolls' => $data,
            'totalRows' => $totalRows,
            'accounts' => $accounts,
            'employees' => $employees,
            'payment_methods' => $payment_methods,
        ]);
    }

    public function create(Request $request)
    {
        //
    }

    // ----------- Store new Payroll --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Payroll::class);

        request()->validate([
            'date' => 'required',
            'employee_id' => 'required',
            'amount' => 'required',
            'payment_method_id' => 'required',
        ]);

        $payroll = \DB::transaction(function () use ($request) {

            $payroll = Payroll::create([
                'user_id' => Auth::user()->id,
                'Ref' => $this->getNumberOrder(),
                'date' => $request['date'],
                'employee_id' => $request['employee_id'],
                'account_id' => $request['account_id'] ? $request['account_id'] : null,
                'amount' => $request['amount'],
                'payment_method_id' => $request['payment_method_id'],
                'payment_status' => 'paid',
                'receiver_account_number' => $request['receiver_account_number'] ?? null,
                'payment_reference_number' => $request['payment_reference_number'] ?? null,
            ]);

            $account = Account::where('id', $request['account_id'])->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($request['account_id']);
                $account->update([
                    'balance' => $account->balance - $request['amount'],
                ]);
            }

            return $payroll;

        }, 10);

        return response()->json(['success' => true, 'id' => $payroll->id]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    public function edit(Request $request, $id)
    {
        //

    }

    // -----------Update Payroll --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Payroll::class);

        request()->validate([
            'date' => 'required',
            'employee_id' => 'required',
            'amount' => 'required',
            'payment_method_id' => 'required',
        ]);

        \DB::transaction(function () use ($id, $request) {

            $payroll = Payroll::findOrFail($id);

            // delete old balance
            $account = Account::where('id', $payroll->account_id)->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($payroll->account_id);
                $account->update([
                    'balance' => $account->balance + $payroll->amount,
                ]);
            }

            Payroll::whereId($id)->update([
                'date' => $request['date'],
                'employee_id' => $request['employee_id'],
                'account_id' => $request['account_id'] ? $request['account_id'] : null,
                'amount' => $request['amount'],
                'payment_method_id' => $request['payment_method_id'],
                'receiver_account_number' => $request['receiver_account_number'] ?? null,
                'payment_reference_number' => $request['payment_reference_number'] ?? null,
            ]);

            // update new account
            $new_account = Account::where('id', $request['account_id'])->exists();

            if ($new_account) {
                // Account exists, perform the update
                $new_account = Account::find($request['account_id']);
                $new_account->update([
                    'balance' => $new_account->balance - $request['amount'],
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  Payroll --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Payroll::class);
        \DB::transaction(function () use ($id) {
            $payroll = Payroll::findOrFail($id);

            Payroll::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            $account = Account::where('id', $payroll->account_id)->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($payroll->account_id);
                $account->update([
                    'balance' => $account->balance + $payroll->amount,
                ]);
            }

            // Delete attached documents
            $documents = DB::table('payroll_documents')
                ->where('payroll_id', $id)
                ->whereNull('deleted_at')
                ->get();

            foreach ($documents as $document) {
                $filePath = public_path($document->path);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            DB::table('payroll_documents')
                ->where('payroll_id', $id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => Carbon::now()]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------- Get Payroll Documents ----------\\
    public function getDocuments($payrollId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Payroll::class);

        $payroll = Payroll::findOrFail($payrollId);

        $documents = DB::table('payroll_documents')
            ->where('payroll_id', $payrollId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'documents' => $documents,
            'status' => true,
        ]);
    }

    // ------------- Upload Payroll Documents ----------\\
    public function uploadDocuments(Request $request, $payrollId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Payroll::class);

        $payroll = Payroll::findOrFail($payrollId);

        $request->validate([
            'documents.*' => 'required|file|max:10240', // Max 10MB per file
        ]);

        $uploadedDocuments = [];

        if ($request->hasFile('documents')) {
            // Create directory if it doesn't exist
            $uploadPath = public_path('images/payroll_documents');
            if (! file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            foreach ($request->file('documents') as $file) {
                // Capture metadata BEFORE moving the file (tmp file is still readable)
                $originalName = $file->getClientOriginalName();
                $size = $file->getSize();
                $mimeType = $file->getMimeType();

                $filename = time() . '_' . Str::random(10) . '_' . $originalName;

                // Move file to public/images/payroll_documents
                $file->move($uploadPath, $filename);

                $relativePath = 'images/payroll_documents/'.$filename;

                $documentId = DB::table('payroll_documents')->insertGetId([
                    'payroll_id' => $payrollId,
                    'name' => $originalName,
                    'path' => $relativePath,
                    'size' => $size,
                    'mime_type' => $mimeType,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $uploadedDocuments[] = $documentId;
            }
        }

        return response()->json([
            'message' => 'Documents uploaded successfully',
            'documents' => $uploadedDocuments,
            'status' => true,
        ]);
    }

    // ------------- Download Payroll Document ----------\\
    public function downloadDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Payroll::class);

        $document = DB::table('payroll_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $document) {
            return response()->json([
                'message' => 'Document not found in database',
                'status' => false,
            ], 404);
        }

        $filePath = public_path($document->path);

        if (! file_exists($filePath)) {
            return response()->json([
                'message' => 'Physical file not found on server',
                'status' => false,
                'path' => $document->path,
            ], 404);
        }

        return response()->download($filePath, $document->name);
    }

    // ------------- Delete Payroll Document ----------\\
    public function deleteDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'delete', Payroll::class);

        $document = DB::table('payroll_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $document) {
            return response()->json([
                'message' => 'Document not found',
                'status' => false,
            ], 404);
        }

        // Soft delete
        DB::table('payroll_documents')
            ->where('id', $documentId)
            ->update(['deleted_at' => Carbon::now()]);

        // Optionally delete the physical file
        $filePath = public_path($document->path);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return response()->json([
            'message' => 'Document deleted successfully',
            'status' => true,
        ]);
    }

    // ------------ Reference Number of Adjustement  -----------\\

    public function getNumberOrder()
    {

        $last = DB::table('payrolls')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = $nwMsg[1] + 1;
            $code = $nwMsg[0].'_'.$inMsg;
        } else {
            $code = 'PS_1';
        }

        return $code;

    }
}
