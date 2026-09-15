<?php

namespace Tests\Feature;

use App\Http\Controllers\hrm\AttendancesController;
use App\Http\Controllers\hrm\HolidayController;
use App\Http\Controllers\hrm\OfficeShiftController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression coverage for the "sort by alias/relation" 500 bug shared by
 * HolidayController, AttendancesController and OfficeShiftController.
 *
 * Root cause: index() fed the raw `SortField`/`SortType` request values
 * straight into orderBy() against a query built with with() (eager loading
 * via a separate query, not a JOIN). Sorting by a relation alias
 * (company_name, employee_username) has no matching physical column on the
 * base table, so it produced "Unknown column" SQL errors (500). An
 * unrecognized SortField, or any SortType other than asc/desc, reached
 * orderBy()/the query builder unvalidated.
 *
 * Fix: an explicit SortField => table-qualified-column whitelist per
 * controller, a conditional leftJoin() only when the specific alias is
 * requested, and a strict asc/desc check for SortType. These tests hit the
 * real HTTP-shaped index() endpoints against a minimal sqlite schema.
 */
class HrmSortByAliasTest extends TestCase
{
    protected function buildHrmSortSchema(): void
    {
        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->integer('role_id')->nullable();
            $t->boolean('is_all_warehouses')->default(1);
            $t->boolean('record_view')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('companies', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('country')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('employees', function ($t) {
            $t->increments('id');
            $t->string('firstname')->nullable();
            $t->string('lastname')->nullable();
            $t->string('username')->nullable();
            $t->integer('company_id')->nullable();
            $t->integer('office_shift_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('holidays', function ($t) {
            $t->increments('id');
            $t->string('title')->nullable();
            $t->integer('company_id')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('office_shifts', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('company_id')->nullable();
            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
                $t->string($day.'_in')->nullable();
                $t->string($day.'_out')->nullable();
            }
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('attendances', function ($t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->integer('employee_id')->nullable();
            $t->integer('company_id')->nullable();
            $t->date('date')->nullable();
            $t->string('clock_in')->nullable();
            $t->string('clock_out')->nullable();
            $t->string('clock_in_ip')->nullable();
            $t->string('clock_out_ip')->nullable();
            $t->integer('clock_in_out')->default(0);
            $t->string('depart_early')->nullable();
            $t->string('late_time')->nullable();
            $t->string('overtime')->nullable();
            $t->string('total_work')->nullable();
            $t->string('total_rest')->nullable();
            $t->string('status')->nullable();
            $t->string('source')->nullable();
            $t->string('source_reference')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    protected function sortOwner(): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'role_id' => 1,
            'is_all_warehouses' => 1,
        ])->save();

        $this->actingAs($user, 'api');
        $this->actingAs($user);
        Gate::before(fn ($u = null) => true);

        return $user;
    }

    protected function makeCompany(string $name): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildHrmSortSchema();
        $this->sortOwner();

        // Test-only routes bound directly to the real controller methods -
        // tenant_api.php's routes live behind stancl/tenancy domain
        // resolution, which this schema-only test does not set up. This
        // exercises the exact same index() code under test via a real HTTP
        // request/response cycle.
        Route::middleware('auth:api')->get('/api/holidays', [HolidayController::class, 'index']);
        Route::middleware('auth:api')->get('/api/attendances', [AttendancesController::class, 'index']);
        Route::middleware('auth:api')->get('/api/office_shifts', [OfficeShiftController::class, 'index']);
    }

    // ---- HOLIDAYS -------------------------------------------------------

    private function seedHolidays(): array
    {
        $companyA = $this->makeCompany('Alpha Co');
        $companyB = $this->makeCompany('Zeta Co');

        DB::table('holidays')->insert([
            ['title' => 'Christmas', 'company_id' => $companyA, 'start_date' => '2026-12-25', 'end_date' => '2026-12-25', 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'New Year', 'company_id' => $companyB, 'start_date' => '2026-01-01', 'end_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$companyA, $companyB];
    }

    public function test_holidays_sort_by_valid_base_column(): void
    {
        $this->seedHolidays();

        $response = $this->getJson('/api/holidays?SortField=title&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $titles = collect($response->json('holidays'))->pluck('title');
        $this->assertSame(['Christmas', 'New Year'], $titles->values()->all());
    }

    public function test_holidays_sort_by_company_name_alias_does_not_500(): void
    {
        $this->seedHolidays();

        $response = $this->getJson('/api/holidays?SortField=company_name&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $names = collect($response->json('holidays'))->pluck('company_name');
        $this->assertSame(['Alpha Co', 'Zeta Co'], $names->values()->all());
    }

    public function test_holidays_invalid_sort_field_falls_back_safely(): void
    {
        $this->seedHolidays();

        $response = $this->getJson('/api/holidays?SortField=not_a_real_column; DROP TABLE holidays;--&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $this->assertSame(2, $response->json('totalRows'));
    }

    public function test_holidays_invalid_sort_type_falls_back_to_desc(): void
    {
        $this->seedHolidays();

        $response = $this->getJson('/api/holidays?SortField=title&SortType=banana&limit=10&page=1');

        $response->assertOk();
        $titles = collect($response->json('holidays'))->pluck('title');
        $this->assertSame(['New Year', 'Christmas'], $titles->values()->all());
    }

    public function test_holidays_sort_combined_with_search_page_limit(): void
    {
        $this->seedHolidays();

        $response = $this->getJson('/api/holidays?SortField=company_name&SortType=desc&search=Christmas&limit=5&page=1');

        $response->assertOk();
        $this->assertSame(1, $response->json('totalRows'));
        $this->assertSame('Christmas', $response->json('holidays.0.title'));
    }

    // ---- ATTENDANCE -------------------------------------------------------

    private function seedAttendance(): void
    {
        $companyA = $this->makeCompany('Alpha Co');
        $companyB = $this->makeCompany('Zeta Co');

        $empA = (int) DB::table('employees')->insertGetId([
            'username' => 'alice', 'company_id' => $companyA, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $empB = (int) DB::table('employees')->insertGetId([
            'username' => 'zack', 'company_id' => $companyB, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('attendances')->insert([
            ['employee_id' => $empA, 'company_id' => $companyA, 'date' => '2026-09-01', 'clock_in' => '09:00', 'clock_out' => '17:00', 'created_at' => now(), 'updated_at' => now()],
            ['employee_id' => $empB, 'company_id' => $companyB, 'date' => '2026-09-02', 'clock_in' => '09:00', 'clock_out' => '17:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_attendance_sort_by_date(): void
    {
        $this->seedAttendance();

        $response = $this->getJson('/api/attendances?SortField=date&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $dates = collect($response->json('attendances'))->pluck('date');
        $this->assertSame(['2026-09-01', '2026-09-02'], $dates->values()->all());
    }

    public function test_attendance_sort_by_employee_username_alias_does_not_500(): void
    {
        $this->seedAttendance();

        $response = $this->getJson('/api/attendances?SortField=employee_username&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $usernames = collect($response->json('attendances'))->pluck('employee_username');
        $this->assertSame(['alice', 'zack'], $usernames->values()->all());
    }

    public function test_attendance_sort_by_company_name_alias_does_not_500(): void
    {
        $this->seedAttendance();

        $response = $this->getJson('/api/attendances?SortField=company_name&SortType=desc&limit=10&page=1');

        $response->assertOk();
        $names = collect($response->json('attendances'))->pluck('company_name');
        $this->assertSame(['Zeta Co', 'Alpha Co'], $names->values()->all());
    }

    public function test_attendance_invalid_sort_field_falls_back_safely(): void
    {
        $this->seedAttendance();

        $response = $this->getJson('/api/attendances?SortField=bogus_column&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $this->assertSame(2, $response->json('totalRows'));
    }

    public function test_attendance_invalid_sort_type_falls_back_to_desc(): void
    {
        $this->seedAttendance();

        $response = $this->getJson('/api/attendances?SortField=date&SortType=xyz&limit=10&page=1');

        $response->assertOk();
        $dates = collect($response->json('attendances'))->pluck('date');
        $this->assertSame(['2026-09-02', '2026-09-01'], $dates->values()->all());
    }

    public function test_attendance_sort_combined_with_search_page_limit(): void
    {
        $this->seedAttendance();

        $response = $this->getJson('/api/attendances?SortField=employee_username&SortType=asc&search=alice&limit=5&page=1');

        $response->assertOk();
        $this->assertSame(1, $response->json('totalRows'));
        $this->assertSame('alice', $response->json('attendances.0.employee_username'));
    }

    // ---- OFFICE SHIFT -------------------------------------------------------

    private function seedOfficeShifts(): void
    {
        $companyA = $this->makeCompany('Alpha Co');
        $companyB = $this->makeCompany('Zeta Co');

        DB::table('office_shifts')->insert([
            ['name' => 'Morning', 'company_id' => $companyA, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Night', 'company_id' => $companyB, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_office_shift_sort_by_name(): void
    {
        $this->seedOfficeShifts();

        $response = $this->getJson('/api/office_shifts?SortField=name&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $names = collect($response->json('office_shifts'))->pluck('name');
        $this->assertSame(['Morning', 'Night'], $names->values()->all());
    }

    public function test_office_shift_sort_by_company_name_alias_does_not_500(): void
    {
        $this->seedOfficeShifts();

        $response = $this->getJson('/api/office_shifts?SortField=company_name&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $names = collect($response->json('office_shifts'))->pluck('company_name');
        $this->assertSame(['Alpha Co', 'Zeta Co'], $names->values()->all());
    }

    public function test_office_shift_invalid_sort_field_falls_back_safely(): void
    {
        $this->seedOfficeShifts();

        $response = $this->getJson('/api/office_shifts?SortField=totally_invalid&SortType=asc&limit=10&page=1');

        $response->assertOk();
        $this->assertSame(2, $response->json('totalRows'));
    }

    public function test_office_shift_invalid_sort_type_falls_back_to_desc(): void
    {
        $this->seedOfficeShifts();

        $response = $this->getJson('/api/office_shifts?SortField=name&SortType=notadirection&limit=10&page=1');

        $response->assertOk();
        $names = collect($response->json('office_shifts'))->pluck('name');
        $this->assertSame(['Night', 'Morning'], $names->values()->all());
    }

    public function test_office_shift_sort_combined_with_search_page_limit(): void
    {
        $this->seedOfficeShifts();

        $response = $this->getJson('/api/office_shifts?SortField=company_name&SortType=desc&search=Morning&limit=5&page=1');

        $response->assertOk();
        $this->assertSame(1, $response->json('totalRows'));
        $this->assertSame('Morning', $response->json('office_shifts.0.name'));
    }
}
