<?php

namespace Tests\Unit;

use App\Http\Controllers\hrm\AttendancesController;
use App\Models\Employee;
use App\Models\OfficeShift;
use Carbon\Carbon;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression coverage for the office-shift time-format bug fix.
 *
 * Root cause: OfficeShiftController::store()/update() wrote
 * `DateTime::format('H:iA')` — 'H' is already 24h (00-23), so the digits
 * were always the true 24h time; the trailing AM/PM was decorative and,
 * for any hour outside 1-12, nonsensical (e.g. "18:00PM"). These tests
 * exercise the shared `OfficeShift::normalizeTime()` parser and the
 * `AttendancesController` calculation it feeds, entirely at the PHP level
 * (no HTTP, no tenant DB) since both are pure functions of their inputs.
 */
class OfficeShiftAttendanceTimeTest extends TestCase
{
    // ---- OfficeShift::normalizeTime() ----------------------------------

    public function test_canonical_24h_values_pass_through(): void
    {
        $this->assertSame('09:00', OfficeShift::normalizeTime('09:00'));
        $this->assertSame('18:00', OfficeShift::normalizeTime('18:00'));
        $this->assertSame('00:00', OfficeShift::normalizeTime('00:00'));
        $this->assertSame('23:59', OfficeShift::normalizeTime('23:59'));
    }

    public function test_legacy_valid_looking_am_pm_values_normalize_correctly(): void
    {
        // Case D: legacy value where the digit happens to double as a valid
        // 12h hour too - must still resolve to the true 24h value (08:00),
        // never re-interpreted as a 12h time (which would coincidentally be
        // the same value here, but must not silently rely on that).
        $this->assertSame('08:00', OfficeShift::normalizeTime('08:00AM'));
        $this->assertSame('12:00', OfficeShift::normalizeTime('12:00PM'));
        $this->assertSame('00:00', OfficeShift::normalizeTime('00:00AM'));
    }

    public function test_legacy_invalid_18_00_pm_normalizes_to_18_00_never_06_00(): void
    {
        // Case E, and the explicit "never becomes 06:00" requirement.
        $this->assertSame('18:00', OfficeShift::normalizeTime('18:00PM'));
        $this->assertNotSame('06:00', OfficeShift::normalizeTime('18:00PM'));
        $this->assertSame('17:00', OfficeShift::normalizeTime('17:00PM'));
        $this->assertSame('23:00', OfficeShift::normalizeTime('23:00PM'));
    }

    public function test_null_and_empty_values_normalize_to_null(): void
    {
        // Case F support: a day with no schedule has empty/null columns.
        $this->assertNull(OfficeShift::normalizeTime(null));
        $this->assertNull(OfficeShift::normalizeTime(''));
        $this->assertNull(OfficeShift::normalizeTime('   '));
    }

    public function test_unparsable_garbage_normalizes_to_null_instead_of_throwing(): void
    {
        $this->assertNull(OfficeShift::normalizeTime('not-a-time'));
        $this->assertNull(OfficeShift::normalizeTime('25:00'));
        $this->assertNull(OfficeShift::normalizeTime('09:60'));
    }

    public function test_reopening_editor_shows_the_same_value_that_was_saved(): void
    {
        // Case J: simulate store() writing the canonical value, then index()
        // reading it back for the edit modal - must be stable/idempotent.
        $storedNew = OfficeShift::normalizeTime('18:00');
        $this->assertSame('18:00', OfficeShift::normalizeTime($storedNew));

        // A row already corrupted by the old bug before this fix shipped
        // must still reopen showing the operator's real original input.
        $legacyRow = '18:00PM';
        $this->assertSame('18:00', OfficeShift::normalizeTime($legacyRow));
    }

    // ---- AttendancesController::buildAttendanceData() / dateTimeForAttendance() ----

    private function callBuildAttendanceData(Employee $employee, array $validated): array
    {
        $controller = new AttendancesController;
        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('buildAttendanceData');
        $method->setAccessible(true);

        return $method->invoke($controller, $employee, $validated);
    }

    private function employeeWithShift(array $shiftAttributes): Employee
    {
        $shift = new OfficeShift(array_merge([
            'id' => 1, 'name' => 'Test shift', 'company_id' => 1,
        ], $shiftAttributes));

        $employee = new Employee(['id' => 1, 'company_id' => 1]);
        $employee->setRelation('office_shift', $shift);

        return $employee;
    }

    public function test_shift_08_to_17_calculates_late_time_on_arrival(): void
    {
        // Case A + G: shift stored in the legacy shape (as every row in this
        // system's history actually is), clock-in 30 minutes late.
        $employee = $this->employeeWithShift([
            'monday_in' => '08:00AM', 'monday_out' => '17:00PM',
        ]);

        $data = $this->callBuildAttendanceData($employee, [
            'company_id' => 1, 'date' => '2026-09-14', // Monday
            'clock_in' => '08:30',
            'clock_out' => '17:00',
        ]);

        $this->assertSame('00:30', $data['late_time']);
        $this->assertSame('00:00', $data['overtime']);
        $this->assertSame('00:00', $data['depart_early']);
    }

