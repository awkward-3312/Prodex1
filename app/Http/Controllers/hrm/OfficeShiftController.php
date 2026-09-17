<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OfficeShift;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OfficeShiftController extends Controller
{
    // ----------- GET ALL  office_shift --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', OfficeShift::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $data = [];
        $office_shifts = OfficeShift::with('company:id,name')->where('deleted_at', '=', null)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $office_shifts->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $office_shifts = $office_shifts->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($office_shifts as $office_shift) {

            $item['id'] = $office_shift->id;
            $item['name'] = $office_shift->name;
            $item['company_id'] = $office_shift['company']->id;
            $item['company_name'] = $office_shift['company']->name;
            // normalizeTime() replaces the old blind substr($v, 0, -2): that
            // assumed every stored value had a 2-char AM/PM suffix, which is
            // false for canonical "H:i" writes (would corrupt "09:00" into
            // "09:"). normalizeTime() safely handles both shapes.
            $item['monday_in'] = OfficeShift::normalizeTime($office_shift->monday_in);
            $item['monday_out'] = OfficeShift::normalizeTime($office_shift->monday_out);
            $item['tuesday_in'] = OfficeShift::normalizeTime($office_shift->tuesday_in);
            $item['tuesday_out'] = OfficeShift::normalizeTime($office_shift->tuesday_out);
            $item['wednesday_in'] = OfficeShift::normalizeTime($office_shift->wednesday_in);
            $item['wednesday_out'] = OfficeShift::normalizeTime($office_shift->wednesday_out);
            $item['thursday_in'] = OfficeShift::normalizeTime($office_shift->thursday_in);
            $item['thursday_out'] = OfficeShift::normalizeTime($office_shift->thursday_out);
            $item['friday_in'] = OfficeShift::normalizeTime($office_shift->friday_in);
            $item['friday_out'] = OfficeShift::normalizeTime($office_shift->friday_out);
            $item['saturday_in'] = OfficeShift::normalizeTime($office_shift->saturday_in);
            $item['saturday_out'] = OfficeShift::normalizeTime($office_shift->saturday_out);
            $item['sunday_in'] = OfficeShift::normalizeTime($office_shift->sunday_in);
            $item['sunday_out'] = OfficeShift::normalizeTime($office_shift->sunday_out);
            $data[] = $item;
        }

        return response()->json([
            'office_shifts' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', OfficeShift::class);

        $companies = Company::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'companies' => $companies,
        ]);

    }

    // ----------- Store new office_shift --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', OfficeShift::class);

        request()->validate([
            'name' => 'required|string',
            'company_id' => 'required',
        ]);

        // Canonical storage format going forward: 24h "H:i" (e.g. "09:00",
        // "18:00"), unambiguous, no AM/PM. normalizeTime() also tolerates
        // whatever legacy-shaped string might come in (defensive only - the
        // clock-picker always sends plain "H:i").
        OfficeShift::create([
            'company_id' => $request['company_id'],
            'name' => $request['name'],
            'monday_in' => OfficeShift::normalizeTime($request['monday_in']),
            'monday_out' => OfficeShift::normalizeTime($request['monday_out']),
            'tuesday_in' => OfficeShift::normalizeTime($request['tuesday_in']),
            'tuesday_out' => OfficeShift::normalizeTime($request['tuesday_out']),
            'wednesday_in' => OfficeShift::normalizeTime($request['wednesday_in']),
            'wednesday_out' => OfficeShift::normalizeTime($request['wednesday_out']),
            'thursday_in' => OfficeShift::normalizeTime($request['thursday_in']),
            'thursday_out' => OfficeShift::normalizeTime($request['thursday_out']),
            'friday_in' => OfficeShift::normalizeTime($request['friday_in']),
            'friday_out' => OfficeShift::normalizeTime($request['friday_out']),
            'saturday_in' => OfficeShift::normalizeTime($request['saturday_in']),
            'saturday_out' => OfficeShift::normalizeTime($request['saturday_out']),
            'sunday_in' => OfficeShift::normalizeTime($request['sunday_in']),
            'sunday_out' => OfficeShift::normalizeTime($request['sunday_out']),
        ]);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ------------ function edit -----------\\

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', OfficeShift::class);

        $companies = Company::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'companies' => $companies,
        ]);

    }

    // -----------Update office_shift --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', OfficeShift::class);

        // Same canonical 24h "H:i" storage as store(). normalizeTime() also
        // tolerates a value that still carries the old AM/PM suffix (e.g. if
        // this request round-tripped a row nobody has re-saved yet), so an
        // update to one day never corrupts another day's already-legacy value.
        OfficeShift::whereId($id)->update([
            'company_id' => $request['company_id'],
            'name' => $request['name'],
            'monday_in' => OfficeShift::normalizeTime($request['monday_in']),
            'monday_out' => OfficeShift::normalizeTime($request['monday_out']),
            'tuesday_in' => OfficeShift::normalizeTime($request['tuesday_in']),
            'tuesday_out' => OfficeShift::normalizeTime($request['tuesday_out']),
            'wednesday_in' => OfficeShift::normalizeTime($request['wednesday_in']),
            'wednesday_out' => OfficeShift::normalizeTime($request['wednesday_out']),
            'thursday_in' => OfficeShift::normalizeTime($request['thursday_in']),
            'thursday_out' => OfficeShift::normalizeTime($request['thursday_out']),
            'friday_in' => OfficeShift::normalizeTime($request['friday_in']),
            'friday_out' => OfficeShift::normalizeTime($request['friday_out']),
            'saturday_in' => OfficeShift::normalizeTime($request['saturday_in']),
            'saturday_out' => OfficeShift::normalizeTime($request['saturday_out']),
            'sunday_in' => OfficeShift::normalizeTime($request['sunday_in']),
            'sunday_out' => OfficeShift::normalizeTime($request['sunday_out']),
        ]);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  office_shift --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', OfficeShift::class);

        \DB::transaction(function () use ($id) {

            OfficeShift::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', OfficeShift::class);

        $selectedIds = $request->selectedIds;
        foreach ($selectedIds as $office_shift_id) {
            OfficeShift::whereId($office_shift_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }
}