    public function test_shift_09_to_18_calculates_overtime_without_crashing(): void
    {
        // Case B + E + H: this exact shift shape (18:00 stored as "18:00PM")
        // used to throw an uncaught Carbon exception before the fix.
        $employee = $this->employeeWithShift([
            'monday_in' => '09:00AM', 'monday_out' => '18:00PM',
        ]);

        $data = $this->callBuildAttendanceData($employee, [
            'company_id' => 1, 'date' => '2026-09-14',
            'clock_in' => '09:00',
            'clock_out' => '18:30',
        ]);

        $this->assertSame('00:30', $data['overtime']);
        $this->assertSame('00:00', $data['late_time']);
        $this->assertSame('09:00', $data['clock_in']);
        $this->assertSame('18:30', $data['clock_out']);
    }

    public function test_shift_stored_in_new_canonical_format_also_works(): void
    {
        // Same as above but the shift was created after this fix, so the
        // columns hold plain "H:i" with no suffix at all.
        $employee = $this->employeeWithShift([
            'monday_in' => '09:00', 'monday_out' => '18:00',
        ]);

        $data = $this->callBuildAttendanceData($employee, [
            'company_id' => 1, 'date' => '2026-09-14',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
        ]);

        $this->assertSame('00:00', $data['late_time']);
        $this->assertSame('00:00', $data['overtime']);
        $this->assertSame('00:00', $data['depart_early']);
        $this->assertSame('09:00', $data['total_work']);
    }

    public function test_overnight_shift_is_supported(): void
    {
        // Case C: entrada > salida on the clock face means an overnight
        // shift; buildAttendanceData already rolls shiftOut to the next day
        // when it is <= shiftIn. Verify that still holds with legacy-shaped
        // stored values and produces sane, non-throwing arithmetic.
        $employee = $this->employeeWithShift([
            'monday_in' => '22:00', 'monday_out' => '06:00',
        ]);

        $data = $this->callBuildAttendanceData($employee, [
            'company_id' => 1, 'date' => '2026-09-14',
            'clock_in' => '22:00',
            'clock_out' => '06:00',
        ]);

        $this->assertSame('00:00', $data['late_time']);
        $this->assertSame('00:00', $data['overtime']);
        $this->assertSame('00:00', $data['depart_early']);
        $this->assertSame('08:00', $data['total_work']);
    }

    public function test_day_with_no_schedule_skips_classification(): void
    {
        // Case F: day off - no in/out configured for this weekday.
        $employee = $this->employeeWithShift([
            'monday_in' => null, 'monday_out' => null,
        ]);

        $data = $this->callBuildAttendanceData($employee, [
            'company_id' => 1, 'date' => '2026-09-14',
            'clock_in' => '08:00',
            'clock_out' => '17:00',
        ]);

        $this->assertSame('00:00', $data['late_time']);
        $this->assertSame('00:00', $data['overtime']);
        $this->assertSame('00:00', $data['depart_early']);
        $this->assertSame('08:00', $data['clock_in']);
        $this->assertSame('17:00', $data['clock_out']);
    }

    public function test_soft_deleted_shift_is_ignored_like_no_shift_assigned(): void
    {
        // Case I: the employee's office_shift_id still points at a row with
        // deleted_at set. Attendance must not apply its hours.
        $employee = $this->employeeWithShift([
            'monday_in' => '08:00AM', 'monday_out' => '17:00PM',
        ]);
        // deleted_at is not mass-assignable on OfficeShift, so it must be set
        // directly to simulate a soft-deleted row (matches how destroy()
        // actually sets it in the real controller).
        $employee->office_shift->deleted_at = Carbon::now();

        $data = $this->callBuildAttendanceData($employee, [
            'company_id' => 1, 'date' => '2026-09-14',
            'clock_in' => '10:00',
            'clock_out' => '15:00',
        ]);

        $this->assertSame('00:00', $data['late_time']);
        $this->assertSame('00:00', $data['overtime']);
        $this->assertSame('00:00', $data['depart_early']);
        $this->assertSame('10:00', $data['clock_in']);
        $this->assertSame('15:00', $data['clock_out']);
    }

    public function test_no_shift_at_all_skips_classification(): void
    {
        $employee = new Employee(['id' => 1, 'company_id' => 1]);
        $employee->setRelation('office_shift', null);

        $data = $this->callBuildAttendanceData($employee, [
            'company_id' => 1, 'date' => '2026-09-14',
            'clock_in' => '08:00',
            'clock_out' => '17:00',
        ]);

        $this->assertSame('00:00', $data['late_time']);
        $this->assertSame('00:00', $data['overtime']);
    }
}
